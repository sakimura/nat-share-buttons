# NAT Share Buttons

Lightweight WordPress share-button plugin by Nat Sakimura / NAT Consulting LLC.

## Features

- **Facebook** — real share count via Graph API (no app token needed)
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

Since "a little discrepancy is fine", simply deactivate Mashshare and activate
this plugin. Historical counts are not imported; the total will reset to the
live Facebook/Pinterest counts plus new click-tracking going forward.

If you want to seed the click-tracking table with old Mashshare data, run:

```sql
-- Example: copy old X counts from Mashshare post meta
INSERT INTO wp_nsb_clicks (post_id, network, clicked_at)
SELECT post_id, 'x', NOW()
FROM wp_postmeta
WHERE meta_key = '_mashsb_shares'
-- This inserts one row per old share; adjust as needed
```

## Notes on X/Twitter

Twitter/X removed their public share-count API in 2015. No plugin can fetch
real repost counts without the paid X API ($100+/month). This plugin records
clicks on the X button instead.

## License

MIT
