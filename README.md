# Atelier Rewards (Shopify + Laravel)

A **scoped** loyalty slice: Laravel owns the points ledger; Shopify remains the store and the place a discount code is spent. This is not a Smile/Yotpo clone (no tiers, referrals, or POS). It is enough to earn on paid orders, reverse on refunds, redeem for a single-use Admin GraphQL discount, and inspect the integration.

The attached assessment PDF was not available in this environment. The build follows the brief you gave (Shopify store + PHP Laravel + Admin API GraphQL) plus the usual assessment bar for this kind of task: idempotent webhooks, HMAC verification, an append-only ledger, and a redeem path that calls `discountCodeBasicCreate`.

## What it does

| Surface | Behavior |
| --- | --- |
| Storefront (`/`) | Demo shop + rewards card. Paying an item posts an `orders/paid`-shaped payload into the same earn service the webhook uses. |
| Admin (`/admin`) | Balances, rewards, customers, Shopify connection. |
| `POST /api/webhooks/shopify` | HMAC-checked Shopify webhooks. Handles `orders/paid`, `orders/create`, `refunds/create`. |
| `GET /api/rewards/customer?email=` | JSON for a theme app block / customer account widget. |
| `POST /api/rewards/redeem` | JSON redeem → GraphQL discount code. |

### Earn

- Configurable **points per dollar** on **subtotal** (shipping/tax ignored).
- Optional **minimum order amount**.
- Idempotent on Shopify order id (`shopify:order:{id}:earn`).
- Skips voided/refunded financial status.

### Redeem

1. Balance check.
2. Admin GraphQL mutation `discountCodeBasicCreate` (amount off or percent, usage limit 1, 30-day expiry by default).
3. Deduct points in a DB transaction and store the code.

If `SHOPIFY_ACCESS_TOKEN` is empty (or `SHOPIFY_MOCK=true`), the **same GraphQL document and variables** are logged and a mock `gid://shopify/DiscountCodeNode/...` is returned so the slice is runnable without a Partner app.

## Run locally

PHP 8.3+, Composer, SQLite.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=43123
```

Open [http://127.0.0.1:43123](http://127.0.0.1:43123). Seeded member: `maya@atelier.example` (180 pts).

```bash
php artisan test
```

## Instruction manual

Download from the repo:

- `docs/Atelier-Rewards-Instruction-Manual.pdf`
- `docs/Atelier-Rewards-Instruction-Manual.docx`

## GraphQL (no extra account)

You do **not** register for GraphQL. It is Shopify’s Admin API. A custom app token on your shop is enough.

## Connect a real Shopify shop

Use **Admin → Shopify** (`/admin/settings`). You can paste credentials there (encrypted in the database) or put them in `.env`.

1. Shopify Admin → **Settings → Apps and sales channels → Develop apps → Create an app**.
2. Admin API scopes: `read_orders`, `read_customers`, `write_discounts`.
3. Install the app. Copy the **Admin API access token** (`shpat_…`) and the **API secret key** (webhook HMAC).
4. Shop domain must be `your-store.myshopify.com`.
5. This app must be reachable on **HTTPS**. Locally: `ngrok http 43123`, then paste `https://….ngrok-free.app` as the public app URL.
6. Save connection → **Test GraphQL connection** → **Register orders/paid + refunds/create**.
7. Place a paid order on the store (use the same customer email you look up on `/`). Points post from the webhook. Redeem on `/` to create a real discount code via `discountCodeBasicCreate`.

Optional `.env` instead of the form:

```
SHOPIFY_STORE_DOMAIN=your-shop.myshopify.com
SHOPIFY_API_VERSION=2025-01
SHOPIFY_ACCESS_TOKEN=shpat_...
SHOPIFY_WEBHOOK_SECRET=...
SHOPIFY_MOCK=false
```

HMAC: `X-Shopify-Hmac-Sha256` = `base64(hmac_sha256(raw_body, api_secret))`. Production should always set the secret.

## Git repository

This project is already committed on the working branch in this Cloud Agent session. It is **not** yet pushed to a GitHub/GitLab repo of yours until you share that remote.

Send any of:

- the GitHub (or GitLab) HTTPS URL, e.g. `https://github.com/you/rewards.git`
- or create the GitHub repo from Cursor’s **Create repo** control, then tell me it exists

I will add the remote and push `main` (I will not force-push unless you ask). Do not commit Admin API tokens. `.env` stays local; `.env.example` has empty Shopify keys.

## Layout

- `app/Services/Rewards/RewardsService.php` — earn / refund / redeem / adjust
- `app/Services/Shopify/ShopifyGraphqlClient.php` — Admin GraphQL client + mock
- `app/Http/Middleware/VerifyShopifyWebhook.php` — HMAC
- `database/migrations/*` — customers, point_transactions, rewards, redemptions, webhook + GraphQL logs

Out of scope on purpose: Shopify OAuth install flow, billing, multi-store tenancy, checkout UI extensions, and point expiry jobs.
