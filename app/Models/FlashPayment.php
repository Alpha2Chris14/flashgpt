<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlashPayment extends Model
{
    protected $fillable = [
        'mch_order_no',
        'pay_order_id',
        'mch_no',
        'app_id',
        'way_code',
        'amount',
        'currency',
        'status',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
