<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An AquaFlow account. Authentication is by username, and the `pin` column
 * holds the extra Owner PIN (both stored as bcrypt hashes).
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    public const ROLE_CASHIER = 'cashier';
    public const ROLE_ADMIN = 'admin';

    protected $fillable = [
        'username',
        'password',
        'role',
        'name',
        'pin',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'pin',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'cashier_id');
    }
}
