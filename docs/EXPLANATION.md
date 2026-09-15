# Rewards System — brief written explanation

This is a genuine Shopify-connected loyalty slice, not a mock-up.

**Earn.** Shopify delivers `orders/paid`, `refunds/create`, `customers/create`, and `customers/update` to Laravel. Purchase points are `floor(amount_paid_after_discounts × 2)` using Shopify’s subtotal after discounts. Those rows carry `available_at` = now + 14 days. Spendable balance is ledger total minus still-held purchase points. Account (200), newsletter (100, subscribed consent only, one-time flag + idempotency key), and birthday (250, once per calendar year) are awarded from customer webhooks, not from admin buttons.

**Spend.** The catalog is fixed: 500 / 500 / 1,500 / 2,500. Redeem calls Admin GraphQL: `discountCodeBasicCreate` (£5 or 100% off a verified product), `discountCodeFreeShippingCreate`, or `giftCardCreate` (£25). Codes invented locally are not used as the source of truth; Shopify’s returned code and gid are stored on the redemption.

**Audit.** `points_balance` is only a cache of the ledger. Customer history lists every earn, refund, redeem, and correction with running `balance_after`. Duplicate webhooks reuse `idempotency_key`.

**Free product.** Before any discount is created, the server queries Shopify `product.inCollection` (in demo mode it still refuses ids containing `ineligible`) against the configured collection id.

**Sandbox.** Use a Shopify development store and a custom app. Custom-app tokens often cannot register webhooks via GraphQL; create the four topics in Admin Notifications and point them at `/api/webhooks/shopify`.
