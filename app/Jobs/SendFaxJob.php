<?php

namespace App\Jobs;

use App\Models\DefaultSettings;
use App\Models\OutboundFax;
use App\Services\FreeswitchEslService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Performs ONE attempt to originate an outbound fax via FreeSWITCH.
 *
 * Lifecycle:
 *   1. Atomic-claim the row (status waiting/trying/busy → sending). If the
 *      claim returns 0 rows, another worker already took it; exit silently.
 *   2. Build the dial string with retry-attempt-specific T38/ECM/V17 options
 *      (mirrors the legacy fax_send.php retry ladder).
 *   3. bgapi originate via FreeswitchEslService.
 *   4. Save call_uuid + command + response back to the row.
 *   5. Job exits — does NOT wait for the call result. The Lua hangup hook
 *      fires a webhook to HandleFaxTxEventJob which decides retry/sent/failed.
 *
 * If the originate command itself is rejected, we revert to 'trying' and
 * let HandleFaxTxEventJob (via webhook) or CheckStuckFaxesJob retry.
 */
class SendFaxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = 10;

    public function __construct(public string $outboundFaxUuid)
    {
        $this->onQueue('faxes');
    }

    public function handle(FreeswitchEslService $esl): void
    {
        Redis::throttle('fax')->allow(2)->every(1)->then(function () use ($esl) {
            fax_webhook_debug('SendFaxJob started', [
                'outbound_fax_uuid' => $this->outboundFaxUuid,
                'attempt'           => $this->attempts(),
            ]);

            $fax = OutboundFax::with('faxServer.domain')->find($this->outboundFaxUuid);

            if (!$fax) {
                fax_webhook_debug('SendFaxJob: row not found, dropping', [
                    'outbound_fax_uuid' => $this->outboundFaxUuid,
                ]);
                return;
            }

            // Stop if we've already burned the retry budget.
            if ($fax->retry_count >= $fax->retry_limit) {
                fax_webhook_debug('SendFaxJob: retry limit reached, marking failed', [
                    'outbound_fax_uuid' => $fax->outbound_fax_uuid,
                    'retry_count'       => $fax->retry_count,
                    'retry_limit'       => $fax->retry_limit,
                ]);

                OutboundFax::where('outbound_fax_uuid', $fax->outbound_fax_uuid)
                    ->whereIn('status', ['waiting', 'trying', 'busy'])
                    ->update(['status' => 'failed']);

                SendFaxNotificationJob::dispatch($fax->outbound_fax_uuid);
                return;
            }

            // Atomic claim: only one worker can transition any pending state to
            // 'sending'. Defends against duplicate dispatches across servers.
            $attemptUuid = (string) Str::uuid();

            $claimed = OutboundFax::where('outbound_fax_uuid', $fax->outbound_fax_uuid)
                ->whereIn('status', ['waiting', 'trying', 'busy'])
                ->update([
                    'status'               => 'sending',
                    'retry_at'             => now(),
                    'retry_count'          => DB::raw('retry_count + 1'),
                    'current_attempt_uuid' => $attemptUuid,
                    'call_uuid'            => null, // populated below once originate is accepted
                ]);

            if ($claimed === 0) {
                fax_webhook_debug('SendFaxJob: claim lost (already taken or terminal)', [
                    'outbound_fax_uuid' => $fax->outbound_fax_uuid,
                    'status'            => $fax->status,
                ]);
                return;
            }

            // Reload after claim so we have the bumped retry_count.
            $fax->refresh();

            $callUuid    = (string) Str::uuid();
            $dialString  = $this->buildDialString($fax, $callUuid, $attemptUuid);

            if ($dialString === null) {
                // No outbound route — short-circuit to 'failed' immediately.
                logger('SendFaxJob: no outbound route for fax', [
                    'outbound_fax_uuid' => $fax->outbound_fax_uuid,
                    'destination'       => $fax->destination,
                ]);

                OutboundFax::where('outbound_fax_uuid', $fax->outbound_fax_uuid)
                    ->update([
                        'status'   => 'failed',
                        'response' => 'no outbound route',
                    ]);

                SendFaxNotificationJob::dispatch($fax->outbound_fax_uuid);
                return;
            }

            $command = 'bgapi originate ' . $dialString;

            fax_webhook_debug('SendFaxJob originate prepared', [
                'outbound_fax_uuid' => $fax->outbound_fax_uuid,
                'attempt_uuid'      => $attemptUuid,
                'call_uuid'         => $callUuid,
                'retry_count'       => $fax->retry_count,
            ]);

            try {
                if (!$esl->isConnected()) {
                    $esl->reconnect();
                }

                $response = (string) $esl->executeCommand($command);

                fax_webhook_debug('SendFaxJob originate response', [
                    'outbound_fax_uuid' => $fax->outbound_fax_uuid,
                    'response'          => $response,
                ]);

                $accepted = stripos($response, '+OK') !== false;

                OutboundFax::where('outbound_fax_uuid', $fax->outbound_fax_uuid)
                    ->update([
                        'command'   => $command,
                        'response'  => $response,
                        'call_uuid' => $accepted ? $callUuid : null,
                        'status'    => $accepted ? 'sending' : 'trying',
                    ]);

                if (!$accepted) {
                    fax_webhook_debug('SendFaxJob: originate rejected, will retry', [
                        'outbound_fax_uuid' => $fax->outbound_fax_uuid,
                        'response'          => $response,
                    ]);
                }
            } catch (Throwable $e) {
                logger('SendFaxJob ESL error: ' . $e->getMessage());

                // Revert to 'trying' so the reaper / next dispatch can retry.
                OutboundFax::where('outbound_fax_uuid', $fax->outbound_fax_uuid)
                    ->where('status', 'sending')
                    ->update([
                        'status'   => 'trying',
                        'response' => 'ESL error: ' . $e->getMessage(),
                    ]);

                throw $e; // surface to Horizon so the job retries via $tries
            }
        }, function () {
            // Could not obtain redis throttle lock; release for another worker.
            $this->release(5);
        });
    }

    /**
     * Build the FreeSWITCH originate dial string for this attempt. Mirrors the
     * variables the legacy fax_send.php / FaxSendService used, plus per-attempt
     * fallback options (T38/ECM/V17 ladder) and the new channel vars the Lua
     * hook needs to identify this attempt.
     *
     * Returns null when no outbound route can be resolved.
     */
    private function buildDialString(OutboundFax $fax, string $callUuid, string $attemptUuid): ?string
    {
        $faxServer  = $fax->faxServer;
        $domainName = $faxServer?->domain?->domain_name ?? '';
        $tollAllow  = $faxServer?->fax_toll_allow ?? '';
        $faxEmail   = $faxServer?->fax_email ?? '';

        $channelVariables = [];
        if (!empty($tollAllow)) {
            $channelVariables['toll_allow'] = $tollAllow;
        }

        $route = outbound_route_to_bridge(
            $fax->domain_uuid,
            ($fax->prefix ?? '') . $fax->destination,
            $channelVariables
        );

        if (empty($route)) {
            return null;
        }
        $faxUri = $route[0];

        $e = fn($val) => str_replace(["'", '{', '}'], ["\\'", '', ''], (string) $val);

        $vars = [
            "origination_uuid={$e($callUuid)}",
            "fax_uuid={$e($fax->fax_uuid)}",
            "outbound_fax_uuid={$e($fax->outbound_fax_uuid)}",
            "outbound_fax_attempt_uuid={$e($attemptUuid)}",
            "accountcode='{$e($fax->accountcode)}'",
            "sip_h_X-customacc='{$e($fax->accountcode)}'",
            "execute_on_answer='sched_hangup +14400'",
            "call_direction='outbound'",
            "domain_uuid={$e($fax->domain_uuid)}",
            "domain_name={$e($domainName)}",
            "origination_caller_id_name='{$e($fax->source_name)}'",
            "origination_caller_id_number='{$e($fax->source)}'",
            "fax_ident='{$e($fax->source)}'",
            "fax_header='{$e($fax->source_name)}'",
            "fax_file='{$e($fax->file_path)}'",
            "hangup_after_bridge=true",
            "continue_on_fail=true",
            "media_mix_inbound_outbound_codecs='true'",
            "sip_renegotiate-codec-on-reinvite='true'",
            "absolute_codec_string='PCMU,PCMA'",
            "caller_destination={$e($fax->destination)}",
        ];

        // Verbose fax logging only when admin has enabled FAX_WEBHOOK_DEBUG —
        // keeps FreeSWITCH logs quiet in production.
        if (config('fax.webhook_debug')) {
            $vars[] = 'fax_verbose=true';
        }

        // Per-attempt fallback ladder, mirrors legacy fax_send.php behavior.
        // Earlier retries try defaults; later retries flip T38/ECM/V17 combos
        // to coax a connection out of finicky receivers.
        foreach ($this->retryLadderOptions((int) $fax->retry_count) as $opt) {
            $vars[] = $opt;
        }

        // Domain-configured dial-plan variables (fax.variable settings).
        foreach ($this->domainDialplanVariables() as $extra) {
            $vars[] = $extra;
        }

        $vars = array_merge($vars, [
            "mailto_address='{$e($faxEmail)}'",
            "mailfrom_address='{$e($fax->email)}'",
            "fax_uri={$e($faxUri)}",
            "fax_retry_attempts={$fax->retry_count}",
            "fax_retry_limit={$fax->retry_limit}",
            "api_hangup_hook='lua lua/fax_hangup.lua'",
        ]);

        return '{' . implode(',', $vars) . '}' . $faxUri . " &txfax('{$e($fax->file_path)}')";
    }

    /**
     * T38/ECM/V17 fallback ladder per retry attempt. retry_count is the
     * number of THIS attempt (1 for first try, 2 for first retry, ...).
     * Each rung flips a different combination to coax a connection out of
     * finicky receivers.
     */
    private function retryLadderOptions(int $retryCount): array
    {
        return match ($retryCount) {
            2       => ['fax_use_ecm=false', 'fax_enable_t38=true',  'fax_enable_t38_request=true'],
            3       => ['fax_use_ecm=true',  'fax_enable_t38=true',  'fax_enable_t38_request=true',  'fax_disable_v17=false'],
            4       => ['fax_use_ecm=true',  'fax_enable_t38=false', 'fax_enable_t38_request=false', 'fax_disable_v17=false'],
            5       => ['fax_use_ecm=false', 'fax_enable_t38=false', 'fax_enable_t38_request=false', 'fax_disable_v17=true'],
            default => [], // attempt 1: leave defaults alone
        };
    }

    /**
     * Domain-configured channel vars from fax.variable settings. Cached for
     * a minute to avoid hammering DefaultSettings on every send.
     */
    private function domainDialplanVariables(): array
    {
        return cache()->remember('fax_dialplan_variables', 60, function () {
            return DefaultSettings::where('default_setting_category', 'fax')
                ->where('default_setting_subcategory', 'variable')
                ->where('default_setting_enabled', 'true')
                ->pluck('default_setting_value')
                ->all();
        });
    }
}
