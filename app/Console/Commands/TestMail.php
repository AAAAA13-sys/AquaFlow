<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class TestMail extends Command
{
    protected $signature = 'aquaflow:mail-test {recipient}';

    protected $description = 'Send a non-secret SMTP configuration test to the specified recipient';

    public function handle(): int
    {
        Validator::make(['email' => $this->argument('recipient')], ['email' => ['required', 'email:rfc']])->validate();
        try {
            Mail::mailer('smtp')->raw('AquaFlow SMTP configuration test. No login code is included.', fn ($message) => $message->to($this->argument('recipient'))->subject('AquaFlow mail configuration test'));
        } catch (\Throwable) {
            $this->error('SMTP delivery failed. Check the private environment configuration and network.');

            return self::FAILURE;
        }
        $this->info('SMTP accepted the test message. Check the recipient inbox and spam folder.');

        return self::SUCCESS;
    }
}
