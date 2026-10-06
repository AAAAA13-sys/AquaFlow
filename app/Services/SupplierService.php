<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierService
{
    public function create(array $data): Supplier
    {
        $data['contact'] = ($data['contact'] ?? null) ?: '-';
        $data['lead_time_days'] = $data['lead_time_days'] ?? 2;

        return Supplier::create($data);
    }

    public function delete(Supplier $supplier): void
    {
        if (StockMovement::where('supplier_id', $supplier->id)->exists()) {
            throw ValidationException::withMessages(['supplier' => 'This supplier has procurement history and cannot be deleted.']);
        }
        $supplier->delete();
    }

    public function update(Supplier $supplier, array $data): void
    {
        DB::transaction(function () use ($supplier, $data): void {
            $supplier = Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('contact', $data) && ! $data['contact']) {
                $data['contact'] = '-';
            }
            $supplier->fill($data)->save();
            if (array_key_exists('lead_time_days', $data)) {
                foreach (InventoryItem::where('supplier_id', $supplier->id)->orderBy('id')->lockForUpdate()->get() as $item) {
                    $item->lead_time_days = $supplier->lead_time_days;
                    app(InventoryEngine::class)->recalculateItem($item);
                }
            }
        });
    }
}
