<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hold extends Model
{
    protected $table = 'holds';
    protected $fillable = ['product_id', 'qty', 'used', 'expires_at'];
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function order()
    {
        return $this->hasOne(Order::class);
    }
}
