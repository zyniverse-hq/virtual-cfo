<?php

namespace App\Models;

use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Enums\StatementType;
use App\Services\AggregateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property array<string, mixed>|null $source_metadata
 * @property StatementType $statement_type
 * @property ImportSource $source
 * @property ImportStatus $status
 */
class ImportedFile extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::deleting(function (ImportedFile $file) {
            if ($file->isForceDeleting()) {
                if ($file->file_path && Storage::disk('local')->exists($file->file_path)) {
                    Storage::disk('local')->delete($file->file_path);
                }

                // Collect affected months from non-deleted transactions before cascade fires.
                // Soft-deleted transactions already had their aggregates decremented at soft-delete time.
                $yearMonths = Transaction::where('imported_file_id', $file->id)
                    ->distinct()
                    ->selectRaw("TO_CHAR(date, 'YYYY-MM') AS year_month")
                    ->pluck('year_month');

                // Bulk-delete all transactions (incl. soft-deleted) so DB cascade becomes a no-op.
                Transaction::withTrashed()->where('imported_file_id', $file->id)->forceDelete();

                if ($yearMonths->isNotEmpty()) {
                    $service = app(AggregateService::class);
                    foreach ($yearMonths as $yearMonth) {
                        $service->rebuild($file->company_id, $yearMonth);
                    }
                }
            } else {
                Transaction::where('imported_file_id', $file->id)->each(
                    fn (Transaction $transaction) => $transaction->delete()
                );
            }
        });

        static::restoring(function (ImportedFile $file) {
            Transaction::onlyTrashed()->where('imported_file_id', $file->id)->each(
                fn (Transaction $transaction) => $transaction->restore()
            );
        });
    }

    protected $fillable = [
        'company_id',
        'inbound_email_id',
        'bank_name',
        'account_holder_name',
        'card_variant',
        'account_number',
        'statement_period',
        'opening_balance',
        'statement_type',
        'file_path',
        'original_filename',
        'display_name',
        'file_hash',
        'status',
        'source',
        'source_metadata',
        'message_id',
        'total_rows',
        'mapped_rows',
        'error_message',
        'uploaded_by',
        'processed_at',
        'bank_account_id',
        'credit_card_id',
        'is_matching',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'bank_name',
                'statement_period',
                'statement_type',
                'file_path',
                'original_filename',
                'display_name',
                'file_hash',
                'status',
                'source',
                'total_rows',
                'mapped_rows',
                'error_message',
                'uploaded_by',
                'processed_at',
            ])
            ->logOnlyDirty()
            ->useLogName('imported-files');
    }

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'statement_type' => StatementType::class,
            'source' => ImportSource::class,
            'source_metadata' => 'encrypted:array',
            'account_number' => 'encrypted',
            'processed_at' => 'datetime',
            'total_rows' => 'integer',
            'mapped_rows' => 'integer',
            'is_matching' => 'boolean',
            'opening_balance' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<InboundEmail, $this> */
    public function inboundEmail(): BelongsTo
    {
        return $this->belongsTo(InboundEmail::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return BelongsTo<CreditCard, $this> */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    private const ACTIVE_STATUSES = [ImportStatus::Pending, ImportStatus::Processing];

    /** @param Builder<self> $query */
    public function scopeActivelyProcessing(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->whereIn('status', self::ACTIVE_STATUSES)
                ->orWhere('is_matching', true);
        });
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES)
            || $this->is_matching;
    }

    public function getMappedPercentageAttribute(): float
    {
        if ($this->total_rows === 0) {
            return 0;
        }

        return round(($this->mapped_rows / $this->total_rows) * 100, 1);
    }

    public function getExportAccountTitle(): string
    {
        return $this->statement_type === StatementType::CreditCard ? 'Card:' : 'Account:';
    }

    public function getFullBankOrCardName(): string
    {
        $bankName = $this->bank_name ?? '';

        if ($this->statement_type === StatementType::CreditCard) {
            $this->loadMissing('creditCard');
            
            $baseName = trim((string) ($this->creditCard?->name ?? $bankName));
            $variant = trim((string) $this->card_variant);
            
            if ($variant === '') {
                return $baseName;
            }
            
            if ($baseName === '') {
                return $variant;
            }
            
            if (stripos($variant, $baseName) !== false) {
                return $variant;
            }
            
            return $baseName . ' ' . $variant;
        }

        return $bankName;
    }
}
