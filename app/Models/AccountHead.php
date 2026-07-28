<?php

namespace App\Models;

use App\Services\AggregateService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property string $name
 */
class AccountHead extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::deleting(function (AccountHead $head) {
            if (! $head->isForceDeleting()) {
                $yearMonths = Transaction::where('account_head_id', $head->id)
                    ->distinct()
                    ->selectRaw("TO_CHAR(date, 'YYYY-MM') AS year_month")
                    ->pluck('year_month');

                if ($yearMonths->isEmpty()) {
                    return;
                }

                Transaction::withoutEvents(function () use ($head) {
                    $head->transactions()->update(['account_head_id' => null]);
                });

                $service = app(AggregateService::class);
                foreach ($yearMonths as $yearMonth) {
                    $service->rebuild($head->company_id, $yearMonth);
                }
            }
        });
    }

    protected $fillable = [
        'company_id',
        'name',
        'parent_id',
        'tally_guid',
        'group_name',
        'is_active',
    ];

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

    /** @return Attribute<string, string> */
    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => (string) preg_replace('/\s+/', ' ', trim($value)),
        );
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
