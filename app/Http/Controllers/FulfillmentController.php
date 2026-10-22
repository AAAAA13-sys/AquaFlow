<?php

namespace App\Http\Controllers;

use App\Http\Requests\FulfillmentRequest;
use App\Http\Requests\RemittanceRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\TransactionResource;
use App\Models\ProductionQueueItem;
use App\Models\User;
use App\Services\CustomerLedgerService;
use App\Services\FulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FulfillmentController extends Controller
{
    public function index(Request $request, FulfillmentService $service): JsonResponse
    {
        return response()->json([
            'orders' => $service->listing($request->user(), $request->query('mode')),
            'updated_at' => now()->toISOString(),
            'quantities' => $request->user()->isEmployee() ? null : $service->quantities(),
            'riders' => $request->user()->isAdmin()
                ? User::where('role', User::ROLE_RIDER)->where('is_active', true)->whereHas('employee', fn ($query) => $query->where('is_active', true))->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function update(FulfillmentRequest $request, int $id, FulfillmentService $service): JsonResponse
    {
        return response()->json(['order' => $service->change($id, $request->user(), $request->validated(), $request->input('mode'))]);
    }

    public function show(Request $request, int $id, FulfillmentService $service): JsonResponse
    {
        abort_unless($request->user()->canHandleCash(), 403);

        return response()->json(['order' => $service->row(ProductionQueueItem::findOrFail($id), $request->user())]);
    }

    public function proof(Request $request, int $id)
    {
        $job = ProductionQueueItem::findOrFail($id);
        $user = $request->user();
        abort_unless($user->canHandleCash() || ($user->role === 'rider' && (int) $job->rider_id === $user->id), 403);
        $proof = $job->deliveryProof;
        abort_unless($proof, 404);

        return Storage::disk('local')->response($proof->photo_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function remit(RemittanceRequest $request, int $id, CustomerLedgerService $ledger): JsonResponse
    {
        $job = ProductionQueueItem::findOrFail($id);
        $proof = $job->deliveryProof;
        abort_unless($proof, 422, 'A completed delivery collection is required.');
        $data = $request->validated();
        $result = $ledger->settle($job->transaction->customer, (float) $data['amount'], $request->user(), $data['submission_key'], $proof->id, $data['note'] ?? null);

        return response()->json(['customer' => new CustomerResource($result['customer']), 'transaction' => new TransactionResource($result['transaction']), 'replayed' => $result['replayed']]);
    }
}
