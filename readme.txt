=== Store Migrator for WooCommerce ===
Contributors: abdoudiba
Tags: shopify, woocommerce, migration, import, csv importer
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.0
WC tested up to: 10.0
Stable tag: 0.3.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate a Shopify store into WooCommerce — products, variants and images from a CSV export, batched and resumable, with a full rollback.

== Description ==

Store Migrator for WooCommerce brings a Shopify catalogue into WooCommerce
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

= 0.3.0-dev =
* Pre-flight checks on the Analyze step: a dry run over the CSV flags duplicate
  SKUs, unparseable prices, rows with no title, missing columns and unreachable
  image URLs before anything is written. Blocking issues stop the import until
  the file is corrected; warnings are shown but let the run proceed.
* The plugin now keeps its own per-run log (independent of WooCommerce's logger,
  which many stores have switched off), shown on the report screen and
  downloadable as CSV.
* Crash-safety: a product's mapping row is written the moment the product is
  created, before its images and variations, so a batch that dies partway can
  still be rolled back cleanly and the next run updates in place.

= 0.2.0-dev =
* Product import from the Shopify Products CSV: simple and variable products,
  variants (price, sale price from Compare At, SKU, stock, backorders, weight,
  barcode, tax, virtual), product type as category, tags, vendor, SEO fields,
  and product + variant images sideloaded into the media library.
* Background index pass over the uploaded CSV, then batched import through
  Action Scheduler with a live progress report.
* One-click rollback: deletes every product, variation and image a run created.
* Re-running an import updates products in place instead of duplicating them.

= 0.1.0-dev =
* Scaffold: plugin bootstrap, Action Scheduler batch pipeline, ID-map table,
  migration-run registry, and the navigable import wizard shell.
