<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Staff authenticate by username; admins use email, password and email OTP.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public const ROLE_CASHIER = 'cashier';

    public const ROLE_ADMIN = 'admin';

    /** Operational staff who work a station floor rather than a till. */
    public const ROLE_REFILLER = 'refiller';

    public const ROLE_RIDER = 'rider';

    protected $fillable = [
        'is_bootstrap',
        'bootstrap_secret_hash',
        'email',
        'employee_id',
        'username',
        'password',
        'must_change_password',
        'role',
        'name',
        'job_title',
        'is_active',
    ];

    protected $hidden = [
        'bootstrap_secret_hash',
        'password',
    ];

    protected function casts(): array
    {
        return [
            'is_bootstrap' => 'boolean',
            'is_active' => 'boolean',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function canHandleCash(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_CASHIER], true);
    }

    /** The employee record a rider or refiller is attached to, when one is. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Staff who work the station floor rather than a till or a dashboard.
     *
     * Riders and refillers sign in to the employee portal, never to the POS or
     * the owner portal. The role is what routes them; EnsureRoleAccess then
     * confines them to their own work surface and checks the linked employee
     * record is still active.
     */
    public function isEmployee(): bool
    {
        return in_array($this->role, [self::ROLE_REFILLER, self::ROLE_RIDER], true);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'cashier_id');
    }
}
