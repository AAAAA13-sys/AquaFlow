<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerStatementRequest;
use App\Models\Customer;
use App\Services\CustomerStatementService;
use Illuminate\Http\JsonResponse;

class CustomerStatementController extends Controller
{
    public function __construct(private readonly CustomerStatementService $statements) {}

    public function show(CustomerStatementRequest $request, Customer $customer): JsonResponse
    {
        // The walk-in guest is a shared placeholder, not an account.
        abort_if($customer->system_key === 'walk-in', 404, 'The walk-in guest has no account statement.');

        return response()->json($this->statements->build($customer, $request->period()));
    }
}
