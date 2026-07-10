<?php

namespace App\Models;

use App\GraphQL\Models\BankRecord;
use App\Services\OffshoreFulfillmentResult;
use App\Services\PendingRequestsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Transaction extends Model
{
    public const PENDING_WITHDRAWAL_KEY_VALUE = 1;

    public const BANK_ATTEMPT_SENDING = 'sending';

    public const BANK_ATTEMPT_PREPARING = 'preparing';

    public const BANK_ATTEMPT_SUCCEEDED = 'succeeded';

    public const BANK_ATTEMPT_FAILED = 'failed';

    public const BANK_ATTEMPT_NEEDS_RECONCILIATION = 'needs_reconciliation';

    public const BANK_ATTEMPT_RECONCILED_SENT = 'reconciled_sent';

    public const BANK_ATTEMPT_RECONCILED_REFUNDED = 'reconciled_refunded';

    private const BANK_NOTE_MAX_LENGTH = 255;

    public $table = 'transactions';

    protected $casts = [
        'is_pending' => 'bool',
        'requires_admin_approval' => 'bool',
        'pending_withdrawal_key' => 'int',
        'approved_at' => 'datetime',
        'denied_at' => 'datetime',
        'refunded_at' => 'datetime',
        'bank_processing_at' => 'datetime',
        'sent_at' => 'datetime',
        'bank_record_id' => 'int',
        'bank_attempt_count' => 'int',
        'bank_attempted_at' => 'datetime',
        'bank_reconciliation_details' => 'array',
        'offshore_fulfillment_details' => 'array',
        'payroll_run_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (Transaction $transaction): void {
            $transaction->pending_withdrawal_key = $transaction->shouldHoldPendingWithdrawalKey()
                ? self::PENDING_WITHDRAWAL_KEY_VALUE
                : null;
        });
    }

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
        $this->bank_attempt_status = self::BANK_ATTEMPT_SUCCEEDED;

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
        $this->approved_at = null;
        $this->approved_by = null;
        // Preserve any existing reason while appending the new context for admins.
        $this->pending_reason = $this->pending_reason
            ? $this->pending_reason.' | '.$reason
            : $reason;
        $this->save();
    }

    public function ensureBankCorrelationId(): void
    {
        if (blank($this->bank_correlation_id)) {
            $this->bank_correlation_id = 'NXS-WD-'.$this->getKey();
        }
    }

    public function bankNoteWithCorrelation(?string $note): string
    {
        $this->ensureBankCorrelationId();

        $correlationTag = '['.$this->bank_correlation_id.']';
        $baseNote = trim((string) $note);

        if ($baseNote === '') {
            return $correlationTag;
        }

        $baseLimit = self::BANK_NOTE_MAX_LENGTH - mb_strlen($correlationTag) - 1;

        return Str::limit($baseNote, $baseLimit, '').' '.$correlationTag;
    }

    public function beginBankAttempt(): void
    {
        $this->ensureBankCorrelationId();
        $this->bank_attempt_status = self::BANK_ATTEMPT_SENDING;
        $this->bank_attempt_count = (int) $this->bank_attempt_count + 1;
        $this->bank_attempted_at = now();
        $this->save();
    }

    public function beginBankPreparation(): void
    {
        $this->ensureBankCorrelationId();
        $this->bank_attempt_status = self::BANK_ATTEMPT_PREPARING;
        $this->save();
    }

    public function markDefiniteBankFailure(string $reason): void
    {
        $this->bank_attempt_status = self::BANK_ATTEMPT_FAILED;
        $this->markPendingAdminReview($reason);
    }

    public function markBankNeedsReconciliation(string $reason): void
    {
        $this->bank_attempt_status = self::BANK_ATTEMPT_NEEDS_RECONCILIATION;
        $this->requires_admin_approval = true;
        $this->bank_processing_at = null;
        $this->pending_reason = $this->pending_reason
            ? $this->pending_reason.' | '.$reason
            : $reason;
        $this->bank_reconciliation_details = array_merge(
            $this->bank_reconciliation_details ?? [],
            ['detected_at' => now()->toISOString()]
        );
        $this->save();
    }

    public function requiresBankReconciliation(): bool
    {
        return $this->bank_attempt_status === self::BANK_ATTEMPT_NEEDS_RECONCILIATION;
    }

    public function isNationWithdrawal(): bool
    {
        return $this->transaction_type === 'withdrawal' && is_null($this->to_account_id);
    }

    public function shouldHoldPendingWithdrawalKey(): bool
    {
        return (bool) $this->is_pending
            && $this->isNationWithdrawal()
            && ! is_null($this->nation_id);
    }

    public function isRefunded(): bool
    {
        return ! is_null($this->refunded_at);
    }
}
