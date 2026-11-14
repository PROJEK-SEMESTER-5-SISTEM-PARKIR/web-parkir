<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuestAccessPermission extends Model
{
    protected $fillable = [
        'member_id',
        'vehicle_id',
        'guest_name',
        'guest_phone',
        'valid_from',
        'valid_until',
        'is_active',
        'used_entry',
        'used_exit',
        'entry_at',
        'exit_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'valid_from'  => 'datetime',
            'valid_until' => 'datetime',
            'entry_at'    => 'datetime',
            'exit_at'     => 'datetime',
            'is_active'   => 'boolean',
            'used_entry'  => 'boolean',
            'used_exit'   => 'boolean',
        ];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Return true jika izin masih aktif dan saat ini berada dalam rentang waktu valid.
     */
    public function isCurrentlyValid(): bool
    {
        $now = now();

        return $this->is_active
            && $this->valid_from <= $now
            && $this->valid_until >= $now;
    }

    /**
     * Return true jika izin sudah melewati waktu berakhir.
     */
    public function isExpired(): bool
    {
        return $this->valid_until < now();
    }
}
