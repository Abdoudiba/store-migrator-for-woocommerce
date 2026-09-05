=== Yuupee Store Migrator for Shopify ===
Contributors: abdoudiba
Tags: shopify, woocommerce, migration, import, csv importer
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.0
WC tested up to: 10.0
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate a Shopify store into WooCommerce — products, variants and images from a CSV export, batched and resumable, with a full rollback.

== Description ==

Yuupee Store Migrator for Shopify brings a Shopify catalogue into WooCommerce
without a hosted middleman. You export the standard CSV files from your Shopify
admin, upload them here, and the plugin creates the matching WooCommerce
products.

Every object it creates is recorded in an internal ID map, so:

* re-running an import **updates** products instead of duplicating them;
* a one-click **rollback** removes everything a run created;
* a later premium tier can do **incremental** re-runs (only new orders, etc.).

Large catalogues are processed in the background with Action Scheduler (the
same queue WooCommerce itself uses), so a 20,000-product store migrates
without a long-running request and picks up where it left off if interrupted.

= Free =

* Products and variants from the Shopify **Products CSV** (simple and variable).
* Product images by URL, product type as category, tags.
* Background batching, ID map, dry-run, rollback, per-row log.

= Premium (sold separately) =

* Live **Shopify Admin API** connection — no CSV juggling.
* Collections to product categories, customers and addresses, full orders,
  discount codes to coupons, blog posts and pages.
* **301 redirect** generation for the old Shopify URLs.
* **Incremental** re-runs.

= Known limits =

* Shopify never exports password hashes (no API, no CSV), so migrated
  customers set a new password on first login.
* Smart-collection rules can't be reproduced one-to-one; membership is copied.
* Shopify has no native product reviews (they come from apps), so reviews are
  out of scope.

== Installation ==

1. Install and activate WooCommerce.
2. Upload this plugin to `/wp-content/plugins/` or via Plugins → Add New → Upload, and activate.
3. In your Shopify admin, export Products (and, with premium, connect the Admin API).
4. Go to WooCommerce → Shopify Import and follow the wizard.

== Frequently Asked Questions ==

= Do I need a paid Shopify plan? =

No. The free tier works entirely from the CSV files Shopify lets every plan
export. The premium Admin API connection needs a custom app in your Shopify
admin, which any plan can create.

= Can I undo an import? =

Yes. Each run is tagged, and the wizard's rollback deletes every WooCommerce
object that run created.

= Will it duplicate products if I run it twice? =

No. Matching is by the Shopify source ID (then by SKU as a fallback), so a
second run updates the existing product.

== Changelog ==

= 1.0.1 =
* Renamed to "Yuupee Store Migrator for Shopify" (slug: yuupee-store-migrator-for-shopify) per WordPress.org naming guidelines.
* Hardened the image-reachability pre-flight check: it now uses `wp_safe_remote_head()` so a CSV-supplied image URL can't be used to probe internal/private network addresses.

= 1.0.0 =
First public release.

* Import Shopify products from the standard Products CSV: simple and variable
  products, variants (price, sale price from Compare At, SKU, stock, backorders,
  weight, barcode, tax, virtual), product type as category, tags, vendor and SEO
  fields. Product and variant images are sideloaded into the media library and
  de-duplicated.
* Background processing through Action Scheduler (the queue WooCommerce itself
  uses): an index pass over the uploaded CSV, then batched import with a live
  progress report, resumable if interrupted.
* Idempotent: matching is by Shopify source ID (SKU as a fallback), so a second
  run updates products in place instead of duplicating them.
* One-click rollback: deletes every product, variation and image a run created.
* Pre-flight checks on the Analyze step: a dry run over the CSV flags duplicate
  SKUs, unparseable prices, rows with no title, missing columns and unreachable
  image URLs before anything is written. Blocking issues stop the import until
  the file is corrected; warnings are shown but let the run proceed.
* Independent per-run log (WooCommerce's logger is off on many stores), shown on
  the report screen and downloadable as CSV.
* Crash-safety: a product's mapping row is written the moment the product is
  created, before its images and variations, so a batch that dies partway can
  still be rolled back cleanly.
