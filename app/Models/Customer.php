<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Customer extends Model
{
    protected $fillable = [
        'shop_domain',
        'shopify_customer_id',
        'email',
        'name',
        'birthday',
        'last_birthday_reward_year',
        'account_bonus_awarded',
        'newsletter_bonus_awarded',
        'points_balance',
    ];

    protected function casts(): array
    {
        return [
            'points_balance' => 'integer',
            'birthday' => 'date',
            'last_birthday_reward_year' => 'integer',
            'account_bonus_awarded' => 'boolean',
            'newsletter_bonus_awarded' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    public function pendingPoints(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('point_transactions', 'available_at')) {
            return 0;
        }

        return (int) $this->transactions()
            ->where('type', 'earn')
            ->where('points', '>', 0)
            ->whereNotNull('available_at')
            ->where('available_at', '>', Carbon::now())
            ->sum('points');
    }

    public function spendablePoints(): int
    {
        return max(0, (int) $this->points_balance - $this->pendingPoints());
    }
}
