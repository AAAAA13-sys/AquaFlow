<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SendPasswordResetOtpJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    public int $backoff = 15;

    public function __construct(
        public string $email,
        public string $code
    ) {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        $record = DB::table('password_reset_otps')->where('email', $this->email)->first();
        if (! $record || now()->gte($record->expires_at) || ! Hash::check($this->code, $record->otp_hash)) {
            return;
        }
        $mailer = config('mail.default', 'smtp');

        try {
            Mail::mailer($mailer)->raw(
                "Your AquaFlow password reset code is: {$this->code}\n\nThis verification code will expire in 15 minutes. If you did not request a password reset, please ignore this email or review your account security.",
                function ($message): void {
                    $message->to($this->email)
                        ->subject('AquaFlow Password Reset Code');
                }
            );
        } catch (\Throwable) {
            // SMTP errors can contain credentials or sensitive transport traces; never leak them.
            throw new \RuntimeException('Failed to deliver password reset email. Please check the SMTP configuration.');
        }
    }
}
