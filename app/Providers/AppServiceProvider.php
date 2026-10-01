<?php

namespace App\Providers;

use App\Models\SystemSetting;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // API responses use explicit top-level keys ({customers: [...]},
        // {transaction: {...}}), so resources must not add a "data" wrapper.
        JsonResource::withoutWrapping();

        // Share station branding and settings with the shells that need them.
        View::composer(
            ['layouts.base', 'layouts.admin', 'layouts.cashier', 'auth.*', 'admin.*', 'cashier.*'],
            function (\Illuminate\View\View $view): void {
                $settings = self::systemSettings();

                $view->with('settings', $settings);
                $view->with('stationName', $settings[SystemSetting::KEY_STATION_NAME] ?? 'AquaFlow');
            }
        );
    }

    /**
     * Station settings, read once per request.
     *
     * @return array<string,string>
     */
    private static function systemSettings(): array
    {
        static $settings = null;

        if ($settings !== null) {
            return $settings;
        }

        try {
            $settings = SystemSetting::allValues();
        } catch (Throwable) {
            // The schema may not exist yet (first install / before migrations).
            $settings = [];
        }

        return $settings;
    }
}
