<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Rewards\RewardsService;
use Illuminate\Console\Command;

class AwardBirthdayPointsCommand extends Command
{
    protected $signature = 'rewards:award-birthdays';

    protected $description = 'Award 250 birthday points from Shopify-sourced birthdays (once per calendar year).';

    public function handle(RewardsService $rewards): int
    {
        $count = 0;

        Customer::query()->whereNotNull('birthday')->each(function (Customer $customer) use ($rewards, &$count) {
            $payload = [
                'email' => $customer->email,
                'id' => $customer->shopify_customer_id,
                'first_name' => $customer->name,
                'birthday' => $customer->birthday?->toDateString(),
            ];
            $tx = $rewards->awardBirthdayIfDue($payload, $customer->shop_domain);
            if ($tx && $tx->wasRecentlyCreated) {
                $count++;
                $this->info($customer->email.' +250');
            }
        });

        $this->info("Awarded {$count} birthday bonus(es).");

        return self::SUCCESS;
    }
}
