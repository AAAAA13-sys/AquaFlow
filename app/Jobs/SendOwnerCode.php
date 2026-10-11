<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendOwnerCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    public int $backoff = 15;

    public function __construct(public string $challengeId, public string $code) {}

    public function handle(): void
    {
        $challenge = DB::table('owner_login_challenges')->where('id', $this->challengeId)->first();
        if (! $challenge || $challenge->consumed_at || now()->gte($challenge->expires_at)) {
            return;
        }
        if (config('mail.default') !== 'smtp') {
            throw new \RuntimeException('Owner verification requires SMTP.');
        }
        try {
            Mail::mailer('smtp')->raw('Your AquaFlow owner sign-in code is '.$this->code.'. It expires five minutes after the request. Do not share this code.', function ($message) use ($challenge): void {
                $message->to($challenge->email)->subject('AquaFlow owner sign-in verification');
            });
        } catch (\Throwable) {
            // SMTP errors can contain credentials or message content; never persist them.
            throw new \RuntimeException('Verification email delivery failed. Check the private SMTP configuration.');
        }
    }
}
