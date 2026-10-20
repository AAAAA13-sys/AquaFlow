<?php

namespace App\Http\Controllers;

use App\Http\Requests\DecideApprovalRequest;
use App\Http\Resources\ApprovalRequestResource;
use App\Models\ApprovalRequest;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Approval Requests screen, for both reviewer tiers.
 *
 * Read access is scoped by `ApprovalService::visibleTo`, so a manager can only
 * ever fetch the requests routed to them. The owner sees both tiers because the
 * scope widens with rank on the server - the browser never chooses.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->approvals->visibleTo($request->user());

        $state = $request->string('state')->toString();
        if (in_array($state, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $query->where('state', $state);
        }

        $type = $request->string('type')->toString();
        if ($type !== '') {
            $query->where('type', $type);
        }

        $rows = $query->orderByDesc('id')->limit(200)->get();

        return response()->json([
            'requests' => ApprovalRequestResource::collection($rows),
            'pending_count' => $this->approvals->visibleTo($request->user())->pending()->count(),
        ]);
    }

    public function show(Request $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        $this->authorizeView($request, $approvalRequest);

        return response()->json([
            'request' => new ApprovalRequestResource($approvalRequest->load(['requester', 'decider', 'events.actor'])),
        ]);
    }

    public function decide(DecideApprovalRequest $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        $data = $request->validated();
        $this->authorizeView($request, $approvalRequest);

        $result = $this->approvals->decide(
            $approvalRequest,
            $request->user(),
            $data['decision'],
            $data['note'] ?? null,
            $data['submission_key'],
        );

        return response()->json([
            'replayed' => $result['replayed'],
            'request' => new ApprovalRequestResource($result['request']->load(['requester', 'decider', 'events.actor'])),
        ]);
    }

    public function cancel(Request $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        $this->authorizeView($request, $approvalRequest);

        $cancelled = $this->approvals->cancel(
            $approvalRequest,
            $request->user(),
            $request->string('note')->toString() ?: null,
        );

        return response()->json([
            'request' => new ApprovalRequestResource($cancelled->load(['requester', 'decider', 'events.actor'])),
        ]);
    }

    /** A viewer must be able to see a request before acting on it. */
    private function authorizeView(Request $request, ApprovalRequest $approvalRequest): void
    {
        abort_unless(
            $this->approvals->visibleTo($request->user())->whereKey($approvalRequest->id)->exists(),
            404,
            'Approval request not found.',
        );
    }
}
