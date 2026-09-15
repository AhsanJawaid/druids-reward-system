<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reward extends Model
{
    protected $fillable = [
        'name',
        'description',
        'points_cost',
        'discount_type',
        'discount_value',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'points_cost' => 'integer',
            'discount_value' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    public function label(): string
    {
        if ($this->discount_type === 'percentage') {
            return rtrim(rtrim(number_format((float) $this->discount_value, 2), '0'), '.').'% off';
        }

        return '$'.number_format((float) $this->discount_value, 2).' off';
    }
}
