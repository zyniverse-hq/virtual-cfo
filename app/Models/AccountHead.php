<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
