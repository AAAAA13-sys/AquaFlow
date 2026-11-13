<?php

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'spent_on' => $this->spent_on?->format('Y-m-d'),
            'category' => $this->category,
            'category_label' => Expense::CATEGORIES[$this->category] ?? $this->category,
            'amount' => round((float) $this->amount, 2),
            'payee' => $this->payee,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'recorded_by' => ['id' => $this->recorded_by, 'name' => $this->recorder?->name],
            'voided_at' => $this->voided_at?->toIso8601String(),
            'voided_by' => $this->voided_by ? ['id' => $this->voided_by, 'name' => $this->voider?->name] : null,
            'void_reason' => $this->void_reason,
        ];
    }
}
