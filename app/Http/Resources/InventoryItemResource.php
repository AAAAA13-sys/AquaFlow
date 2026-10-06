<?php

namespace App\Http\Resources;

use App\Models\InventoryItem;
use App\Services\InventoryEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin InventoryItem
 */
class InventoryItemResource extends JsonResource
{
    /**
     * Resolve days-of-cover for the whole set in one pass, then attach it.
     *
     * Without this each item would trigger its own demandProfile() lookup, so a
     * 7-row table became 7 forecast queries.
     *
     * @param  Collection<int, InventoryItem>  $items
     */
    public static function forItems(Collection $items, InventoryEngine $engine): AnonymousResourceCollection
    {
        $cover = $engine->daysRemainingFor($items);

        // Note: ResourceCollection::map() wraps each item in `new static(...)`
        // before handing it to the callback, so the model type hint would fail.
        // Building the array of resources up front avoids that double wrap.
        return self::collection(
            $items
                ->map(fn (InventoryItem $item): self => new self($item, [
                    'cover' => $cover[$item->id] ?? $engine->daysRemaining($item),
                ]))
                ->all()
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        // Days-of-cover is resolved by the inventory engine and injected by
        // forItems(). Falling back to the engine here keeps this resource correct
        // when constructed directly, so a new caller cannot reintroduce a
        // divergent number.
        $engine = app(InventoryEngine::class);
        $cover = $this->additional['cover'] ?? $engine->daysRemaining($this->resource);

        return [
            'id' => $this->id,
            'item' => $this->item_name,
            'cat' => $this->category,
            'on' => (int) $this->stock_on_hand,
            'on_label' => $engine->quantityLabel((int) $this->stock_on_hand, (string) $this->unit),
            'unit' => $this->unit,
            'ss' => (int) $this->safety_stock,
            'rop' => (int) $this->reorder_point,
            'target' => (int) $this->target_stock,
            'supplier_id' => $this->supplier_id,
            'lead' => (int) $this->lead_time_days,

            // Canonical figures - the browser must not recompute these.
            'days_left' => $cover['days_left'],
            'daily_demand' => $cover['daily_demand'],
            'demand_source' => $cover['demand_source'],

            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier?->name ?? '-', '-'),
            'status' => $this->status(),
            // Explicit action rather than a passive "Warning".
            'status_label' => $this->needsReorder() ? 'REORDER NOW' : 'OK',
            'needs_reorder' => $this->needsReorder(),
            'recalculated' => $this->recalculated_at?->toIso8601String(),
        ];
    }
}
