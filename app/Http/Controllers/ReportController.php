<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportSummaryRequest;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function summary(ReportSummaryRequest $request): JsonResponse
    {
        [$from, $to] = $request->range();

        return response()->json($this->reports->summary($from, $to));
    }
}
