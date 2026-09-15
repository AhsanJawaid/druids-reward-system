<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reward extends Model
{
    public const CATALOG = [
        [
            'slug' => 'money_off',
            'name' => 'Money off (small)',
            'description' => '£5 off the customer\'s next order.',
            'points_cost' => 500,
            'discount_type' => 'fixed_amount',
            'discount_value' => 5,
        ],
        [
            'slug' => 'free_shipping',
            'name' => 'Free shipping',
            'description' => 'Waives the shipping charge on the customer\'s next order.',
            'points_cost' => 500,
            'discount_type' => 'free_shipping',
            'discount_value' => 0,
        ],
        [
            'slug' => 'free_product',
            'name' => 'Free product',
            'description' => 'The customer selects one item from a curated collection of eligible products. Shipping is still charged normally.',
            'points_cost' => 1500,
            'discount_type' => 'free_product',
            'discount_value' => 0,
        ],
        [
            'slug' => 'gift_card',
            'name' => 'Gift card',
            'description' => 'A £25 store gift card.',
            'points_cost' => 2500,
            'discount_type' => 'gift_card',
            'discount_value' => 25,
        ],
    ];

    protected $fillable = [
        'name',
        'slug',
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

    public static function syncCatalog(): void
    {
        foreach (self::CATALOG as $row) {
            static::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'points_cost' => $row['points_cost'],
                    'discount_type' => $row['discount_type'],
                    'discount_value' => $row['discount_value'],
                    'active' => true,
                ],
            );
        }

        $slugs = collect(self::CATALOG)->pluck('slug');
        static::query()
            ->where(function ($query) use ($slugs) {
                $query->whereNull('slug')->orWhereNotIn('slug', $slugs);
            })
            ->update(['active' => false]);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    public function label(): string
    {
        return match ($this->slug) {
            'money_off' => '£5 off the next order',
            'free_shipping' => 'Free shipping on the next order',
            'free_product' => 'One item from the eligible collection',
            'gift_card' => '£25 store gift card',
            default => $this->name,
        };
    }
}
