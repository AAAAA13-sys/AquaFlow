<?php

namespace App\Services;

use App\Jobs\SendPasswordResetOtpJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordResetService
{
    public function sendOtp(string $email): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        // Hash even for unknown addresses to avoid a cheap account-existence timing signal.
        $hash = Hash::make($code);
        DB::transaction(function () use ($email, $code, $hash): void {
            $user = User::where('email', $email)->lockForUpdate()->first();
            if (! $user || ! $user->is_active || $user->is_bootstrap) {
                return;
            }

            DB::table('password_reset_otps')->where('email', $email)->delete();
            DB::table('password_reset_otps')->insert([
                'email' => $email,
                'otp_hash' => $hash,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(15),
                'created_at' => now(),
            ]);
            SendPasswordResetOtpJob::dispatch($email, $code)->afterCommit();
        });
    }

    public function verifyOtp(string $email, string $otp): void
    {
        $error = DB::transaction(function () use ($email, $otp): ?string {
            $user = User::where('email', $email)->lockForUpdate()->first();
            $record = DB::table('password_reset_otps')->where('email', $email)->lockForUpdate()->first();
            $invalid = 'The verification code has expired or is invalid. Please request a new one.';
            if (! $record) {
                return $invalid;
            }
            $tokens = DB::table('password_reset_otps')->where('email', $email);
            if (! $user || ! $user->is_active || $user->is_bootstrap
                || now()->gte($record->expires_at) || $record->attempts >= 5) {
                $tokens->delete();
                return $invalid;
            }
            if (! Hash::check($otp, $record->otp_hash)) {
                if ($record->attempts + 1 >= 5) {
                    $tokens->delete();
                    return 'Too many failed verification attempts. Please request a new code.';
                }
                $tokens->increment('attempts');
                return 'Invalid verification code. Please check your code and try again.';
            }

            return null;
        });

        if ($error !== null) {
            throw ValidationException::withMessages(['otp' => $error]);
        }
    }

    public function resetPassword(string $email, string $otp, string $newPassword): void
    {
        $error = DB::transaction(function () use ($email, $otp, $newPassword): ?string {
            // Both issuance and redemption lock the account first, then its code.
            $user = User::where('email', $email)->lockForUpdate()->first();
            $record = DB::table('password_reset_otps')->where('email', $email)->lockForUpdate()->first();
            $invalid = 'The verification code has expired or is invalid. Please request a new one.';
            if (! $record) {
                return $invalid;
            }
            $tokens = DB::table('password_reset_otps')->where('email', $email);
            if (! $user || ! $user->is_active || $user->is_bootstrap
                || now()->gte($record->expires_at) || $record->attempts >= 5) {
                $tokens->delete();
                return $invalid;
            }
            if (! Hash::check($otp, $record->otp_hash)) {
                if ($record->attempts + 1 >= 5) {
                    $tokens->delete();
                    return 'Too many failed verification attempts. Please request a new code.';
                }
                $tokens->increment('attempts');
                return 'Invalid verification code. Please check your code and try again.';
            }

            $user->update(['password' => Hash::make($newPassword), 'must_change_password' => false]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $tokens->delete();
            return null;
        });

        // Commit failed-attempt counters and token burning before returning validation errors.
        if ($error !== null) {
            throw ValidationException::withMessages(['otp' => $error]);
        }
    }
}
