<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetOtp extends Model
{
    public $timestamps = false;

    protected $table = 'password_reset_otps';

    protected $fillable = [
        'email',
        'otp_hash',
        'attempts',
        'expires_at',
        'created_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
