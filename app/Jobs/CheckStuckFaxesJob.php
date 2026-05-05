<?php

namespace App\Jobs;

use App\Models\OutboundFax;
use App\Services\FreeswitchEslService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Catches outbound faxes that got stuck in a non-terminal state because the
 * normal event-driven path failed (Lua hook never ran, webhook was lost,
 * Redis ate a delayed job, server died mid-send, etc.).
 *
 * Runs every 5 minutes on each server. Both servers run independently — the
 * atomic state transitions deduplicate any overlap.
 *
 * Phases:
 *   A — status='sending', call_uuid set, retry_at older than 5 min:
 *         ask local FS via uuid_exists. If FS says the call is gone, redispatch
 *         immediately. If the call is still alive, leave the row alone (could
 *         be a 500-page fax legitimately in transit).
 *
 *   B — status='sending', call_uuid IS NULL (originate failed before saving)
 *         and retry_at older than 5 min: revert to 'trying' and redispatch.
 *
 *   C — pages-aware hard timeout (safety net). 90 s/page budget with a 15-min
 *         floor. When we don't know the page count, fall back to a flat 6 h.
 *         When this fires, the call has been in 'sending' way past any
 *         legitimate transmission time — mark as failed.
 *
 *   D — status in waiting/trying/busy and retry_at past the retry interval +
 *         a small buffer: the dispatch was lost (Redis flush, primary died).
 *         Redispatch SendFaxJob; the job's atomic claim deduplicates if
 *         another worker also picks it up.
 */
class CheckStuckFaxesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 120;

    /** Don't probe ESL on faxes younger than this — they're probably fine. */
    private const SENDING_GRACE_SECONDS = 300; // 5 min

    /** Per-page budget for Phase C. 90 s/page is conservative (real ~30 s). */
    private const SECONDS_PER_PAGE = 90;

    /** Phase C floor — even a 1-pager waits at least this long. */
    private const PHASE_C_MIN_SECONDS = 900; // 15 min

    /** Phase C fallback when total_pages is unknown. */
    private const PHASE_C_UNKNOWN_PAGES_FALLBACK = 21600; // 6 h

    /** Phase D — minimum retry interval (matches HandleFaxTxEventJob base) plus buffer. */
    private const PHASE_D_GRACE_SECONDS = 300; // 5 min buffer past base retry interval

    public function __construct()
    {
        $this->onQueue('faxes');
    }

    public function handle(FreeswitchEslService $esl): void
    {
        fax_webhook_debug('CheckStuckFaxesJob start');

        $this->phaseA($esl);
        $this->phaseB();
        $this->phaseC();
        $this->phaseD();

        fax_webhook_debug('CheckStuckFaxesJob done');
    }

    /**
     * status='sending', call_uuid present — ask FS whether the call is alive.
     */
    private function phaseA(FreeswitchEslService $esl): void
    {
        $rows = OutboundFax::where('status', 'sending')
            ->whereNotNull('call_uuid')
            ->where('retry_at', '<', now()->subSeconds(self::SENDING_GRACE_SECONDS))
            ->limit(50)
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        try {
            if (!$esl->isConnected()) {
                $esl->reconnect();
            }
        } catch (Throwable $e) {
            // ESL unavailable — Phase C will eventually catch any genuinely-stuck rows.
            fax_webhook_debug('CheckStuckFaxesJob phaseA: ESL unreachable, deferring liveness checks', [
                'error' => $e->getMessage(),
            ]);
            return;
        }

        foreach ($rows as $fax) {
            try {
                $reply = (string) $esl->executeCommand('uuid_exists ' . $fax->call_uuid, false);
                $reply = trim(strtolower($reply));
            } catch (Throwable $e) {
                fax_webhook_debug('CheckStuckFaxesJob phaseA: uuid_exists failed', [
                    'outbound_fax_uuid' => $fax->outbound_fax_uuid,
                    'call_uuid'         => $fax->call_uuid,
                    'error'             => $e->getMessage(),
                ]);
                continue;
            }

            // 'true' or 'false' typically; some configurations return raw bools.
            if (str_contains($reply, 'false') || $reply === '0') {
                fax_webhook_debug('CheckStuckFaxesJob phaseA: call gone, redispatching', [
                    'outbound_fax_uuid' => $fax->outbound_fax_uuid,
                    'call_uuid'         => $fax->call_uuid,
                ]);

                $reset = OutboundFax::where('outbound_fax_uuid', $fax->outbound_fax_uuid)
                    ->where('status', 'sending')
                    ->update(['status' => 'trying']);

                if ($reset > 0) {
                    SendFaxJob::dispatch($fax->outbound_fax_uuid);
                }
            }
            // else: call still alive in FS — leave the row alone.
        }

        $esl->disconnect();
    }

    /**
     * status='sending' but no call_uuid — originate didn't even get accepted
     * (or the worker died before persisting). Revert and redispatch.
     */
    private function phaseB(): void
    {
        $rows = OutboundFax::where('status', 'sending')
            ->whereNull('call_uuid')
            ->where('retry_at', '<', now()->subSeconds(self::SENDING_GRACE_SECONDS))
            ->limit(50)
            ->get(['outbound_fax_uuid']);

        foreach ($rows as $fax) {
            $reset = OutboundFax::where('outbound_fax_uuid', $fax->outbound_fax_uuid)
                ->where('status', 'sending')
                ->whereNull('call_uuid')
                ->update(['status' => 'trying']);

            if ($reset > 0) {
                fax_webhook_debug('CheckStuckFaxesJob phaseB: redispatching (no call_uuid)', [
                    'outbound_fax_uuid' => $fax->outbound_fax_uuid,
                ]);
                SendFaxJob::dispatch($fax->outbound_fax_uuid);
            }
        }
    }

    /**
     * Pages-aware hard timeout. If a row has been 'sending' longer than its
     * per-page budget, mark it failed — a real fax transmission would have
     * completed by now.
     */
    private function phaseC(): void
    {
        // Pull candidates older than the absolute floor; budget check is
        // per-row in PHP since the threshold depends on total_pages.
        $rows = OutboundFax::where('status', 'sending')
            ->where('retry_at', '<', now()->subSeconds(self::PHASE_C_MIN_SECONDS))
            ->limit(100)
            ->get(['outbound_fax_uuid', 'total_pages', 'retry_at']);

        foreach ($rows as $fax) {
            $budget = $fax->total_pages
                ? max(self::PHASE_C_MIN_SECONDS, $fax->total_pages * self::SECONDS_PER_PAGE)
                : self::PHASE_C_UNKNOWN_PAGES_FALLBACK;

            $sendingFor = now()->diffInSeconds($fax->retry_at);
            if ($sendingFor < $budget) {
                continue;
            }

            $marked = OutboundFax::where('outbound_fax_uuid', $fax->outbound_fax_uuid)
                ->where('status', 'sending')
                ->update([
                    'status'   => 'failed',
                    'response' => 'CheckStuckFaxesJob: exceeded per-page budget without webhook',
                ]);

            if ($marked > 0) {
                fax_webhook_debug('CheckStuckFaxesJob phaseC: hard-timeout, marking failed', [
                    'outbound_fax_uuid' => $fax->outbound_fax_uuid,
                    'sending_for_secs'  => $sendingFor,
                    'budget_secs'       => $budget,
                    'total_pages'       => $fax->total_pages,
                ]);
                SendFaxNotificationJob::dispatch($fax->outbound_fax_uuid);
            }
        }
    }

    /**
     * Lost dispatches — waiting/trying/busy rows whose retry window passed
     * without a SendFaxJob picking them up. Caused by Redis flush, primary
     * dying with delayed jobs in its queue, etc.
     */
    private function phaseD(): void
    {
        $rows = OutboundFax::whereIn('status', ['waiting', 'trying', 'busy'])
            ->where(function ($q) {
                $q->whereNull('retry_at')
                  ->orWhere('retry_at', '<', now()->subSeconds(self::PHASE_D_GRACE_SECONDS));
            })
            ->limit(100)
            ->get(['outbound_fax_uuid']);

        foreach ($rows as $fax) {
            fax_webhook_debug('CheckStuckFaxesJob phaseD: redispatching pending row', [
                'outbound_fax_uuid' => $fax->outbound_fax_uuid,
            ]);
            SendFaxJob::dispatch($fax->outbound_fax_uuid);
        }
    }
}
