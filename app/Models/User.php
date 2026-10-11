<?php

namespace App\Models;

use Database\Factories\UserFactory;
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

    protected $fillable = [
        'is_bootstrap',
        'email',
        'email_verified_at',
        'recovery_codes',
        'employee_id',
        'username',
        'password',
        'role',
        'name',
        'pin',
        'is_active',
    ];

    protected $hidden = [
        'recovery_codes',
        'password',
        'pin',
    ];

    protected function casts(): array
    {
        return [
            'is_bootstrap' => 'boolean',
            'email_verified_at' => 'datetime',
            'recovery_codes' => 'array',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isEmployee(): bool
    {
        return in_array($this->role, ['rider', 'refiller'], true);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'cashier_id');
    }
}
