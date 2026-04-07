# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin overview

Single-file WordPress plugin (`nat-share-buttons.php`) with two static assets. No build step, no package manager, no dependencies beyond WordPress core.

- `nat-share-buttons.php` — all PHP logic (activation, enqueueing, share counts, AJAX, rendering, settings, migration)
- `assets/nsb.css` — styles for the share widget
- `assets/nsb.js` — vanilla JS click tracker (fire-and-forget `fetch` to `admin-ajax.php`)

## Architecture

**Share count sources (two strategies):**
- *Real API counts*: Facebook (Graph API, cached via `set_transient` for 1 hour) and Pinterest (widgets JSONP API, same caching)
- *Click-tracked counts*: X, LinkedIn, LINE — recorded in a custom DB table `{prefix}nsb_clicks` on each button click

**Total share count** = `_nsb_seed_count` post meta (seeded from Mashshare migration) + Facebook API count + Pinterest API count + click-tracked counts for X/LinkedIn/LINE.

**Rate limiting**: 1 click per IP per post per network per hour, enforced via `set_transient`.

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
- `nsb_migrate` — admin only, copies old Mashshare meta to `_nsb_seed_count`
- `nsb_detect_keys` — admin only, finds `%mash%` meta keys in the DB

**Database table** created on activation via `dbDelta`:
```sql
wp_nsb_clicks (id, post_id, network, clicked_at)
```

## Naming conventions

All functions, hooks, options, and CSS classes are prefixed `nsb_` / `nsb-`. The option key is `nsb_options` (array). Transient keys: `nsb_fb_{md5}`, `nsb_pin_{md5}`, `nsb_rl_{md5}`.
