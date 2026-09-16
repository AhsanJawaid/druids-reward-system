<?php

namespace App\Services\Rewards;

use App\Exceptions\IneligibleProductException;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\ShopifyGraphQLException;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\ProgramSetting;
use App\Models\Redemption;
use App\Models\Reward;
use App\Services\Shopify\ShopifyGraphqlClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RewardsService
{
    public function __construct(private ShopifyGraphqlClient $shopify) {}

    /**
     * Award purchase points from a real Shopify order webhook.
     * 2 points per £1 on the amount paid after discounts. Held 14 days.
     *
     * @param  array<string, mixed>  $order
     */
    public function earnFromOrder(array $order, string $shopDomain): ?PointTransaction
    {
        $orderId = $this->orderId($order);
        $email = $this->emailFrom($order);

        if ($orderId === '' || $email === '') {
            return null;
        }

        $status = strtolower((string) data_get(
            $order,
            'financial_status',
            data_get($order, 'displayFinancialStatus', data_get($order, 'display_financial_status', ''))
        ));
        if ($status === '') {
            $status = data_get($order, 'fullyPaid') === true ? 'paid' : 'pending';
        }
        $paidStatuses = ['paid', 'partially_paid', 'partially_refunded'];
        if (! in_array($status, $paidStatuses, true)) {
            return null;
        }

        $settings = ProgramSetting::current();
        $paidAfterDiscounts = $this->amountPaidAfterDiscounts($order);

        if ($paidAfterDiscounts < (float) $settings['min_order_amount']) {
            return null;
        }

        $rate = (float) $settings['points_per_pound'];
        $points = (int) floor($paidAfterDiscounts * $rate);
        if ($points <= 0) {
            return null;
        }

        $holdDays = (int) $settings['hold_days'];
        $availableAt = now()->addDays($holdDays);
        $key = 'shopify:order:'.$orderId.':earn';

        return DB::transaction(function () use ($order, $shopDomain, $email, $orderId, $points, $key, $paidAfterDiscounts, $availableAt, $holdDays) {
            if ($existing = PointTransaction::query()->where('idempotency_key', $key)->first()) {
                return $existing;
            }

            $customer = $this->lockCustomer(
                $shopDomain,
                $email,
                $this->customerShopifyId($order),
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
                'description' => 'Purchase · 2 pts per £1 after discounts · held '.$holdDays.' days · order '.data_get($order, 'name', $orderId),
                'metadata' => [
                    'amount_paid_after_discounts' => $paidAfterDiscounts,
                    'order_name' => data_get($order, 'name'),
                    'currency' => data_get($order, 'currency', data_get($order, 'presentment_currency', 'GBP')),
                    'hold_days' => $holdDays,
                ],
                'available_at' => $availableAt,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $refund
     */
    public function refundOrder(array $refund, string $shopDomain): ?PointTransaction
    {
        $refundId = (string) (data_get($refund, 'id') ?? '');
        $orderId = (string) (data_get($refund, 'order_id') ?? data_get($refund, 'order.id') ?? '');
        $email = $this->emailFrom($refund);

        if ($refundId === '' || $email === '') {
            return null;
        }

        $settings = ProgramSetting::current();
        $rate = (float) $settings['points_per_pound'];
        $amount = (float) data_get($refund, 'transactions.0.amount', data_get($refund, 'refund_line_items.0.subtotal', data_get($refund, 'amount', 0)));
        $points = (int) floor($amount * $rate);

        if ($points <= 0) {
            $points = 0;
            foreach ((array) data_get($refund, 'refund_line_items', []) as $item) {
                $points += (int) floor(((float) data_get($item, 'subtotal', 0)) * $rate);
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

            $customer = $this->lockCustomer($shopDomain, $email, $this->customerShopifyId($refund));
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
                'available_at' => null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<PointTransaction>
     */
    public function processCustomerWebhook(array $payload, string $shopDomain, string $topic): array
    {
        $email = $this->emailFrom($payload);
        if ($email === '') {
            return [];
        }

        $issued = [];

        if ($topic === 'customers/create') {
            $tx = $this->awardAccountCreated($payload, $shopDomain);
            if ($tx) {
                $issued[] = $tx;
            }
        }

        $newsletter = $this->awardNewsletterOptIn($payload, $shopDomain);
        if ($newsletter) {
            $issued[] = $newsletter;
        }

        $birthday = $this->awardBirthdayIfDue($payload, $shopDomain);
        if ($birthday) {
            $issued[] = $birthday;
        }

        return $issued;
    }

    /**
     * Pull recent Shopify customers and paid orders when webhooks never arrived.
     *
     * @return array{customers: int, orders: int, points: int}
     */
    public function syncLiveShopify(string $shopDomain): array
    {
        $customers = $this->shopify->recentCustomers();
        $orders = $this->shopify->recentOrders();
        $customerHits = 0;
        $orderHits = 0;
        $points = 0;

        foreach ($customers as $node) {
            $payload = [
                'id' => data_get($node, 'id'),
                'admin_graphql_api_id' => data_get($node, 'id'),
                'email' => data_get($node, 'email'),
                'first_name' => data_get($node, 'firstName'),
                'tags' => is_array(data_get($node, 'tags')) ? implode(',', (array) data_get($node, 'tags')) : data_get($node, 'tags'),
                'email_marketing_consent' => [
                    'state' => strtolower((string) data_get($node, 'emailMarketingConsent.marketingState', '')),
                ],
                'birthday' => data_get($node, 'metafield.value'),
            ];
            $before = PointTransaction::query()->count();
            $this->processCustomerWebhook($payload, $shopDomain, 'customers/create');
            $this->awardBirthdayIfDue($payload, $shopDomain);
            if (PointTransaction::query()->count() > $before) {
                $customerHits++;
            }
        }

        foreach ($orders as $node) {
            $payload = $this->shopify->graphqlOrderToWebhookPayload($node);
            $before = PointTransaction::query()->sum('points');
            $this->awardAccountCreated($this->emailNamePayload($payload), $shopDomain);
            $tx = $this->earnFromOrder($payload, $shopDomain);
            $this->awardBirthdayIfDue($this->emailNamePayload($payload), $shopDomain);
            if ($tx && $tx->wasRecentlyCreated) {
                $orderHits++;
            }
            $points += (int) PointTransaction::query()->sum('points') - (int) $before;
        }

        return [
            'customers' => $customerHits,
            'orders' => $orderHits,
            'points' => (int) $points,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function emailNamePayload(array $payload): array
    {
        return [
            'email' => $this->emailFrom($payload),
            'id' => $this->customerShopifyId($payload),
            'admin_graphql_api_id' => $this->customerShopifyId($payload),
            'first_name' => (string) data_get($payload, 'customer.first_name', data_get($payload, 'first_name', '')),
            'birthday' => data_get($payload, 'birthday'),
            'tags' => data_get($payload, 'tags', data_get($payload, 'customer.tags')),
            'email_marketing_consent' => data_get($payload, 'email_marketing_consent', data_get($payload, 'customer.email_marketing_consent')),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function awardAccountCreated(array $payload, string $shopDomain): ?PointTransaction
    {
        $email = $this->emailFrom($payload);
        if ($email === '') {
            return null;
        }

        $shopifyId = $this->customerShopifyId($payload);
        $key = 'shopify:customer:'.($shopifyId ?: $email).':account';

        return DB::transaction(function () use ($payload, $shopDomain, $email, $shopifyId, $key) {
            if ($existing = PointTransaction::query()->where('idempotency_key', $key)->first()) {
                return $existing;
            }

            $customer = $this->lockCustomer(
                $shopDomain,
                $email,
                $shopifyId,
                (string) data_get($payload, 'first_name', data_get($payload, 'customer.first_name', '')),
            );

            if ($customer->account_bonus_awarded) {
                return null;
            }

            return $this->creditImmediate(
                $customer,
                200,
                'account_create',
                $shopifyId ?: $email,
                $key,
                'Account created · 200 points (one-time)',
                ['topic' => 'customers/create'],
            );
        });
    }

    /**
     * Award 100 points only on a genuine marketing opt-in, once per customer.
     *
     * @param  array<string, mixed>  $payload
     */
    public function awardNewsletterOptIn(array $payload, string $shopDomain): ?PointTransaction
    {
        try {
            $payload = $this->enrichMarketingFromShopify($payload);
        } catch (\Throwable) {
            // Still award from the webhook body if Shopify GraphQL is unreachable.
        }

        if (! $this->isGenuineMarketingOptIn($payload)) {
            return null;
        }

        $email = $this->emailFrom($payload);
        if ($email === '') {
            return null;
        }

        $shopifyId = $this->customerShopifyId($payload);
        $key = 'shopify:customer:'.$email.':newsletter';

        return DB::transaction(function () use ($payload, $shopDomain, $email, $shopifyId, $key) {
            if ($existing = PointTransaction::query()->where('idempotency_key', $key)->first()) {
                return $existing;
            }

            $customer = $this->lockCustomer($shopDomain, $email, $shopifyId, (string) data_get($payload, 'first_name', ''));

            if ($customer->newsletter_bonus_awarded) {
                return null;
            }

            return $this->creditImmediate(
                $customer,
                100,
                'newsletter',
                $shopifyId ?: $email,
                $key,
                'Newsletter signup · 100 points (one-time, genuine opt-in)',
                ['accepts_marketing' => true],
            );
        });
    }

    /**
     * Pull Shopify email marketing / newsletter tags, then award 100 points if subscribed.
     */
    public function refreshNewsletterFromShopify(string $shopDomain, string $email, ?string $customerGid = null): ?PointTransaction
    {
        return $this->syncShopifyCustomerBonuses($shopDomain, $email, $customerGid)['newsletter'];
    }

    /**
     * @return array{newsletter: ?PointTransaction, birthday: ?PointTransaction}
     */
    public function syncShopifyCustomerBonuses(string $shopDomain, string $email, ?string $customerGid = null): array
    {
        $email = strtolower($email);
        $found = $this->shopify->findCustomerProfile($email, $customerGid);
        $payload = [
            'email' => $found['email'] ?: $email,
            'admin_graphql_api_id' => $found['id'],
            'id' => $found['id'],
            'tags' => $found['tags'],
            'birthday' => $found['birthday'],
            'email_marketing_consent' => ['state' => $found['marketing_state']],
        ];

        return [
            'newsletter' => $this->awardNewsletterOptIn($payload, $shopDomain),
            'birthday' => $this->awardBirthdayIfDue($payload, $shopDomain),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichMarketingFromShopify(array $payload): array
    {
        if ($this->isGenuineMarketingOptIn($payload)) {
            return $payload;
        }

        $found = $this->shopify->findCustomerProfile($this->emailFrom($payload), $this->customerShopifyId($payload));
        if ($found['marketing_state']) {
            $payload['email_marketing_consent'] = array_merge(
                (array) data_get($payload, 'email_marketing_consent', []),
                ['state' => $found['marketing_state']],
            );
        }
        if ($found['tags']) {
            $existingTags = (string) data_get($payload, 'tags', '');
            $payload['tags'] = trim($existingTags === '' ? $found['tags'] : $existingTags.','.$found['tags'], ',');
        }
        if ($found['id']) {
            $payload['admin_graphql_api_id'] = $found['id'];
        }

        return $payload;
    }

    /**
     * Customer provides a birthday. Stored on the ledger customer, copied to
     * Shopify custom.birthday when live, and 250 points post if today matches.
     */
    public function saveProvidedBirthday(string $shopDomain, string $email, string $ymd, ?string $name = null): ?PointTransaction
    {
        $email = strtolower($email);
        $payload = [
            'email' => $email,
            'birthday' => $ymd,
            'first_name' => $name ?: '',
        ];

        $tx = $this->awardBirthdayIfDue($payload, $shopDomain);

        $customer = Customer::query()->where('shop_domain', $shopDomain)->where('email', $email)->first();
        if ($customer) {
            $this->pushBirthdayToShopify($customer);
        }

        return $tx;
    }

    /**
     * Pull custom.birthday from Shopify Admin API, then award if today is the birthday.
     */
    public function refreshBirthdayFromShopify(string $shopDomain, string $email, ?string $customerGid = null): ?PointTransaction
    {
        $email = strtolower($email);
        $found = $this->shopify->findCustomerBirthday($email, $customerGid);
        $payload = [
            'email' => $found['email'] ?: $email,
            'admin_graphql_api_id' => $found['id'],
            'id' => $found['id'],
            'birthday' => $found['birthday'],
        ];

        if (! $found['birthday']) {
            $existing = Customer::query()->where('shop_domain', $shopDomain)->where('email', $email)->first();
            if ($existing?->birthday) {
                $payload['birthday'] = $existing->birthday->toDateString();
            } else {
                return null;
            }
        }

        return $this->awardBirthdayIfDue($payload, $shopDomain);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichBirthdayFromShopify(array $payload): array
    {
        if ($this->extractBirthday($payload)) {
            return $payload;
        }

        $found = $this->shopify->findCustomerBirthday($this->emailFrom($payload), $this->customerShopifyId($payload));
        if ($found['birthday']) {
            $payload['birthday'] = $found['birthday'];
        }
        if ($found['id']) {
            $payload['admin_graphql_api_id'] = $found['id'];
        }

        return $payload;
    }

    private function pushBirthdayToShopify(Customer $customer): void
    {
        if (! $customer->birthday) {
            return;
        }

        $gid = $customer->shopify_customer_id;
        if (! $gid || ! str_starts_with((string) $gid, 'gid://')) {
            $found = $this->shopify->findCustomerBirthday($customer->email, is_string($gid) ? $gid : null);
            $gid = $found['id'];
            if ($gid && ! $customer->shopify_customer_id) {
                $customer->shopify_customer_id = $gid;
                $customer->save();
            }
        }

        if (! is_string($gid) || $gid === '') {
            return;
        }

        try {
            $this->shopify->writeCustomerBirthday($gid, $customer->birthday->toDateString());
        } catch (\Throwable) {
            // Local ledger still holds the date even if Shopify metafield write is denied.
        }
    }

    /**
     * 250 points once per calendar year when the stored Shopify birthday matches today.
     *
     * @param  array<string, mixed>  $payload
     */
    public function awardBirthdayIfDue(array $payload, string $shopDomain): ?PointTransaction
    {
        $email = $this->emailFrom($payload);
        if ($email === '') {
            return null;
        }

        try {
            $payload = $this->enrichBirthdayFromShopify($payload);
        } catch (\Throwable) {
            // Birthday still awards from the local ledger / portal date if Shopify is unreachable.
        }

        $birthday = $this->extractBirthday($payload);

        return DB::transaction(function () use ($payload, $shopDomain, $email, $birthday) {
            $customer = $this->lockCustomer(
                $shopDomain,
                $email,
                $this->customerShopifyId($payload),
                (string) data_get($payload, 'first_name', ''),
            );

            if ($birthday) {
                $customer->birthday = $birthday;
                $customer->save();
            }

            $date = $customer->birthday;
            if (! $date) {
                return null;
            }

            $today = now();
            if ((int) $date->format('m') !== (int) $today->format('m') || (int) $date->format('d') !== (int) $today->format('d')) {
                return null;
            }

            $year = (int) $today->format('Y');
            $key = 'shopify:customer:'.$customer->id.':birthday:'.$year;
            if ($existing = PointTransaction::query()->where('idempotency_key', $key)->first()) {
                return $existing;
            }
            if ((int) $customer->last_birthday_reward_year === $year) {
                return null;
            }

            $tx = $this->creditImmediate(
                $customer,
                250,
                'birthday',
                (string) $year,
                $key,
                'Birthday · 250 points ('.$year.')',
                ['birthday' => $date->toDateString()],
            );

            $customer->last_birthday_reward_year = $year;
            $customer->save();

            return $tx;
        });
    }

    public function redeem(Customer $customer, Reward $reward, ?string $productId = null): Redemption
    {
        if (! $reward->active) {
            throw new \InvalidArgumentException('This reward is not available.');
        }

        $customer->refresh();
        if ($customer->spendablePoints() < $reward->points_cost) {
            throw new InsufficientPointsException('Not enough spendable points. Purchase points are held for 14 days.');
        }

        if ($this->shopify->shouldMock() && ! app()->environment('testing')) {
            throw new ShopifyGraphQLException('Turn on “Use the live Shopify API” and save a real Admin API token before redeeming. Demo codes are not accepted for this assessment.');
        }

        $productGid = $productId ? $this->normalizeProductGid($productId) : null;

        if ($reward->slug === 'free_product') {
            if (! $productGid) {
                throw new IneligibleProductException('Choose an eligible free-product item.');
            }
            $collectionId = (string) ProgramSetting::current()['free_product_collection_id'];
            if ($collectionId === '') {
                throw new IneligibleProductException('The eligible free-product collection is not configured.');
            }
            if (! $this->shopify->productBelongsToCollection($productGid, $collectionId)) {
                throw new IneligibleProductException('That product is not in the eligible free-product collection.');
            }
        }

        $settings = ProgramSetting::current();
        $code = 'RWD-'.strtoupper(Str::random(8));
        $expiresDays = (int) $settings['discount_expiry_days'];

        $shopify = $this->shopify->issueReward($reward, $code, $expiresDays, $productGid);

        return DB::transaction(function () use ($customer, $reward, $shopify, $expiresDays, $productGid) {
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if ($locked->spendablePoints() < $reward->points_cost) {
                throw new InsufficientPointsException('Not enough spendable points. Purchase points are held for 14 days.');
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
                    'code' => $shopify['code'],
                    'shopify_id' => $shopify['id'],
                    'object_type' => $shopify['object_type'],
                    'mocked' => $shopify['mocked'],
                    'product_gid' => $productGid,
                ],
                'available_at' => null,
            ]);

            return Redemption::query()->create([
                'customer_id' => $locked->id,
                'reward_id' => $reward->id,
                'point_transaction_id' => $tx->id,
                'points_spent' => $reward->points_cost,
                'discount_code' => $shopify['code'],
                'shopify_discount_id' => $shopify['id'],
                'shopify_object_type' => $shopify['object_type'],
                'product_gid' => $productGid,
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
                'available_at' => null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $order
     */
    public function amountPaidAfterDiscounts(array $order): float
    {
        $subtotal = data_get($order, 'current_subtotal_price', data_get($order, 'subtotal_price'));
        if ($subtotal !== null && $subtotal !== '') {
            return max(0, (float) $subtotal);
        }

        $line = (float) data_get($order, 'total_line_items_price', 0);
        $discounts = (float) data_get($order, 'total_discounts', 0);

        return max(0, $line - $discounts);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function isGenuineMarketingOptIn(array $payload): bool
    {
        $state = strtolower((string) data_get(
            $payload,
            'email_marketing_consent.state',
            data_get($payload, 'customer.email_marketing_consent.state', '')
        ));

        if (in_array($state, ['unsubscribed', 'invalid'], true)) {
            return false;
        }

        if (in_array($state, ['subscribed', 'pending'], true)) {
            return true;
        }

        if ($this->acceptsMarketing($payload)) {
            return true;
        }

        // Dawn/footer {% form 'customer' %} tags the profile "newsletter" and often
        // leaves email_marketing_consent as not_subscribed until a later confirm.
        return $this->hasNewsletterTag($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hasNewsletterTag(array $payload): bool
    {
        $raw = data_get($payload, 'tags', data_get($payload, 'customer.tags', ''));
        if (is_array($raw)) {
            $raw = implode(',', $raw);
        }
        $tags = array_map('trim', explode(',', strtolower((string) $raw)));

        return in_array('newsletter', $tags, true)
            || in_array('email subscribe', $tags, true)
            || in_array('email-subscribe', $tags, true)
            || in_array('email signup', $tags, true)
            || in_array('email-signup', $tags, true)
            || in_array('shopify email', $tags, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function acceptsMarketing(array $payload): bool
    {
        $accepts = data_get($payload, 'accepts_marketing', data_get($payload, 'customer.accepts_marketing'));

        return $accepts === true || $accepts === 'true' || $accepts === 1 || $accepts === '1';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractBirthday(array $payload): ?Carbon
    {
        $candidates = [
            data_get($payload, 'birthday'),
            data_get($payload, 'date_of_birth'),
            data_get($payload, 'customer.birthday'),
        ];

        foreach ((array) data_get($payload, 'note_attributes', data_get($payload, 'customer.note_attributes', [])) as $attr) {
            $name = strtolower((string) data_get($attr, 'name', data_get($attr, 'key', '')));
            if (in_array($name, ['birthday', 'birthdate', 'date_of_birth', 'dob'], true)) {
                $candidates[] = data_get($attr, 'value');
            }
        }

        foreach ((array) data_get($payload, 'metafields', []) as $field) {
            $key = strtolower((string) data_get($field, 'key', ''));
            $namespace = strtolower((string) data_get($field, 'namespace', ''));
            if ($key === 'birthday' || ($namespace === 'custom' && $key === 'birthday') || str_contains($key, 'birthday') || str_contains($key, 'birth') || $key === 'dob') {
                $candidates[] = data_get($field, 'value');
            }
        }

        $note = (string) data_get($payload, 'note', '');
        if (preg_match('/birthday\s*[:=]\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $note, $match)) {
            $candidates[] = $match[1];
        }

        $tags = (string) data_get($payload, 'tags', '');
        if (preg_match('/birthday:([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $tags, $match)) {
            $candidates[] = $match[1];
        }

        foreach ($candidates as $raw) {
            if (! is_string($raw) && ! is_numeric($raw)) {
                continue;
            }
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            try {
                return Carbon::parse($raw)->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function creditImmediate(
        Customer $customer,
        int $points,
        string $source,
        string $sourceId,
        string $key,
        string $description,
        array $metadata,
    ): PointTransaction {
        $customer->points_balance += $points;
        if ($source === 'account_create') {
            $customer->account_bonus_awarded = true;
        }
        if ($source === 'newsletter') {
            $customer->newsletter_bonus_awarded = true;
        }
        $customer->save();

        return PointTransaction::query()->create([
            'customer_id' => $customer->id,
            'type' => 'earn',
            'points' => $points,
            'balance_after' => $customer->points_balance,
            'source' => $source,
            'source_id' => $sourceId,
            'idempotency_key' => $key,
            'description' => $description,
            'metadata' => $metadata,
            'available_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function orderId(array $order): string
    {
        $id = data_get($order, 'admin_graphql_api_id', data_get($order, 'id'));

        return $id === null ? '' : (string) $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emailFrom(array $payload): string
    {
        return strtolower((string) data_get(
            $payload,
            'email',
            data_get($payload, 'email_address', data_get($payload, 'customer.email', data_get($payload, 'order.email', '')))
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function customerShopifyId(array $payload): ?string
    {
        $id = data_get(
            $payload,
            'customer.admin_graphql_api_id',
            data_get($payload, 'customer_id', data_get($payload, 'customer.id', data_get($payload, 'admin_graphql_api_id')))
        );
        if ($id === null || $id === '') {
            $id = data_get($payload, 'id');
        }
        if ($id === null || $id === '') {
            return null;
        }
        $id = (string) $id;
        if (str_starts_with($id, 'gid://')) {
            return $id;
        }
        if (is_numeric($id)) {
            return 'gid://shopify/Customer/'.$id;
        }

        return $id;
    }

    private function normalizeProductGid(string $id): string
    {
        $id = trim($id);
        if (str_starts_with($id, 'gid://')) {
            return $id;
        }
        if (is_numeric($id)) {
            return 'gid://shopify/Product/'.$id;
        }

        return $id;
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
