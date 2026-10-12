<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StoreInstallationService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a clean store: one temporary bootstrap account plus its one-time setup
 * code, no demo data.
 *
 * Safe to re-run. While setup is unfinished it issues a fresh code (the previous
 * one is replaced), which is the documented recovery path when the printed code
 * is lost. Once a real owner account exists it does nothing at all.
 */
class InstallStore extends Command
{
    protected $signature = 'aquaflow:install-store';

    protected $description = 'Initialize a clean store with its first owner account (no demo data)';

    public function handle(StoreInstallationService $installer): int
    {
        if (User::query()->exists()) {
            return $this->reissueOrReportFinished($installer);
        }

        $secret = StoreInstallationService::generateSecret();

        $details = [
            'name' => 'First-run Setup',
            'username' => 'aquaflow_setup',
            // Never used to authenticate: step 1 requires the generated secret and
            // the owner replaces this during setup. It only has to satisfy the same
            // password policy as any other account.
            'password' => Str::random(20).'aA1',
            'email' => StoreInstallationService::BOOTSTRAP_EMAIL,
        ];

        try {
            $installer->install($details, true, $secret);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $this->printSecret($secret);

        return self::SUCCESS;
    }

    private function reissueOrReportFinished(StoreInstallationService $installer): int
    {
        $secret = $installer->reissueSecret();

        if ($secret === null) {
            $this->info('An account already exists. Store data and credentials were preserved.');

            return self::SUCCESS;
        }

        $this->line('Setup was started but never finished. A new code replaces the old one.');

        $this->printSecret($secret);

        return self::SUCCESS;
    }

    private function printSecret(string $secret): void
    {
        $this->newLine();
        $this->line('    ==========================================');
        $this->line('       SETUP CODE:  '.$secret);
        $this->line('    ==========================================');
        $this->newLine();
        $this->info('Open /owner/login on the store host and enter this code to create your owner account.');
        $this->line('It is shown once and stored only as a hash. Lost it? Run this command again.');
    }
}
