<?php

namespace Tests;

use App\Models\CashShift;
use App\Models\User;
use App\Services\CashReconciliationService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * POS sales require an opened station-day drawer. Open one through the
     * real service (idempotent per day) so sale tests exercise the same guard
     * the cashier hits. A zero float keeps cash figures equal to sales only.
     */
    protected function openDrawer(?User $cashier = null): CashShift
    {
        $cashier ??= User::where('username', 'cashier')->firstOrFail();

        return app(CashReconciliationService::class)
            ->open($cashier, 0, null, 'Test station drawer')['shift'];
    }
}
