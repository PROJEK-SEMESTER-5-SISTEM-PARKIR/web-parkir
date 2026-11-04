<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffContact extends Model
{
    protected $fillable = ['name', 'phone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}