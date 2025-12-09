<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'reference',
        'country',
        'amount',
        'status',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
