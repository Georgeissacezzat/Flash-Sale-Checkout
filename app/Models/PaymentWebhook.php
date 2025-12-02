<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhook extends Model
{
    protected $table = 'payment_webhooks';
    protected $fillable = ['order_id', 'idempotency_key'];
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
