<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'job_title' => $this->job_title, 'contact_number' => $this->contact_number, 'is_active' => $this->is_active, 'created_at' => $this->created_at?->toIso8601String()];
    }
}
