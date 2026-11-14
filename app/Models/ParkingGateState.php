<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParkingGateState extends Model
{
    protected $fillable = [
        'gate',
        'trigger',
        'izin',
        'status',
        'face_match',
        'face_member_id',
        'face_distance',
        'plate_match',
        'plate_member_id',
        'plate_vehicle_id',
        'plate_detected',
        // Tracking percobaan & kualitas (Gate Decision Rules)
        'attempt_count',
        'quality_fail_count',
        'last_failure_reason',
        'face_votes',
        'face_frame_count',
    ];

    protected $casts = [
        'trigger'           => 'boolean',
        'face_match'        => 'boolean',
        'plate_match'       => 'boolean',
        'attempt_count'     => 'integer',
        'quality_fail_count' => 'integer',
        'face_frame_count'  => 'integer',
        'face_votes'        => 'array',
    ];
}
