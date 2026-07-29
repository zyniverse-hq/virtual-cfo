<?php

namespace App\Models;

use App\Enums\MappingType;
use App\Enums\ReconciliationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property ReconciliationStatus $reconciliation_status
 * @property MappingType $mapping_type
 */
class Transaction extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'imported_file_id',
        'date',
        'description',
        'reference_number',
        'debit',
        'credit',
        'balance',
        'currency',
        'account_head_id',
        'mapping_type',
        'ai_confidence',
        'raw_data',
        'bank_format',
        'reconciliation_status',
        'recurring_pattern_id',
        'duplicate_of_id',
        'is_synthetic',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'imported_file_id',
                'date',
                'reference_number',
                'account_head_id',
                'mapping_type',
                'ai_confidence',
                'bank_format',
                'reconciliation_status',
            ])
            ->logOnlyDirty()
            ->useLogName('transactions');
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'description' => 'encrypted',
            'debit' => 'encrypted',
            'credit' => 'encrypted',
            'balance' => 'encrypted',
            'raw_data' => 'encrypted:array',
            'mapping_type' => MappingType::class,
            'ai_confidence' => 'float',
            'reconciliation_status' => ReconciliationStatus::class,
            'is_synthetic' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<ImportedFile, $this> */
    public function importedFile(): BelongsTo
    {
        return $this->belongsTo(ImportedFile::class);
    }

    public function accountHead(): BelongsTo
    {
        return $this->belongsTo(AccountHead::class);
    }

    /** @return BelongsTo<RecurringPattern, $this> */
    public function recurringPattern(): BelongsTo
    {
        return $this->belongsTo(RecurringPattern::class);
    }

    /** @return HasMany<ReconciliationMatch, $this> */
    public function reconciliationMatchesAsBank(): HasMany
    {
        return $this->hasMany(ReconciliationMatch::class, 'bank_transaction_id');
    }

    /** @return HasMany<ReconciliationMatch, $this> */
    public function reconciliationMatchesAsInvoice(): HasMany
    {
        return $this->hasMany(ReconciliationMatch::class, 'invoice_transaction_id');
    }

    public function scopeUnmapped(Builder $query): Builder
    {
        return $query->where('mapping_type', MappingType::Unmapped);
    }

    public function scopeMapped(Builder $query): Builder
    {
        return $query->where('mapping_type', '!=', MappingType::Unmapped);
    }

    public function scopeNeedsReview(Builder $query): Builder
    {
        return $query->where('mapping_type', MappingType::Ai)
            ->where('ai_confidence', '<', 0.8);
    }

    public function scopeUnreconciled(Builder $query): Builder
    {
        return $query->where('reconciliation_status', ReconciliationStatus::Unreconciled);
    }

    public function scopeFlagged(Builder $query): Builder
    {
        return $query->where('reconciliation_status', ReconciliationStatus::Flagged);
    }

    /**
     * Transactions eligible for automated matching.
     *
     * Flagged is included because it is only ever set by the reconciliation
     * engine to mean "tried and failed", never by a user decision — so those
     * rows must be re-examined when new counterparties arrive.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeMatchable(Builder $query): void
    {
        $query->whereIn('reconciliation_status', [
            ReconciliationStatus::Unreconciled,
            ReconciliationStatus::Flagged,
        ]);
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeMatched(Builder $query): Builder
    {
        return $query->where('reconciliation_status', ReconciliationStatus::Matched);
    }

    public function getDecryptedDebitAttribute(): ?float
    {
        return $this->debit !== null ? (float) $this->debit : null;
    }

    public function getDecryptedCreditAttribute(): ?float
    {
        return $this->credit !== null ? (float) $this->credit : null;
    }

    public function getDecryptedBalanceAttribute(): ?float
    {
        return $this->balance !== null ? (float) $this->balance : null;
    }

    /**
     * Get the transaction amount (debit or credit) as a float.
     * For bank transactions, debit means money going out (payment).
     * For invoices, the debit field stores the invoice total.
     */
    public function getAmountAttribute(): ?float
    {
        if ($this->debit !== null) {
            return (float) $this->debit;
        }

        if ($this->credit !== null) {
            return (float) $this->credit;
        }

        return null;
    }

    /** @return BelongsTo<self, $this> */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    /** @return HasMany<DuplicateFlag, $this> */
    public function duplicateFlags(): HasMany
    {
        return $this->hasMany(DuplicateFlag::class, 'transaction_id');
    }

    public function moveToCompany(Company $target): void
    {
        $this->update([
            'company_id' => $target->id,
            'account_head_id' => null,
            'mapping_type' => MappingType::Unmapped,
            'ai_confidence' => null,
        ]);
    }
}
