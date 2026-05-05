<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Outbound fax queue + record. One row per fax (not per attempt).
 *
 * Lifecycle:
 *   waiting → sending → sent | failed
 *           → trying / busy → sending (retry) → ...
 *
 * The DB row is the system of record. SendFaxJob is the worker that
 * performs one attempt; HandleFaxTxEventJob processes the hangup
 * webhook and decides whether to retry, succeed, or fail.
 *
 * Per-attempt history lives in v_fax_logs, related via outbound_fax_uuid.
 */
class OutboundFax extends Model
{
    use HasFactory, \App\Models\Traits\TraitUuid;

    protected $table = 'outbound_faxes';

    protected $primaryKey = 'outbound_fax_uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'domain_uuid',
        'fax_uuid',
        'status',
        'source',
        'source_name',
        'destination',
        'destination_name',
        'email',
        'subject',
        'body',
        'file_path',
        'total_pages',
        'prefix',
        'accountcode',
        'retry_count',
        'retry_limit',
        'retry_at',
        'command',
        'response',
        'call_uuid',
        'current_attempt_uuid',
        'notify_sent_at',
    ];

    protected $casts = [
        'retry_at'       => 'datetime',
        'notify_sent_at' => 'datetime',
        'total_pages'    => 'integer',
        'retry_count'    => 'integer',
        'retry_limit'    => 'integer',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_uuid', 'domain_uuid');
    }

    public function faxServer(): BelongsTo
    {
        return $this->belongsTo(Faxes::class, 'fax_uuid', 'fax_uuid');
    }

    /**
     * All attempt logs for this fax, oldest first.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(FaxLogs::class, 'outbound_fax_uuid', 'outbound_fax_uuid')
            ->orderBy('fax_date');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['sent', 'failed'], true);
    }
}
