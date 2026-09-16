# Rewards System (Shopify + Laravel)

A working points-based loyalty program for a Shopify development store. Laravel owns an append-only points ledger. Shopify remains the shop and the place a discount code or gift card is spent. There is no third-party loyalty app.

## Spec

### Earn (Shopify webhooks only)

| Action | Points | Conditions |
| --- | --- | --- |
| Make a purchase | 2 per £1 | Amount actually paid after discounts. 14-day hold before spendable. |
| Create an account | 200 | One-time, `customers/create`. |
| Newsletter signup | 100 | One-time, genuine Shopify opt-in. New emails on the theme footer form get tag `newsletter`. Existing customers: set Email marketing to Subscribed on the Shopify customer (or opt in at checkout), then look up the email on the Portal — the app reads consent with GraphQL because the footer form often does not update an existing profile. |
| Birthday | 250 | Once per calendar year, from a date the customer provides. **Do not use Checkout Profile “Add block”.** Save the date on the Rewards Portal or Admin customer page. Shopify webhooks never include `custom.birthday`; this app reads that metafield with GraphQL on lookup, and also accepts a customer note/tag `birthday:YYYY-MM-DD`. |

### Spend (100 points = £1)

| Reward | Points | Shopify object |
| --- | --- | --- |
| Money off (small) | 500 | `discountCodeBasicCreate` — £5 off |
| Free shipping | 500 | `discountCodeFreeShippingCreate` |
| Free product | 1,500 | `discountCodeBasicCreate` 100% off one item after **server-side** collection check |
| Gift card | 2,500 | `giftCardCreate` — £25 |

Balances are never trusted as a lone number: every change is a `point_transactions` row (`balance_after`, source, Shopify id, hold timestamp).

## Run locally

PHP 8.3+, Composer, SQLite.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=43123
php artisan test
```

If `php artisan test` says the command is not defined, Composer was installed without dev packages (`--no-dev`). That is normal on Hostinger. **Do not run tests on the live server** — they wipe database tables. On your computer:

```bash
cd /path/to/the-folder-that-contains-artisan
composer install
php artisan test
# or
./vendor/bin/phpunit
```

Do not run the command from the `public` folder.

- Portal: `/`
- Admin reports: `/admin`
- Rewards catalog (spec tables): `/admin/rewards`
- Shopify connection: `/admin/settings`
- Webhook: `POST /api/webhooks/shopify`

## Connect a Shopify development store

1. Create a custom app **inside this Shopify store** (Settings → Apps → Develop apps) with scopes: `read_orders`, `read_customers`, `write_customers`, `read_products`, `write_discounts`, `write_gift_cards`.
   Or, if the app was created in the Shopify Dev Dashboard / CLI, paste **Client ID + Client secret** on Admin → Shopify settings instead of a `shpat_` token (those tokens expire).
2. Install it. Copy the Admin API token and API secret key.
3. On `/admin/settings`, paste `your-store.myshopify.com`, the token, the secret, and this app’s public HTTPS URL. Enable live mode.
4. Add Notifications / app webhooks (custom apps often cannot create them via GraphQL):
   - Order payment (`orders/paid`)
   - Refund create (`refunds/create`)
   - Customer creation (`customers/create`)
   - Customer update (`customers/update`)
   - URL: `https://your-host/api/webhooks/shopify`
5. Paste the **eligible free-product collection** ID (GraphQL gid or numeric) so free-product redemption can be verified.
6. **Mark the order as paid.** Fulfillment of an unpaid order does not award points. Purchase points become spendable after 14 days. Account / newsletter / birthday points are spendable immediately.

Optional `.env`:

```
SHOPIFY_STORE_DOMAIN=your-shop.myshopify.com
SHOPIFY_API_VERSION=2026-07
SHOPIFY_ACCESS_TOKEN=shpat_...
SHOPIFY_WEBHOOK_SECRET=...
SHOPIFY_MOCK=false
```

## Birthday (easiest working path)

Shopify will not show `custom.birthday` on the new customer Profile unless you build a Customer Account UI extension. The Shopify CLI app you created is not that extension, and “Add block” staying empty is expected. Saving the metafield in Admin also does nothing by itself: `customers/update` payloads do not include metafields.

**Award 250 points today**

1. Upload this build and click **Update database** on Settings if the live site 500s.
2. Open the Rewards Portal (`/`).
3. Enter the customer’s Shopify email and today’s date under **Your birthday**.
4. Click **Save birthday**. The ledger shows `Birthday · 250 points`.

Same action exists on Admin → that customer. Looking up the email also pulls `custom.birthday` via GraphQL if you already stored it in Shopify.

Optional daily cron: `php artisan rewards:award-birthdays`.

## Walkthrough

1. Create a customer on the shop (200 points) and optionally subscribe to email marketing (100 points).
2. Place an order, apply a discount if you want, **mark as paid**. Ledger shows 2 × £ paid after discounts, with `available_at` +14 days.
3. After hold (or using spendable account points), redeem **Money off (small)** on `/`. Copy the Shopify discount code into checkout.

## Brief explanation

See `docs/EXPLANATION.md`.

## Operator manual

A slide-style user manual (screenshots of the Portal and Admin) is in:

- `docs/Rewards-System-User-Manual.pdf`
- `docs/Rewards-System-User-Manual.pptx`

## Hostinger 500 after upload

Uploading PHP files does not alter MySQL. Portal / Reports / Rewards query new columns (`slug`, `available_at`, birthday flags, gift-card fields). Settings does not, so it stays up.

After this build is on the server, open Settings and click **Update database**, or run `docs/fix-database.sql` in phpMyAdmin, or `php artisan migrate --force` over SSH. Then reload the other pages.
