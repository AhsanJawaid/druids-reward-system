<?php

namespace App\Services\Rewards;

use App\Exceptions\InsufficientPointsException;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\ProgramSetting;
use App\Models\Redemption;
use App\Models\Reward;
use App\Services\Shopify\ShopifyGraphqlClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RewardsService
{
    public function __construct(private ShopifyGraphqlClient $shopify) {}

    /**
     * Award points from a Shopify orders/paid (or orders/create) payload.
     *
     * @param  array<string, mixed>  $order
     */
    public function earnFromOrder(array $order, string $shopDomain): ?PointTransaction
    {
        $orderId = $this->orderId($order);
        $email = strtolower((string) data_get($order, 'email', data_get($order, 'customer.email', '')));

        if ($orderId === '' || $email === '') {
            return null;
        }

        $status = strtolower((string) data_get($order, 'financial_status', 'paid'));
        if (in_array($status, ['voided', 'refunded'], true)) {
            return null;
        }

        $settings = ProgramSetting::current();
        $subtotal = (float) data_get($order, 'subtotal_price', data_get($order, 'total_price', 0));

        if ($subtotal < (float) $settings['min_order_amount']) {
            return null;
        }

        $points = (int) floor($subtotal * (float) $settings['points_per_dollar']);
        if ($points <= 0) {
            return null;
        }

        $key = 'shopify:order:'.$orderId.':earn';

        return DB::transaction(function () use ($order, $shopDomain, $email, $orderId, $points, $key, $subtotal) {
            if ($existing = PointTransaction::query()->where('idempotency_key', $key)->first()) {
                return $existing;
            }

            $customer = $this->lockCustomer(
                $shopDomain,
                $email,
                (string) data_get($order, 'customer.id'),
                (string) data_get($order, 'customer.first_name', data_get($order, 'customer.displayName', 'Customer')),
            );

            $customer->points_balance += $points;
            $customer->save();

            return PointTransaction::query()->create([
                'customer_id' => $customer->id,
                'type' => 'earn',
                'points' => $points,
                'balance_after' => $customer->points_balance,
                'source' => 'shopify_order',
                'source_id' => $orderId,
                'idempotency_key' => $key,
                'description' => 'Earned from Shopify order #'.data_get($order, 'name', $orderId),
                'metadata' => [
                    'subtotal' => $subtotal,
                    'order_name' => data_get($order, 'name'),
                    'currency' => data_get($order, 'currency', 'USD'),
                ],
            ]);
        });
    }

    /**
     * Reverse points for a Shopify refunds/create payload.
     *
     * @param  array<string, mixed>  $refund
     */
    public function refundOrder(array $refund, string $shopDomain): ?PointTransaction
    {
        $refundId = (string) (data_get($refund, 'id') ?? '');
        $orderId = (string) (data_get($refund, 'order_id') ?? data_get($refund, 'order.id') ?? '');
        $email = strtolower((string) data_get($refund, 'email', data_get($refund, 'order.email', data_get($refund, 'customer.email', ''))));

        if ($refundId === '' || $email === '') {
            return null;
        }

        $settings = ProgramSetting::current();
        $amount = (float) data_get($refund, 'transactions.0.amount', data_get($refund, 'refund_line_items.0.subtotal', data_get($refund, 'amount', 0)));
        $points = (int) floor($amount * (float) $settings['points_per_dollar']);

        if ($points <= 0) {
            $points = 0;
            foreach ((array) data_get($refund, 'refund_line_items', []) as $item) {
                $points += (int) floor(((float) data_get($item, 'subtotal', 0)) * (float) $settings['points_per_dollar']);
            }
        }

        if ($points <= 0) {
            return null;
        }

        $key = 'shopify:refund:'.$refundId.':refund';

        return DB::transaction(function () use ($shopDomain, $email, $refund, $refundId, $orderId, $points, $key) {
            if ($existing = PointTransaction::query()->where('idempotency_key', $key)->first()) {
                return $existing;
            }

            $customer = $this->lockCustomer($shopDomain, $email, (string) data_get($refund, 'customer.id'));
            $deduct = min($points, $customer->points_balance);
            $customer->points_balance -= $deduct;
            $customer->save();

            return PointTransaction::query()->create([
                'customer_id' => $customer->id,
                'type' => 'refund',
                'points' => -$deduct,
                'balance_after' => $customer->points_balance,
                'source' => 'shopify_refund',
                'source_id' => $refundId,
                'idempotency_key' => $key,
                'description' => 'Reversed for Shopify refund on order '.$orderId,
                'metadata' => ['order_id' => $orderId, 'requested_points' => $points],
            ]);
        });
    }

    public function redeem(Customer $customer, Reward $reward): Redemption
    {
        if (! $reward->active) {
            throw new \InvalidArgumentException('This reward is not available.');
        }

        $customer->refresh();
        if ($customer->points_balance < $reward->points_cost) {
            throw new InsufficientPointsException('Not enough points to redeem this reward.');
        }

        $settings = ProgramSetting::current();
        $code = 'RWD-'.strtoupper(Str::random(8));
        $expiresDays = (int) $settings['discount_expiry_days'];

        $shopify = $this->shopify->createDiscountCode($reward, $code, $expiresDays);

        return DB::transaction(function () use ($customer, $reward, $shopify, $expiresDays) {
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if ($locked->points_balance < $reward->points_cost) {
                throw new InsufficientPointsException('Not enough points to redeem this reward.');
            }

            $locked->points_balance -= $reward->points_cost;
            $locked->save();

            $tx = PointTransaction::query()->create([
                'customer_id' => $locked->id,
                'type' => 'redeem',
                'points' => -$reward->points_cost,
                'balance_after' => $locked->points_balance,
                'source' => 'reward',
                'source_id' => (string) $reward->id,
                'idempotency_key' => 'redeem:'.$locked->id.':'.Str::uuid(),
                'description' => 'Redeemed '.$reward->name,
                'metadata' => [
                    'discount_code' => $shopify['code'],
                    'shopify_discount_id' => $shopify['id'],
                    'mocked' => $shopify['mocked'],
                ],
            ]);

            return Redemption::query()->create([
                'customer_id' => $locked->id,
                'reward_id' => $reward->id,
                'point_transaction_id' => $tx->id,
                'points_spent' => $reward->points_cost,
                'discount_code' => $shopify['code'],
                'shopify_discount_id' => $shopify['id'],
                'status' => 'issued',
                'expires_at' => now()->addDays($expiresDays),
                'graphql_result' => $shopify['raw'],
            ]);
        });
    }

    public function adjust(Customer $customer, int $points, string $reason): PointTransaction
    {
        return DB::transaction(function () use ($customer, $points, $reason) {
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $next = max(0, $locked->points_balance + $points);
            $delta = $next - $locked->points_balance;
            $locked->points_balance = $next;
            $locked->save();

            return PointTransaction::query()->create([
                'customer_id' => $locked->id,
                'type' => 'adjust',
                'points' => $delta,
                'balance_after' => $locked->points_balance,
                'source' => 'admin',
                'source_id' => null,
                'idempotency_key' => 'adjust:'.$locked->id.':'.Str::uuid(),
                'description' => $reason !== '' ? $reason : 'Manual adjustment',
                'metadata' => [],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function orderId(array $order): string
    {
        $id = data_get($order, 'admin_graphql_api_id', data_get($order, 'id'));

        return $id === null ? '' : (string) $id;
    }

    private function lockCustomer(string $shopDomain, string $email, ?string $shopifyId = null, ?string $name = null): Customer
    {
        $customer = Customer::query()->firstOrCreate(
            ['shop_domain' => $shopDomain, 'email' => strtolower($email)],
            [
                'shopify_customer_id' => $shopifyId ?: null,
                'name' => $name ?: Str::before($email, '@'),
                'points_balance' => 0,
            ],
        );

        if ($shopifyId && ! $customer->shopify_customer_id) {
            $customer->shopify_customer_id = $shopifyId;
        }
        if ($name && ! $customer->name) {
            $customer->name = $name;
        }
        $customer->save();

        return Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
    }
}
