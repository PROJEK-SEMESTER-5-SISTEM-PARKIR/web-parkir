<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessRequest extends Model
{
    protected $fillable = [
        'member_id', 'gate', 'vehicle_id', 'direction', 'reason',
        'plate_detected', 'plate_match', 'face_match', 'face_distance',
        'status', 'requested_at', 'responded_at', 'expires_at', 'buzzer_at',
    ];

    protected function casts(): array
    {
        return [
            'plate_match' => 'boolean',
            'face_match' => 'boolean',
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
            'expires_at' => 'datetime',
            'buzzer_at' => 'datetime',
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

    public function securityAlert()
    {
        return $this->hasOne(SecurityAlert::class);
    }
}