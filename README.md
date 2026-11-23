# ChatGPT Ads Product Feed for OpenCart 3

An OpenCart 3.0.x extension that publishes your catalog as a product feed in the [OpenAI product feed format](https://developers.openai.com/commerce/specs/file-upload/products) for **ChatGPT Ads** product feed campaigns. Paste one URL into Ads Manager → **Hosted URL**. The feed then keeps itself up to date, with no cron and no extra setup.

[Русская версия](README.ru.md)

## Features

- **All required OpenAI fields:** `item_id`, `title`, `description`, `url`, `brand`, `seller_name`, `image_url`, `price`, `availability`, `target_countries`, `store_country`.
- **Recommended fields:** `sale_price` (from specials), `additional_image_urls`, `product_category`, `gtin` (EAN/UPC/JAN/ISBN), `mpn`, weight, shipping, returns, policies, `is_ads_eligible`.
- **`ads_metadata` labels for Ads Manager grouping:**
  - `custom_label_0` = top category
  - `custom_label_1` = `on_sale`
  - `custom_label_2` = brand
- **Variants from product options.** You choose options, for example *Color* or *Size*. Each value or combination becomes its own row with `group_id`, `listing_has_variations` and `variant_dict`. Price modifiers and option stock are taken into account.
- **Static hosted URL:** `https://your-shop/chatgpt-feed/KEY.csv`. Ads Manager rejects URLs with query parameters.
- **Updates itself.** When the feed is older than *Cache, hours*, normal storefront traffic triggers a background rebuild, so no cron is needed.
- **Large catalogs.** The build is resumable and uses keyset pagination with 200 products per batch. It stops before the PHP time limit and continues in a new request, and memory use stays flat.
- **WebP/GIF images** are converted once to JPEG (GD) and cached in `image/cache/chatgpt-feed/`, because OpenAI requires JPEG or PNG.
- **Validator.** Rows with missing required fields, non-HTTPS links, unsupported images or zero prices are skipped and listed in the admin panel and the log.
- **Delta API (optional).** Saving a product in admin pushes its price and availability to ChatGPT Ads right away.
- Output format: CSV, TSV or JSONL.

## Requirements

- OpenCart 3.0.x (tested on 3.0.4.2)
- PHP 7.0+ with cURL and GD. For WebP conversion GD needs WebP support.
- HTTPS storefront
- Write access for PHP to the site root (`chatgpt-feed/`) or `image/` (fallback)

## Installation

1. Download `oaiq_chatgpt_feed.ocmod.zip` from [Releases](../../releases), or build it with `python build.py`.
2. Go to **Extensions → Installer** and upload the zip.
3. Go to **Extensions → Extensions**, choose the type **Feeds**, find **ChatGPT Ads Product Feed** and click **Install**, then **Edit**.
4. Set **Status** to Enabled. Set store country, target countries and currency (for example `UA` / `UA` / `UAH`), seller name, and the policy URLs (full `https://` links). Click **Save**.
5. Click **Rebuild now** and wait for *Done*.
6. Copy **Hosted feed URL**. In ChatGPT Ads Manager, open your feed, click **⋯**, choose **Hosted URL** and paste it.

To update, upload the new zip over the old one and click **Save** once on the module page.

## Settings

| Setting | Notes |
|---|---|
| Format | CSV (default), TSV or JSONL |
| Cache, hours | How often the feed is rebuilt (default 24). ChatGPT expires items after 2 weeks. |
| item_id source | `product_id`, SKU or model. Keep it stable once the feed is live. |
| Image size, px | `0` = original images. Otherwise images are scaled down. |
| Out-of-stock status mapping | Maps the OpenCart stock status to `availability` when quantity is 0 |
| Categories / Exclude product IDs | Limit what is exported. No categories selected = all categories. |
| Variant options | Options that turn into separate variant rows |
| Delta API | Ads API key (Ads Manager → Settings) and feed ID (`fd_…`) |

## How it works

```
storefront page view ──(feed stale?)──► background build step (≤25 s) ──► next step … ──► done
                                                                                        │
system/storage/oaiq_chatgpt_feed/feed_0.csv ──copy──► /chatgpt-feed/KEY.csv ◄── ChatGPT Ads (Hosted URL)
```

- Each step reads products after the last `product_id` it processed, writes to a `.tmp` file and saves its position. The live file is replaced only when the whole catalog is done.
- Optional server cron, for shops with almost no traffic: `0 3 * * * curl -s "CRON_URL" > /dev/null`. The cron URL is shown on the module page.

## Security

- The feed file name and the cron URL contain a random 32-character key, checked with `hash_equals`. The Delta endpoint uses a separate 64-character secret.
- Forced rebuilds are limited to once per 5 minutes, and concurrent builds are blocked by a lock.
- Every setting is whitelisted and validated on save. The Ads API key is never sent back to the browser, and admin output is escaped.
- No core files are changed. The extension uses OpenCart events only.

## Files

```
install.xml
upload/admin/controller/extension/feed/oaiq_chatgpt_feed.php
upload/admin/language/en-gb/extension/feed/oaiq_chatgpt_feed.php
upload/admin/view/template/extension/feed/oaiq_chatgpt_feed.twig
upload/catalog/controller/extension/feed/oaiq_chatgpt_feed.php
upload/catalog/model/extension/feed/oaiq_chatgpt_feed.php
```

Uninstalling the extension removes its settings and events. Generated files stay in `chatgpt-feed/`, `image/cache/chatgpt-feed/` and `system/storage/oaiq_chatgpt_feed/`, and you can delete them by hand.

## License

[GPL-3.0](LICENSE), the same license as OpenCart.
