# Changelog

This file follows the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format, and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.1] - 2026-11-23

### Fixed
- HTML entities such as `&nbsp;` no longer appear in product titles and descriptions. OpenCart stores this text HTML-escaped, so it is now decoded twice.

## [1.0.0] - 2026-11-23

### Added
- Product feed in the OpenAI product feed format, as CSV, TSV or JSONL, for ChatGPT Ads hosted URL campaigns.
- Static published feed file `chatgpt-feed/KEY.csv`, with `image/chatgpt-feed/` used when the site root is not writable.
- Automatic rebuild triggered by storefront traffic, with no cron required. An optional cron URL is also available.
- Resumable, time-limited build with keyset pagination for large catalogs.
- Variants from selected product options: `group_id`, `listing_has_variations` and `variant_dict`.
- Conversion of WebP and GIF images to JPEG for the feed.
- Validator for required fields, HTTPS links, image formats and prices, with errors shown in the admin panel.
- `ads_metadata` labels: top category, on-sale flag and brand.
- Optional Delta API push of price and availability when a product is saved in admin.
- Admin page with AJAX rebuild and progress display, category and product filters, and stock status mapping.
