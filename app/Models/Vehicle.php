<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $fillable = ['member_id', 'plate_number', 'vehicle_type'];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function parkingSessions()
    {
        return $this->hasMany(ParkingSession::class);
    }

    public function guestPermissions()
    {
        return $this->hasMany(GuestAccessPermission::class);
    }
}