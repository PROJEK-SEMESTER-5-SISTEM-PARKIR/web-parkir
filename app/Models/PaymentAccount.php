<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PaymentAccount extends Model
{
    protected $fillable = ['type', 'label', 'account_number', 'account_name', 'qris_path', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getQrisUrlAttribute(): ?string
    {
        return $this->qris_path ? Storage::disk('public')->url($this->qris_path) : null;
    }
}