<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_guest' => $this->system_key === 'walk-in',
            'created_at' => $this->created_at?->toISOString(),
            'addr' => $this->address,
            'contact' => $this->contact,
            'issuedS' => (int) $this->issued_slim,
            'returnedS' => (int) $this->returned_slim,
            'issuedR' => (int) $this->issued_round,
            'returnedR' => (int) $this->returned_round,
            'debt' => (float) $this->debt_balance,
            // Cash already handed over but not yet approved. The counter must
            // see this so it does not collect the same pesos twice.
            'settlement_pending' => (float) $this->settlement_pending_amount,
            'settlement_pending_at' => $this->settlement_pending_at?->toISOString(),
            // What can still be collected right now.
            'collectible' => round(max(0, (float) $this->debt_balance - (float) $this->settlement_pending_amount), 2),
            'tx' => (int) $this->total_transactions,
            'last' => $this->last_visit?->format('Y-m-d') ?? '-',
            'pending' => [
                'slim' => $this->pendingSlim(),
                'round' => $this->pendingRound(),
            ],
        ];
    }
}
