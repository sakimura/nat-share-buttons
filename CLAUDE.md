# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin overview

WordPress plugin with a small popular-posts module and static assets. No build step, no package manager, no dependencies beyond WordPress core.

- `nat-share-buttons.php` — activation, enqueueing, page-view/click AJAX, rendering, settings, migration
- `includes/popular-posts.php` — daily buckets, cleanup, ranking query, widget
- `assets/nsb.css` — styles for the share widget
- `assets/popular.css` — styles for the popular-posts widget
- `assets/nsb.js` — vanilla JS page-view/click tracker

## Architecture

**View and ranking sources:**
- Lifetime page views in `{prefix}nsb_pageviews`
- Daily page views in `{prefix}nsb_pageviews_daily`, retained for 32 days
- Local click events for X, LinkedIn, and LINE in `{prefix}nsb_clicks`

**Displayed total** = `_nsb_seed_count` post meta (seeded from Mashshare migration) + local lifetime page views. Click events are not included in popular-post rankings.

**Rate limiting**: 1 view per IP/post/hour and 1 click per IP/post/network/hour. Transient identifiers use a salted HMAC; raw IP addresses are not stored.

**Standalone compatibility**: if `NLPP_VERSION` exists after all plugins load,
the integrated module does not register its widget, daily writer, or cron callback.
The main handler temporarily keeps the legacy shared rate-limit key so staged
upgrade and rollback do not double count.

**Output methods (all call `nsb_render()`):**
- Auto-inserted above post content via `the_content` filter (disabled by `nsb_options['disable_auto']`)
- Shortcode `[nat_share id="123"]`
- Template function `echo nsb_render( $post_id )`

**Admin features** (Settings → NAT Share Buttons):
- Toggle auto-insertion
- Mashshare migration: detects `%mash%` meta keys, then copies values to `_nsb_seed_count` post meta (supports dry-run)

## Development

This is a local WordPress install. No build tools needed — edit PHP/CSS/JS directly.

To test in the browser, the site runs at the Local by Flywheel URL configured for `nat-dev`.

**AJAX actions registered:**
- `nsb_click` — public + logged-in, records a share click
- `nsb_pageview` — public + logged-in, atomically updates lifetime and daily views
- `nsb_migrate` — admin only, copies old Mashshare meta to `_nsb_seed_count`
- `nsb_detect_keys` — admin only, finds `%mash%` meta keys in the DB

**Database tables** created on activation via `dbDelta`:
```sql
wp_nsb_clicks (id, post_id, network, clicked_at)
wp_nsb_pageviews (post_id, count)
wp_nsb_pageviews_daily (post_id, view_date, count)
```

## Naming conventions

New functions, hooks, options, and CSS classes are prefixed `nsb_` / `nsb-`.
The legacy `nlpp_activated_at`, `nlpp_daily_cleanup`, `nat_local_popular`, and
`nlpp-` CSS identifiers are intentionally retained for migration compatibility.
