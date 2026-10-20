<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettleDebtRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Resources\ApprovalRequestResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\TransactionResource;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Services\ApprovalService;
use App\Services\CustomerLedgerService;
use App\Support\ApprovalTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query()->search($request->string('q')->toString());

        match ($request->string('filter')->toString()) {
            'bottles' => $query->withBottles(),
            'cash' => $query->withDebt(),
            default => $query,
        };

        // The synthetic walk-in record anchors walk-in sales; it is never someone a
        // cashier searches for, and stage 1 has a dedicated Quick Walk-In button.
        // Rows created before `system_key` existed are matched by their legacy
        // display name instead.
        if ($request->boolean('exclude_guest')) {
            $query->where(function ($inner): void {
                $inner->whereNull('system_key')->orWhere('system_key', '!=', 'walk-in');
            })->where('name', '!=', 'Walk-in Guest');
        }

        // The terminal searches as the cashier types and needs only a short,
        // explicitly ordered result list, so it sends `order` and `limit`.
        // Admin callers send neither and keep the original full, id-ordered
        // response.
        match ($request->string('order')->toString()) {
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            default => $query->orderBy('id'),
        };

        $limit = $request->integer('limit', 0);
        if ($limit > 0) {
            $query->limit(min($limit, 50));
        }

        return response()->json([
            'customers' => CustomerResource::collection($query->get()),
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $data = $request->validated();

        $customer = Customer::query()->create([
            'name' => $data['name'],
            'address' => ($data['address'] ?? null) ?: '-',
            'contact' => ($data['contact'] ?? null) ?: '-',
        ]);

        return response()->json(['customer' => new CustomerResource($customer)], 201);
    }

    /**
     * Record a debt payment.
     *
     * A manager or owner collects directly, as before. A cashier's collection is
     * a proposal: the money is recorded and reserved against the customer's
     * balance, but the ledger only moves when a manager approves it. That is why
     * a cashier's response says "pending approval" instead of returning a
     * transaction - there is no receipt yet.
     */
    public function settle(SettleDebtRequest $request, Customer $customer): JsonResponse
    {
        $user = $request->user();
        $amount = (float) $request->validated()['amount'];

        // Owners and managers hold cash authority and keep the direct path.
        if ($user->isAdmin()) {
            $result = app(CustomerLedgerService::class)->settle($customer, $amount, $user, $request->validated()['submission_key']);

            return response()->json([
                'replayed' => $result['replayed'],
                'applied' => $result['applied'],
                'pending_approval' => false,
                'transaction' => new TransactionResource($result['transaction']),
                'customer' => new CustomerResource($result['customer']),
            ]);
        }

        $approvals = app(ApprovalService::class);

        // The proposal itself is idempotent per cashier: re-sending the same
        // submission key returns the request already raised for it rather than
        // filing a duplicate collection.
        $existing = ApprovalRequest::query()
            ->where('type', ApprovalTypes::SETTLEMENT_CREATE)
            ->where('requested_by', $user->id)
            ->where('state', ApprovalRequest::STATE_PENDING)
            ->whereJsonContains('payload->submission_key', $request->validated()['submission_key'])
            ->first();

        if ($existing) {
            return response()->json([
                'replayed' => true,
                'applied' => 0,
                'pending_approval' => true,
                'message' => 'Collection recorded earlier. A manager must approve it before the customer\'s balance changes.',
                'request' => new ApprovalRequestResource($existing->load(['requester'])),
                'customer' => new CustomerResource($customer),
            ], 202);
        }

        $requestRow = DB::transaction(function () use ($customer, $amount, $user, $request, $approvals): ApprovalRequest {
            /** @var Customer $locked */
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            // The cashier's proposal is held to the same availability rule as a
            // direct collection: rider-carrying cash and earlier unapproved
            // collections both reduce what can still be taken at the counter.
            app(CustomerLedgerService::class)->assertCollectible($locked, round($amount, 2));

            $row = $approvals->submit($user, ApprovalTypes::SETTLEMENT_CREATE, [
                'customer_id' => $locked->id,
                'customer_name' => $locked->name,
                'amount' => round($amount, 2),
                'balance_before' => (float) $locked->debt_balance,
                'submission_key' => $request->validated()['submission_key'],
            ], 'Cash received by ' . $user->name, (string) $locked->id);

            // Reserve the cash so the counter cannot collect it twice.
            $approvals->reserveSettlement($locked, round($amount, 2));

            return $row;
        });

        return response()->json([
            'replayed' => false,
            'applied' => 0,
            'pending_approval' => true,
            'message' => 'Collection recorded. A manager must approve it before the customer\'s balance changes.',
            'request' => new ApprovalRequestResource($requestRow->load(['requester'])),
            'customer' => new CustomerResource($customer->refresh()),
        ], 202);
    }
}
