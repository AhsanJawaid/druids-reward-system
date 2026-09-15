<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Redemption extends Model
{
    protected $fillable = [
        'customer_id',
        'reward_id',
        'point_transaction_id',
        'points_spent',
        'discount_code',
        'shopify_discount_id',
        'shopify_object_type',
        'product_gid',
        'status',
        'expires_at',
        'graphql_result',
    ];

    protected function casts(): array
    {
        return [
            'points_spent' => 'integer',
            'expires_at' => 'datetime',
            'graphql_result' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }
}
