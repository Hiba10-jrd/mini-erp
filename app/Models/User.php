<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserAccountStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_user'
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'account_status' => UserAccountStatus::class,
        ];
    }

    public function isActive(): bool
    {
        return $this->account_status === UserAccountStatus::Active;
    }

    public function isDisabled(): bool
    {
        return $this->account_status === UserAccountStatus::Disabled;
    }

    public function isArchived(): bool
    {
        return $this->account_status === UserAccountStatus::Archived;
    }

    /**
     * Determine whether the user is a Super Administrator.
     */
    public function isSuperAdministrator(): bool
    {
        return $this->roles()
            ->where('slug', 'super-admin')
            ->exists();
    }

    /**
     * Determine whether the user has a given permission.
     */
    public function hasPermission(string $permission): bool
    {
        // Refuse permissions that are not defined in the ERP.
        if (! in_array(
            $permission,
            config('erp.permissions', []),
            true
        )) {
            return false;
        }

        // The Super Administrator has access to all
        // registered ERP permissions.
        if ($this->isSuperAdministrator()) {
            return true;
        }

        // Check permissions assigned to the user's roles.
        return $this->roles()
            ->whereHas('permissions', function ($query) use ($permission) {
                $query->where('name', $permission);
            })
            ->exists();
    }
}
