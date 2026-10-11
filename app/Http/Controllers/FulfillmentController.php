<?php

namespace App\Http\Controllers;

use App\Http\Requests\FulfillmentRequest;
use App\Services\FulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FulfillmentController extends Controller
{
    public function index(Request $request, FulfillmentService $service): JsonResponse
    {
        return response()->json(['orders' => $service->listing($request->user()), 'updated_at' => now()->toISOString(), 'quantities' => $request->user()->isEmployee() ? null : $service->quantities()]);
    }

    public function update(FulfillmentRequest $request, int $id, FulfillmentService $service): JsonResponse
    {
        return response()->json(['order' => $service->change($id, $request->user(), $request->validated())]);
    }
}
