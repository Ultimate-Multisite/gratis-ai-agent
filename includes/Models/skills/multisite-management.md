# Multisite Network Management

## When to Use
Use this skill when the user asks about managing a WordPress Multisite network — sites, users across the network, network settings, or super admin tasks.

## Key WP-CLI Commands

### Network Sites
- `wp site list --fields=blog_id,url,registered,last_updated` — List all sites
- `wp site create --slug=<slug> --title=<title>` — Create a new site
- `wp site activate <id>` — Activate a site
- `wp site deactivate <id>` — Deactivate a site
- `wp site archive <id>` — Archive a site
- Mapped custom domains must be diagnosed using their exact public URL. Pass `--url=<mapped-site-url>` so WordPress selects the intended blog; a network-domain check can target a different tenant and hide frontend locks.

### Super Admins
- `wp super-admin list` — List super admins
- `wp super-admin add <user>` — Grant super admin
- `wp super-admin remove <user>` — Revoke super admin

### Network Plugins & Themes
- `wp plugin list --fields=name,status --url=<site>` — Plugins on specific site
- `wp theme list --fields=name,status --url=<site>` — Themes on specific site
- `wp plugin activate <plugin> --network` — Network activate plugin
- `wp theme enable <theme> --network` — Network enable theme

### Network Options
- `wp network meta get 1 <key>` — Read network option
- `wp network meta update 1 <key> <value>` — Update network option

### Cross-site Operations
- `wp site list --field=url | xargs -I {} wp option get blogname --url={}` — Run command across all sites
- `wp user list --network --fields=ID,user_login,user_email` — Network-wide user list

## REST API Patterns
- `GET /wp/v2/sites` — List network sites (WP 5.9+)
- Site-specific requests need `--url=<site-url>` flag in WP-CLI

## Verification Steps
After network changes:
1. Verify the site is accessible at its URL
2. Check that plugins/themes are correctly activated
3. Confirm user roles across relevant sites
4. Test network admin access
5. For Ultimate Multisite customer-owned sites, verify the primary domain mapping and tenant limits (including monthly visits) against the exact mapped URL
