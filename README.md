# NAT Share Buttons

Lightweight WordPress share-button plugin by Nat Sakimura / NAT Consulting LLC.

## Features

- Facebook, X, Pinterest, LinkedIn, and LINE share links
- Local page-view totals with a one-hour keyed rate limit
- Local popular-posts widget based on recent page views
- Local click tracking for X, LinkedIn, and LINE
- Large "NNN VIEWS" total display, matching Mashshare style
- Auto-inserted above post content (can be disabled in settings)
- Shortcode `[nat_share]` for manual placement
- Template function `nsb_render()`
- Zero external font/icon requests — SVG icons are inline
- No social-network API requests on page load

## Installation

1. Upload the `nat-share-buttons` folder to `/wp-content/plugins/`
2. Activate via **Plugins** menu
3. Done — buttons appear above each post automatically

## Popular posts

Version 1.2.0 includes the former NAT Local Popular Posts plugin as a module.
It preserves the existing `nsb_pageviews`, `nsb_pageviews_daily`,
`widget_nat_local_popular`, `nlpp_activated_at`, and `nlpp_daily_cleanup` data
and identifiers, so no table copy or widget reconfiguration is required.

The widget ranks published posts and pages by the selected recent-day window.
For a new installation it uses lifetime totals during the first 48 hours while
daily buckets warm up. Daily buckets older than 32 days are deleted.

### Upgrade from the standalone plugin

1. Update NAT Share Buttons to 1.2.0 while NAT Local Popular Posts remains active.
2. Confirm page views and the existing popular-posts widget still work.
3. Deactivate NAT Local Popular Posts. Do not delete its tables or options.
4. On the next request, the integrated module takes over the same widget and cron hook.

If the standalone plugin is reactivated for rollback, the integrated module
automatically stands down to avoid duplicate widgets or page-view counts.

## Settings

**Settings → NAT Share Buttons** provides the auto-insertion toggle and the
Mashshare seed-count migration tool. No Facebook App Secret or other social
network credential is used or required.

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

The seeded counts are added to locally recorded page views and shown as the total.

## Notes on X/Twitter

Twitter/X removed their public share-count API in 2015. No plugin can fetch
real repost counts without paid API access. This plugin can record local clicks
on the X button, but those clicks are not mixed into popular-post rankings.

## License

MIT
