<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RenewalPayment extends Model
{
    protected $fillable = [
        'member_id', 'subscription_plan_id', 'payment_account_id',
        'vehicle_count', 'base_amount', 'unique_code', 'total_amount',
        'proof_path', 'status', 'reject_reason',
        'reviewed_by', 'reviewed_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function member() { return $this->belongsTo(Member::class); }
    public function plan() { return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id'); }
    public function account() { return $this->belongsTo(PaymentAccount::class, 'payment_account_id'); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}