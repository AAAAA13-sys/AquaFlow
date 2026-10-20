<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuditLogResource;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Audit Log screen.
 *
 * The rank filter lives in `AuditLogService::query`, so a manager's request for
 * "all changes" still returns only the changes made below their rank. Filters
 * here narrow an already-scoped result; they can never widen it.
 */
class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->audit->query($request->user());

        if ($from = $request->string('from')->toString()) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->string('to')->toString()) {
            $query->where('created_at', '<=', $to);
        }
        if ($entity = $request->string('entity_type')->toString()) {
            $query->where('entity_type', $entity);
        }
        if ($action = $request->string('action')->toString()) {
            $query->where('action', $action);
        }
        if ($actor = $request->integer('actor_id')) {
            $query->where('actor_id', $actor);
        }

        $rows = $query->orderByDesc('id')->limit(300)->get();

        return response()->json([
            'logs' => AuditLogResource::collection($rows),
        ]);
    }
}
