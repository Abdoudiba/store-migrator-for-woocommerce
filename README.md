# Shopify to WooCommerce Migrator

A freemium WordPress plugin that migrates a Shopify store into WooCommerce.
Inspired by Cart2Cart / LitExtension, but delivered the way our other plugins
are: a fully-functional free core on WordPress.org, a paid premium add-on sold
from our own site, and a done-for-you migration service run with the same tool.

**Status:** milestone 1 — scaffold only. The wizard is navigable; no data moves yet.

---

## Product shape

| Tier | Source | Brings over |
|------|--------|-------------|
| **Free** (WordPress.org) | Shopify **CSV export** (Products, Customers, Orders) | products + variants (simple/variable), images by URL, tags, product type → category |
| **Premium** (our site, license key) | Shopify **Admin API** (merchant creates a custom app, pastes store domain + token) | collections → categories, customers + addresses, full orders (line items, tax, shipping, discounts, status map), discount codes → coupons, metafields, blog posts + pages, **301 redirect generation**, **incremental re-runs** |

### Deliberate non-goals

- Not a hosted SaaS. No servers, no per-migration billing.
- Not multi-cart. Shopify → WooCommerce only; sibling source adapters can reuse
  the engine later.
- Not competing on breadth with Cart2Cart's 85 carts.

### Hard limits to document, not fight

- **Passwords don't migrate.** Shopify exposes no password hashes via API or
  CSV. Migrated customers reset on first login (premium sends the reset mail).
- Smart-collection *rules* can't be reproduced 1:1 — membership is copied.
- Shopify has no native reviews (they're apps) — out of scope.

---

## Architecture

```
WooCommerce → Shopify Import  (wizard: Connect → Choose data → Analyze → Migrate → Report)
        │
        ▼
STWM_Run            one migration attempt; status draft→analyzing→running→done|failed
        │
        ▼
STWM_Queue          Action Scheduler wrapper. Each batch = one async action with a
                    payload {run_id, entity_type, offset, batch_size}. A processor
                    that isn't done re-enqueues the next offset before returning, so
                    a huge store migrates without a long request and resumes after
                    a timeout.
        │
        ▼
per-entity importers   (milestone 2+) product / category / customer / order / coupon
        │
        ▼
STWM_Migration_Map   custom table wp_stwm_map, keyed by (entity_type, source_id):
                     • idempotent runs — re-import updates, never duplicates
                     • relational stitching — orders resolve product/customer IDs here
                     • rollback — delete every WC object created by a run_id
                     • incremental — skip source IDs already present
```

`STWM_Logger` wraps `wc_get_logger()` (WooCommerce → Status → Logs, source
`stwm`). A dedicated downloadable per-row log table comes later.

HPOS: the plugin declares `custom_order_tables` compatibility and will create
orders only through the `WC_Order` CRUD API.

## File map

| File | Role |
|------|------|
| `shopify-to-woocommerce-migrator.php` | bootstrap, constants, WC guard, activation hooks, HPOS declaration |
| `includes/class-stwm-install.php` | dbDelta schema, activate/deactivate, silent upgrade |
| `includes/class-stwm-migration-map.php` | ID-map CRUD (`get_target`, `set`, `rows_for_run`, `delete_run`, …) |
| `includes/class-stwm-run.php` | migration-run registry (option-backed for now) |
| `includes/class-stwm-queue.php` | Action Scheduler batch pipeline + `handle_batch` dispatch point |
| `includes/class-stwm-logger.php` | logging wrapper |
| `includes/class-stwm-admin.php` | the wizard (menu, steps, nonce'd POST handler) |
| `uninstall.php` | drop table + options on delete |

## Roadmap

1. **Scaffold** — this milestone. ✅
2. **CSV product import** (free core): parse Shopify Products CSV → simple/variable products, images, tags, type→category. First WordPress.org-shippable slice.
3. Rollback UI + dry-run + per-row report screen.
4. **WordPress.org submission** of the free plugin.
5. Premium add-on skeleton + license check (reuse the ysqd approach — no Freemius cut).
6. Shopify Admin API client (REST first, respect the 2 req/s bucket) → products + collections.
7. Customers + orders via API (status mapping: paid+fulfilled → completed, paid+unfulfilled → processing, …).
8. Coupons, 301 redirects, blog posts + pages.
9. Incremental mode ("import orders since last run").
10. Launch premium (~$69 single / $99 3-site / $199 agency) + list the done-for-you service.

## Notes for submission

- **Slug / name.** WordPress.org trademark rules discourage a plugin name that
  leads with "Shopify". Decide the public slug at submission time — likely
  `shopify-to-woocommerce-migrator` survives as descriptive use (cf. VillaTheme's
  "S2W – Import Shopify to WooCommerce"), but have a fallback like
  "Store Importer for WooCommerce".
- Free plugin must be **fully functional** without premium; gate by entity
  *type*, never by row count.
- Prefix everything `stwm_` / `STWM_`; sanitize + escape; no external calls
  without explicit user action (the API token is entered by the merchant).

## Competitive wedge vs VillaTheme S2W

Rock-solid resumable batching on large stores, true incremental re-runs,
first-class 301-redirect export, clean order status mapping, and a paid
done-for-you option attached to the same tool.
