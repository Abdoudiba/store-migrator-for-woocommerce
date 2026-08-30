# WordPress.org listing assets

These files belong in the **`assets/` folder of the SVN repository**, a sibling
of `trunk/` — **not** inside the plugin package. They are `export-ignore`d from
`git archive` so a release zip never carries them.

| File | Size | Purpose |
|------|------|---------|
| `icon-256x256.png` | 256×256 | plugin icon (retina) |
| `icon-128x128.png` | 128×128 | plugin icon |
| `banner-772x250.png` | 772×250 | plugin header banner |
| `banner-1544x500.png` | 1544×500 | plugin header banner (retina) |

Source: `icon-256.html` / `icon-128.html` / `banner.html` (inline SVG, rendered
to PNG with headless Chrome). Flat mark: three "CSV row" bars flowing into a bold
arrow, on a WooCommerce-adjacent purple. Swap in commissioned art any time by
replacing the PNGs at the same paths.

Screenshots (`screenshot-1.png`, …) are still to do — capture the wizard's
Connect → Analyze (with pre-flight findings) → Report screens.
