<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\ImportStatus;
use App\Jobs\ProcessImportedFile;
use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::updated(function (BankAccount $account) {
            if ($account->wasChanged('pdf_password') && $account->pdf_password) {
                $account->importedFiles()
                    ->where('status', ImportStatus::NeedsPassword)
                    ->each(fn (ImportedFile $file) => ProcessImportedFile::dispatch($file));
            }
        });
    }

    protected $fillable = [
        'company_id',
        'name',
        'account_number',
        'ifsc_code',
        'branch',
        'account_type',
        'pdf_password',
        'is_active',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['company_id', 'name', 'ifsc_code', 'branch', 'account_type', 'is_active'])
            ->logOnlyDirty()
            ->useLogName('bank-accounts');
    }

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'pdf_password' => 'encrypted',
            'account_type' => AccountType::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return Attribute<string|null, never> */
    protected function maskedAccountNumber(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (! $this->account_number) {
                    return null;
                }

                $number = $this->account_number;

                return str_repeat('•', max(0, strlen($number) - 4)).substr($number, -4);
            },
        );
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<ImportedFile, $this> */
    public function importedFiles(): HasMany
    {
        return $this->hasMany(ImportedFile::class);
    }

    /**
     * @param  Builder<BankAccount>  $query
     * @return Builder<BankAccount>
     */
    public function scopeVisibleToCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
