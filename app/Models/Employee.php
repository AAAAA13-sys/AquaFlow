<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'job_title', 'contact_number', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
