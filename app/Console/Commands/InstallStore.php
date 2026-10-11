<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AdminSetupService;
use App\Services\StoreInstallationService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class InstallStore extends Command
{
    protected $signature = 'aquaflow:install-store';

    protected $description = 'Initialize a clean store with its first owner account (no demo data)';

    public function handle(StoreInstallationService $installer): int
    {
        if (User::query()->exists()) {
            $this->info('An account already exists. Store data and credentials were preserved.');

            return self::SUCCESS;
        }
        $details = [
            'name' => 'First-run Setup', 'username' => 'aquaflow_setup',
            'password' => AdminSetupService::PASSWORD,
            'email' => AdminSetupService::EMAIL,
        ];
        try {
            $installer->install($details, true);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $this->info('Open /owner/login on the store host and complete first-run admin setup. The setup account cannot access store operations.');

        return self::SUCCESS;
    }
}
