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
            'addr' => $this->address,
            'contact' => $this->contact,
            'issuedS' => (int) $this->issued_slim,
            'returnedS' => (int) $this->returned_slim,
            'issuedR' => (int) $this->issued_round,
            'returnedR' => (int) $this->returned_round,
            'debt' => (float) $this->debt_balance,
            'tx' => (int) $this->total_transactions,
            'last' => $this->last_visit?->format('Y-m-d') ?? '-',
            'pending' => [
                'slim' => $this->pendingSlim(),
                'round' => $this->pendingRound(),
            ],
        ];
    }
}
