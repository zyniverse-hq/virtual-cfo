<?php

namespace App\Models;

use App\Enums\UserRole;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * @property UserRole|null $role
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasDefaultTenant, HasTenants
{
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'toured_pages',
        'dismissed_suggestions',
        'last_used_company_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'toured_pages' => 'array',
            'dismissed_suggestions' => 'array',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role !== null;
    }

    /** @return BelongsToMany<Company, $this> */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withPivot('role')->withTimestamps();
    }

    /** @return Collection<int, Company> */
    public function getTenants(Panel $panel): Collection
    {
        return $this->companies;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->companies()->whereKey($tenant)->exists();
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        $companies = $this->companies;

        if ($this->last_used_company_id) {
            $lastUsed = $companies->firstWhere('id', $this->last_used_company_id);

            if ($lastUsed) {
                return $lastUsed;
            }
        }

        return $companies->first();
    }

    /** @var array<int, UserRole|null> */
    protected array $roleCache = [];

    public function roleForCompany(Company $company): ?UserRole
    {
        if (array_key_exists($company->id, $this->roleCache)) {
            return $this->roleCache[$company->id];
        }

        /** @var string|null $role */
        $role = $this->companies()
            ->where('company_id', $company->id)
            ->value('company_user.role');

        return $this->roleCache[$company->id] = $role ? UserRole::tryFrom($role) : null;
    }

    public function currentRole(): ?UserRole
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return null;
        }

        return $this->roleForCompany($tenant);
    }

    /** @return HasMany<LoginSession, $this> */
    public function loginSessions(): HasMany
    {
        return $this->hasMany(LoginSession::class);
    }

    public function importedFiles(): HasMany
    {
        return $this->hasMany(ImportedFile::class, 'uploaded_by');
    }

    public function headMappings(): HasMany
    {
        return $this->hasMany(HeadMapping::class, 'created_by');
    }
}
