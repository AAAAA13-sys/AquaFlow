<?php

namespace App\Http\Controllers;

use App\Http\Requests\VoidRequest;
use App\Services\VoidService;
use Illuminate\Http\JsonResponse;

class VoidController extends Controller
{
    public function store(VoidRequest $request, VoidService $service): JsonResponse
    {
        $result = $service->execute($request->user(), $request->validated());
        return response()->json($result, $result['replayed'] ? 200 : 201);
    }
}
