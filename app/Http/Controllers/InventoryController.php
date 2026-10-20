<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryRequest;
use App\Http\Requests\UpdateInventoryRequest;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use App\Services\ApprovalService;
use App\Services\AuditLogService;
use App\Services\InventoryEngine;
use App\Services\StockMovementService;
use App\Support\ApprovalTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryEngine $engine,
    ) {}

    public function index(): JsonResponse
    {
        $items = InventoryItem::query()->current()->with('supplier')->orderBy('id')->get();

        return response()->json([
            'inventory' => InventoryItemResource::forItems($items, $this->engine),
            'advisories' => $this->engine->advisories(),
        ]);
    }

    public function store(StoreInventoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $note = $request->string('reason_note')->toString() ?: null;

        if (! $user->isSuperAdmin()) {
            return app(ApprovalService::class)->stage($user, ApprovalTypes::INVENTORY_CREATE, $data, $note);
        }

        $item = DB::transaction(function () use ($data, $user): InventoryItem {
            $item = app(StockMovementService::class)->createItem($data, $user);

            app(AuditLogService::class)->record($user, 'create', $item, null,
                ['item_name' => $item->item_name, 'unit' => $item->unit]);

            return $item;
        });

        return response()->json([
            'item' => new InventoryItemResource($item->refresh()->load('supplier')),
            'advisories' => $this->engine->advisories(),
            'pending_approval' => false,
        ], 201);
    }

    public function update(UpdateInventoryRequest $request, InventoryItem $inventory): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $note = $request->string('reason_note')->toString() ?: null;

        if (! $user->isSuperAdmin()) {
            // A stock adjustment (`reason`, `direction`, `stock_on_hand`) is
            // applied by the engine directly. Only the descriptive stock fields
            // travel in the proposal, so a manager's edit never smuggles a
            // quantity change past approval.
            return app(ApprovalService::class)->stage($user, ApprovalTypes::INVENTORY_UPDATE, $data, $note, (string) $inventory->id);
        }

        $before = [];
        foreach (array_keys($data) as $key) {
            $before[$key] = $inventory->getAttribute($key);
        }

        $this->engine->updateItem($inventory, $data);

        app(AuditLogService::class)->record($user, 'update', $inventory, $before,
            array_intersect_key($inventory->refresh()->getAttributes(), $before));

        return response()->json([
            'item' => new InventoryItemResource($inventory->refresh()->load('supplier')),
            'advisories' => $this->engine->advisories(),
            'pending_approval' => false,
        ]);
    }

    public function destroy(Request $request, InventoryItem $inventory): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            return app(ApprovalService::class)->stage($user, ApprovalTypes::INVENTORY_DELETE,
                ['item_name' => $inventory->item_name],
                $request->string('reason_note')->toString() ?: null, (string) $inventory->id);
        }

        DB::transaction(function () use ($inventory, $user): void {
            app(AuditLogService::class)->record($user, 'delete', $inventory, ['item_name' => $inventory->item_name], null);
            app(StockMovementService::class)->deleteItem($inventory);
        });

        return response()->json([
            'ok' => true,
            'advisories' => $this->engine->advisories(),
            'pending_approval' => false,
        ]);
    }

    public function recalculate(): JsonResponse
    {
        $updated = $this->engine->recalculateAll();
        $items = InventoryItem::query()->current()->with('supplier')->orderBy('id')->get();

        return response()->json([
            'updated' => $updated,
            'inventory' => InventoryItemResource::forItems($items, $this->engine),
            'advisories' => $this->engine->advisories(),
        ]);
    }
}
