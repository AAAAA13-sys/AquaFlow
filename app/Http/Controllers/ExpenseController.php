<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseIndexRequest;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\VoidExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;

class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(ExpenseIndexRequest $request): JsonResponse
    {
        [$from, $to] = $request->range();
        $category = $request->validated()['category'] ?? null;

        return response()->json([
            'expenses' => ExpenseResource::collection($this->expenses->list($from, $to, $category)),
            'totals' => $this->expenses->totalsBetween($from, $to, $category),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $expense = $this->expenses->record($request->user(), $request->validated());

        // A replay answers exactly as the first call did.
        return response()->json(['expense' => new ExpenseResource($expense)], 201);
    }

    public function void(VoidExpenseRequest $request, Expense $expense): JsonResponse
    {
        $expense = $this->expenses->void($request->user(), $expense, $request->validated()['reason']);

        return response()->json(['expense' => new ExpenseResource($expense)]);
    }
}
