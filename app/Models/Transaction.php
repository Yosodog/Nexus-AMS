<?php

namespace App\Models;

use App\GraphQL\Models\BankRecord;
use App\Services\OffshoreFulfillmentResult;
use App\Services\PendingRequestsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    public $table = 'transactions';

    protected $casts = [
        'is_pending' => 'bool',
        'requires_admin_approval' => 'bool',
        'approved_at' => 'datetime',
        'denied_at' => 'datetime',
        'refunded_at' => 'datetime',
        'bank_processing_at' => 'datetime',
        'sent_at' => 'datetime',
        'bank_record_id' => 'int',
        'offshore_fulfillment_details' => 'array',
        'payroll_run_date' => 'date',
    ];

    /**
     * @return BelongsTo
     */
    public function toAccount()
    {
        return $this->belongsTo(Account::class, 'to_account_id', 'id');
    }

    /**
     * @return BelongsTo
     */
    public function fromAccount()
    {
        return $this->belongsTo(Account::class, 'from_account_id', 'id');
    }

    /**
     * @return BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nation::class, 'nation_id', 'id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function deniedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'denied_by');
    }

    public function payrollGrade(): BelongsTo
    {
        return $this->belongsTo(PayrollGrade::class, 'payroll_grade_id');
    }

    public function payrollMember(): BelongsTo
    {
        return $this->belongsTo(PayrollMember::class, 'payroll_member_id');
    }

    public function setSent(?BankRecord $bankRecord = null): void
    {
        $this->is_pending = false;
        $this->sent_at = now();
        $this->bank_processing_at = null;

        if ($bankRecord) {
            $this->bank_record_id = $bankRecord->id;
        }

        $this->save();

        app(PendingRequestsService::class)->flushCache();
    }

    public function recordOffshoreFulfillment(OffshoreFulfillmentResult $result): void
    {
        // Persist a snapshot of the fulfillment attempt so reviewers can audit it later.
        $this->offshore_fulfillment_status = $result->status;
        $this->offshore_fulfillment_message = $result->message;
        $this->offshore_fulfillment_details = $result->toArray();
        $this->save();
    }

    public function markPendingAdminReview(string $reason): void
    {
        $this->requires_admin_approval = true;
        $this->bank_processing_at = null;
        // Preserve any existing reason while appending the new context for admins.
        $this->pending_reason = $this->pending_reason
            ? $this->pending_reason.' | '.$reason
            : $reason;
        $this->save();
    }

    public function isNationWithdrawal(): bool
    {
        return $this->transaction_type === 'withdrawal' && is_null($this->to_account_id);
    }

    public function isRefunded(): bool
    {
        return ! is_null($this->refunded_at);
    }
}
