<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParkingGateState extends Model
{
    protected $fillable=[
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
    ];

    protected $casts=[
        'trigger'=>'boolean',
        'face_match'=>'boolean',
        'plate_match'=>'boolean',
    ];
}