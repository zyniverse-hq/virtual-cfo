<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AccountHead extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'parent_id',
        'tally_guid',
        'group_name',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::deleting(function (AccountHead $head) {
            if ($head->getLinkedRecordsCount() > 0) {
                throw ValidationException::withMessages([
                    'base' => $head->getDeletionErrorMessage(),
                ]);
            }
        });
    }

    public function getLinkedRecordsCount(): int
    {
        return $this->transactions()->count() + $this->headMappings()->count();
    }

    public function getDeletionErrorMessage(): string
    {
        $transactionsCount = $this->transactions()->count();
        $rulesCount = $this->headMappings()->count();

        $parts = [];
        if ($transactionsCount > 0) {
            $parts[] = $transactionsCount === 1 ? '1 transaction' : "{$transactionsCount} transactions";
        }
        if ($rulesCount > 0) {
            $parts[] = $rulesCount === 1 ? '1 rule' : "{$rulesCount} rules";
        }

        $label = implode(' and ', $parts);
        $totalCount = $transactionsCount + $rulesCount;
        $verb = $totalCount === 1 ? 'is' : 'are';
        $pronoun = $totalCount === 1 ? 'it' : 'them';

        return "Cannot delete — {$label} {$verb} mapped to this head. Reassign {$pronoun} first.";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'parent_id', 'tally_guid', 'group_name', 'is_active'])
            ->logOnlyDirty()
            ->useLogName('account-heads');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(AccountHead::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(AccountHead::class, 'parent_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function headMappings(): HasMany
    {
        return $this->hasMany(HeadMapping::class);
    }

    private ?string $cachedFullPath = null;

    public function getFullPathAttribute(): string
    {
        return $this->cachedFullPath ??= $this->computeFullPath();
    }

    private function computeFullPath(): string
    {
        $parts = [$this->name];
        $current = $this;

        while ($current->parent) {
            $current = $current->parent;
            array_unshift($parts, $current->name);
        }

        return implode(' > ', $parts);
    }
}
