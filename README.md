# NAT Share Buttons

Lightweight WordPress share-button plugin by Nat Sakimura / NAT Consulting LLC.

## Features

- **Facebook** — real share count via Graph API (requires App ID + App Secret)
- **X (Twitter)** — click-tracked count (stored in own DB table)
- **Pinterest** — real pin count via widgets API
- **LinkedIn** — click-tracked count
- **LINE** — click-tracked count
- Large "NNN SHARES" total display, matching Mashshare style
- Auto-inserted above post content (can be disabled in settings)
- Shortcode `[nat_share]` for manual placement
- Template function `nsb_render()`
- Zero external font/icon requests — SVG icons are inline

## Installation

1. Upload the `nat-share-buttons` folder to `/wp-content/plugins/`
2. Activate via **Plugins** menu
3. Done — buttons appear above each post automatically

## Settings

**Settings → NAT Share Buttons**

- Disable auto-insertion (use shortcode instead)
- Facebook App ID / App Secret — required to fetch real Facebook share counts

## Facebook share counts

The Facebook Graph API requires authentication. Without credentials, Facebook counts will show as 0.

1. Go to [developers.facebook.com](https://developers.facebook.com/) and create an app (any type)
2. Copy the **App ID** and **App Secret** from **Settings → Basic**
3. Enter both values in **Settings → NAT Share Buttons** and save

## Shortcode

```
[nat_share]
[nat_share id="123"]
```

## Template tag

```php
<?php echo nsb_render(); ?>
<?php echo nsb_render( $post_id ); ?>
```

## Migrating from Mashshare

Go to **Settings → NAT Share Buttons** and use the migration tool:

1. Click **Detect Mashshare meta keys** to find the meta key used by your Mashshare installation (usually `_mashsb_shares`)
2. Confirm or edit the meta key
3. Click **Dry run** to preview how many posts will be affected
4. Click **Run migration** to copy the old counts to `_nsb_seed_count`

The seeded counts are added to the live Facebook/Pinterest counts and shown as the total.

## Notes on X/Twitter

Twitter/X removed their public share-count API in 2015. No plugin can fetch
real repost counts without the paid X API ($100+/month). This plugin records
clicks on the X button instead.

## License

MIT
