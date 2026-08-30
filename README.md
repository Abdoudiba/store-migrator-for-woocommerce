# Store Migrator for WooCommerce

A freemium WordPress plugin that migrates a Shopify store into WooCommerce.
Inspired by Cart2Cart / LitExtension, but delivered the way our other plugins
are: a fully-functional free core on WordPress.org, a paid premium add-on sold
from our own site, and a done-for-you migration service run with the same tool.

**Status:** milestone 4 — WP.org submission prep. CSV product import + dry-run pre-flight + own per-run log + crash-safe map writes, validated end to end on a live WooCommerce test site. `vendor/bin/phpcs` clean; Plugin Check clean after the rename to a "… for WooCommerce"-pattern name. Remaining before submit: assets (icon/banner/screenshots), drop `-dev` → `0.3.0`. API/premium tiers = M5+.

The plugin's public identity is **Store Migrator for WooCommerce** / slug `store-migrator-for-woocommerce`. The internal code prefix stays `stwm` / `STWM` (unique, 4+ chars — all WP.org requires; matching it to the slug would churn 10 classes, 2 tables, and every option/hook for no review benefit).

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

`STWM_Log` writes every run event to the plugin's own `wp_stwm_log` table
(shown on the report screen, downloadable as CSV) and mirrors each line to
`wc_get_logger()` for stores that have WooCommerce logging enabled — the table
is the source of truth because many stores keep WC logging off.

`STWM_CSV::build_index()` also does the dry-run pre-flight: duplicate SKUs,
unparseable prices, title-less rows, missing columns, and a bounded HTTP check
of image URLs, stored on the run as `problems` and rendered on the Analyze
step. Error-level findings block the import.

HPOS: the plugin declares `custom_order_tables` compatibility and will create
orders only through the `WC_Order` CRUD API.

## File map

| File | Role |
|------|------|
| `store-migrator-for-woocommerce.php` | bootstrap, constants, WC guard, activation hooks, HPOS declaration |
| `includes/class-stwm-install.php` | dbDelta schema, activate/deactivate, silent upgrade |
| `includes/class-stwm-migration-map.php` | ID-map CRUD (`get_target`, `set`, `rows_for_run`, `delete_run`, …) |
| `includes/class-stwm-run.php` | migration-run registry (option-backed for now) |
| `includes/class-stwm-queue.php` | Action Scheduler batch pipeline; `handle_batch` dispatches `index` → `STWM_CSV`, `product` → `STWM_Product_Importer` |
| `includes/class-stwm-csv.php` | reads the Shopify Products CSV; `build_index()` writes `products.index.json` (byte offset per Handle), the analyze counts, and the dry-run `problems` list |
| `includes/class-stwm-product-importer.php` | the `product` batch processor: CSV slice → simple/variable products, variants, images; re-enqueues the next slice |
| `includes/stwm-helpers.php` | `stwm_col()`, `stwm_parse_price()`, `stwm_weight_from_grams()`, per-run upload dir, recursive rmdir |
| `includes/class-stwm-logger.php` | thin `wc_get_logger()` wrapper (non-run-scoped lines) |
| `includes/class-stwm-log.php` | per-run log — `wp_stwm_log` table, counts, CSV export |
| `includes/class-stwm-admin.php` | the wizard (upload, analyze + pre-flight + options, run, report + log, rollback, CSV export) |
| `uninstall.php` | drop both tables + options + `uploads/stwm/` on delete |

## Roadmap

1. **Scaffold** — bootstrap, queue, ID map, wizard shell. ✅
2. **CSV product import** (free core): Shopify Products CSV → simple/variable products, variants, images, tags, type→category; batched, resumable, with rollback and a live report. ✅
3. Dry-run pre-flight (dup SKUs, unreachable images, malformed rows), own per-run log table + CSV export, crash-safe map writes. ✅
4. **WordPress.org submission prep**: WPCS pass (clean), `.pot`, readme polish, Plugin Check (clean), rename to `store-migrator-for-woocommerce`. ✅ — remaining: icon/banner/screenshots, version `0.3.0`.
5. Premium add-on skeleton + license check (reuse the ysqd approach — no Freemius cut).
6. Shopify Admin API client (REST first, respect the 2 req/s bucket) → products + collections.
7. Customers + orders via API (status mapping: paid+fulfilled → completed, paid+unfulfilled → processing, …).
8. Coupons, 301 redirects, blog posts + pages.
9. Incremental mode ("import orders since last run").
10. Launch premium (~$69 single / $99 3-site / $199 agency) + list the done-for-you service.

## Known limitations

- **No product ID in the CSV**, so the source key is the `Handle`. Two Shopify
  products that somehow share a handle would collide; a genuine export can't.
- Rows for one handle are assumed **contiguous** (always true in a real Shopify
  export; hand-sorted files could break the byte-offset index).
- Re-running leaves **orphaned variations** if a variant was removed from the
  CSV between runs — they aren't pruned yet.
- Categories come only from the `Type` column. **Collections** need the Admin
  API (premium).
- Image sideload is synchronous inside the batch; on slow hosts, lower
  "Products per batch". A failed Action Scheduler batch is retried, and the
  import is idempotent, so retries are safe.
- `Cost per item` and `Variant Barcode` (when the WooCommerce GTIN field is
  absent) are stored as `_stwm_*` post meta, not surfaced in the UI.

## Notes for submission

- **Slug / name — settled.** `store-migrator-for-woocommerce` / "Store Migrator
  for WooCommerce". WP.org's Plugin Check only permits "woocommerce" in a name
  or slug in the patterns "for/with/using/and woocommerce", which rules out the
  original "X to WooCommerce" form; "Shopify" is a third-party mark so it can't
  lead either. It stays prominent in the readme title line, short description
  and tags for search.
- Free plugin must be **fully functional** without premium; gate by entity
  *type*, never by row count.
- Prefix everything `stwm_` / `STWM_`; sanitize + escape; no external calls
  without explicit user action (the API token is entered by the merchant).

## Competitive wedge vs VillaTheme S2W

Rock-solid resumable batching on large stores, true incremental re-runs,
first-class 301-redirect export, clean order status mapping, and a paid
done-for-you option attached to the same tool.
