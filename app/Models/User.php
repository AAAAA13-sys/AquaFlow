<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * All roles authenticate with a username and password.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public const ROLE_CASHIER = 'cashier';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLES = [self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN, self::ROLE_CASHIER, self::ROLE_REFILLER, self::ROLE_RIDER];

    public const STAFF_ROLES = [self::ROLE_CASHIER, self::ROLE_REFILLER, self::ROLE_RIDER];

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

    /** Operational management shared by owners and managers. */
    public function isAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * Plain-language name for the role, for the account chip in the sidebar.
     *
     * "Owner" and "Station Manager" are what the people at the station call
     * these accounts; the internal role names never reach the screen.
     */
    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_SUPER_ADMIN => 'Owner',
            self::ROLE_ADMIN => 'Station Manager',
            self::ROLE_CASHIER => 'Cashier',
            self::ROLE_RIDER => 'Delivery Rider',
            self::ROLE_REFILLER => 'Refiller',
            default => ucfirst((string) $this->role),
        };
    }

    /** A privileged approval always requires a different, strictly higher rank. */
    public function canApproveActor(self $actor): bool
    {
        return $this->id !== $actor->id && $this->isAdmin()
            && ($this->isSuperAdmin() ? ! $actor->isSuperAdmin() : in_array($actor->role, self::STAFF_ROLES, true));
    }

    public function canManageUser(self $user): bool
    {
        return ! $user->is_bootstrap && ($this->isSuperAdmin() || ($this->isAdmin() && in_array($user->role, self::STAFF_ROLES, true)));
    }

    public function homeUrl(): string
    {
        return $this->isAdmin() ? '/admin' : ($this->isEmployee() ? '/employee' : '/cashier');
    }

    public function canHandleCash(): bool
    {
        return in_array($this->role, [self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN, self::ROLE_CASHIER], true);
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

    /** Defect and damaged-stock reports this account filed. */
    public function defectReports(): HasMany
    {
        return $this->hasMany(DefectReport::class, 'reported_by');
    }
}
