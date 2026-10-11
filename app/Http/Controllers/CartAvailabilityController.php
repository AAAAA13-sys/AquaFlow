<?php

namespace App\Http\Controllers;

use App\Http\Requests\CartAvailabilityRequest;
use App\Services\CartAvailabilityService;
use Illuminate\Http\JsonResponse;

class CartAvailabilityController extends Controller
{
    public function __invoke(CartAvailabilityRequest $request, CartAvailabilityService $service): JsonResponse
    {
        return response()->json($service->quote($request->validated('items')));
    }
}
