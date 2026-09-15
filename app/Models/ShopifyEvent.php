<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyEvent extends Model
{
    protected $fillable = [
        'topic',
        'shop_domain',
        'webhook_id',
        'hmac_verified',
        'status',
        'message',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'hmac_verified' => 'boolean',
            'payload' => 'array',
        ];
    }
}
