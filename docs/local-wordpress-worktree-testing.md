# Local WordPress Worktree Testing Plan

This plan defines a host-native replacement for `wp-env` that supports multiple
simultaneous git worktrees. Each worktree gets an isolated WordPress install,
database, HTTPS hostname, logs, uploads directory, and symlinked copy of the
plugin checkout.

Target platform: CachyOS / Arch Linux, using `paru`, system services, PHP 8.5,
MariaDB/MySQL, nginx, php-fpm, dnsmasq, and a locally trusted certificate
authority.

## Goals

- Run a full browser-accessible WordPress site for any plugin worktree without
  Docker.
- Allow several worktrees to run at the same time with independent databases and
  URLs.
- Use HTTPS by default through a locally trusted certificate authority.
- Keep setup deterministic enough for automation and future subagents.
- Avoid changing canonical plugin identifiers. The plugin directory symlink must
  remain `superdav-ai-agent`; internal IDs such as `sd-ai-agent` are unchanged.

## Implemented helper quickstart

The repository now includes host-native helper scripts under `scripts/local-wp/`.
They are intended for Dave's CachyOS/Arch development machine and are local-only;
do not use them in CI or production packaging.

Add the repository-local babysitter binary to `PATH` when orchestrating related
work from this checkout:

```bash
export PATH="$PWD/.pi/npm/node_modules/.bin:$PATH"
```

Run the non-destructive host check first:

```bash
scripts/local-wp/bootstrap-host.sh --check
```

The check reports missing commands/services and prints the CachyOS/Arch package,
dnsmasq, nginx, PHP-FPM, and CA trust commands to run manually. It does not write
privileged host configuration.

Provision a site for the current git worktree. Provisioning installs and
activates this plugin plus the latest `ai-provider-for-anthropic-max` GitHub
release by default:

```bash
scripts/local-wp/provision-site.sh --dry-run   # preview commands
scripts/local-wp/provision-site.sh             # create/update the site
```

Useful environment overrides:

```bash
SUPERDAV_WP_SITE_SLUG=feature-x scripts/local-wp/provision-site.sh
SUPERDAV_LOCAL_WP_SITES_ROOT=$HOME/local-wp-sites scripts/local-wp/list-sites.sh
SUPERDAV_LOCAL_WP_PHP_FPM_SOCKET=/run/php-fpm85/php-fpm.sock scripts/local-wp/provision-site.sh
SUPERDAV_LOCAL_WP_MYSQL=mysql scripts/local-wp/provision-site.sh
SUPERDAV_LOCAL_WP_INSTALL_ANTHROPIC_MAX=0 scripts/local-wp/provision-site.sh
SUPERDAV_LOCAL_WP_ANTHROPIC_MAX_URL=https://github.com/Ultimate-Multisite/ai-provider-for-anthropic-max/releases/download/v1.3.0/ai-provider-for-anthropic-max.zip scripts/local-wp/provision-site.sh
```

After provisioning, install the generated nginx config and trust the generated
root CA using the commands printed by `provision-site.sh` and
`bootstrap-host.sh --check`. Passing `--install-nginx` will run the nginx symlink,
`nginx -t`, and reload commands via `sudo`; omit it when you want to review the
config first.

Lifecycle commands read stable `site.json` metadata and default to the site for
the current worktree unless a slug is passed:

```bash
scripts/local-wp/list-sites.sh
scripts/local-wp/smoke-test.sh [site-slug]
scripts/local-wp/snapshot-site.sh clean [site-slug]
scripts/local-wp/reset-site.sh [site-slug]
scripts/local-wp/restore-site.sh clean [site-slug]
scripts/local-wp/destroy-site.sh [site-slug] --yes
```

Safety rules implemented by the helpers:

- generated sites must live under `SUPERDAV_LOCAL_WP_SITES_ROOT`;
- destructive helpers refuse paths outside that root;
- database drops are limited to names using the configured `wp_sd_` prefix;
- the plugin symlink is always `wp-content/plugins/superdav-ai-agent`;
- internal code identifiers such as `sd-ai-agent` are not renamed or migrated;
- the Anthropic Max provider connector is installed from the latest GitHub
  release unless `SUPERDAV_LOCAL_WP_INSTALL_ANTHROPIC_MAX=0` is set;
- privileged nginx, dnsmasq, and trust-store changes are printed for review
  unless explicitly requested.

Verification for script changes:

```bash
bash -n scripts/local-wp/*.sh
scripts/local-wp/bootstrap-host.sh --check
scripts/local-wp/provision-site.sh --dry-run
```

## Proposed layout

```text
/home/dave/superdav-ai-agent/                  main worktree
/home/dave/superdav-ai-agent-wt/feature-x/     additional worktree
/home/dave/local-wp-sites/superdav-main/       generated WordPress site
/home/dave/local-wp-sites/feature-x/           generated WordPress site
/home/dave/.config/superdav-local-wp/config    local factory config
/home/dave/.local/share/superdav-local-ca/     local CA material
```

Per-site layout:

```text
/home/dave/local-wp-sites/<site-slug>/
  public/                                      WordPress core
  public/wp-content/plugins/superdav-ai-agent  symlink to the worktree
  logs/nginx-access.log
  logs/nginx-error.log
  snapshots/clean.sql
  site.env
  site.json
```

Per-site runtime identity:

```text
URL:      https://<site-slug>.superdav.test
Admin:    admin / admin
Database: wp_sd_<site_slug_sanitized>
Plugin:   public/wp-content/plugins/superdav-ai-agent -> current worktree
```

## Phase 1: Host service baseline

### Deliverables

- Documented CachyOS/Arch package installation.
- Running nginx, PHP 8.5 FPM, MariaDB/MySQL, and dnsmasq services.
- A dedicated local test domain suffix, recommended: `superdav.test`.
- A single nginx wildcard server pattern that maps hostnames to generated site
  directories.

### Package installation draft

Use `paru` rather than distro-agnostic or Debian package commands:

```bash
paru -S --needed \
  nginx \
  mariadb \
  php \
  php-fpm \
  php-gd \
  php-imagick \
  wp-cli \
  dnsmasq \
  nss \
  ca-certificates-utils \
  openssl
```

Package names vary depending on current CachyOS/AUR availability. On the tested
CachyOS host, PHP 8.5 is provided by the standard `php` and `php-fpm` packages;
verify with `paru -Ss '^php$|^php-fpm$|^php85'` before installing on another
machine.

### Service setup draft

```bash
sudo systemctl enable --now mariadb
sudo mariadb-install-db --user=mysql --basedir=/usr --datadir=/var/lib/mysql || true
sudo mysql_secure_installation

sudo systemctl enable --now php-fpm.service
sudo systemctl enable --now nginx
sudo systemctl enable --now dnsmasq
```

On the tested CachyOS host the PHP-FPM unit is `php-fpm.service` and the socket
is `/run/php-fpm/php-fpm.sock`. The helper still detects common alternatives and
allows overriding with `SUPERDAV_LOCAL_WP_PHP_FPM_SOCKET`.

## Phase 2: DNS and HTTPS foundation

### Deliverables

- dnsmasq resolves all `*.superdav.test` hostnames to `127.0.0.1` and `::1`.
- A local root certificate authority is generated once and trusted system-wide.
- Per-site certificates are generated for each hostname and signed by the local
  CA.
- Browser access uses `https://<site>.superdav.test` with no certificate warning.

### dnsmasq draft

Create a dedicated config file such as `/etc/dnsmasq.d/superdav-local-wp.conf`:

```text
address=/.superdav.test/127.0.0.1
address=/.superdav.test/::1
```

Ensure NetworkManager/systemd-resolved is configured to use dnsmasq for local
queries on this machine. The implementation phase must record the exact CachyOS
resolver integration that works on the target host.

### Local CA draft

CA location:

```text
/home/dave/.local/share/superdav-local-ca/rootCA.key
/home/dave/.local/share/superdav-local-ca/rootCA.pem
```

Generate once:

```bash
install -d -m 700 ~/.local/share/superdav-local-ca
openssl genrsa -out ~/.local/share/superdav-local-ca/rootCA.key 4096
openssl req -x509 -new -nodes \
  -key ~/.local/share/superdav-local-ca/rootCA.key \
  -sha256 -days 3650 \
  -out ~/.local/share/superdav-local-ca/rootCA.pem \
  -subj '/C=US/O=Superdav Local Dev/CN=Superdav Local Dev Root CA'
```

Trust system-wide on Arch/CachyOS:

```bash
sudo trust anchor ~/.local/share/superdav-local-ca/rootCA.pem
sudo update-ca-trust
```

Browsers using NSS may also need:

```bash
certutil -d sql:$HOME/.pki/nssdb -A \
  -t 'C,,' \
  -n 'Superdav Local Dev Root CA' \
  -i ~/.local/share/superdav-local-ca/rootCA.pem
```

The final scripts should detect whether `certutil` and the NSS DB are present
and print exact manual follow-up when needed.

### Per-site certificate draft

Each site gets:

```text
/home/dave/local-wp-sites/<site-slug>/certs/site.key
/home/dave/local-wp-sites/<site-slug>/certs/site.crt
```

Certificates must include SANs:

```text
DNS:<site-slug>.superdav.test
DNS:localhost
IP:127.0.0.1
IP:::1
```

## Phase 3: nginx and PHP-FPM routing

### Deliverables

- nginx can serve any generated site under `https://<site>.superdav.test`.
- PHP runs through the PHP 8.5 FPM socket.
- Logs are written per site.
- Large request and upload limits are compatible with plugin testing.

### Preferred nginx strategy

Use one generated nginx server file per site rather than a single wildcard root.
That makes certificate paths, logs, and site cleanup deterministic.

Example generated server block:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name feature-x.superdav.test;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name feature-x.superdav.test;

    root /home/dave/local-wp-sites/feature-x/public;
    index index.php index.html;

    ssl_certificate     /home/dave/local-wp-sites/feature-x/certs/site.crt;
    ssl_certificate_key /home/dave/local-wp-sites/feature-x/certs/site.key;

    access_log /home/dave/local-wp-sites/feature-x/logs/nginx-access.log;
    error_log  /home/dave/local-wp-sites/feature-x/logs/nginx-error.log;

    client_max_body_size 128m;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include fastcgi.conf;
        fastcgi_pass unix:/run/php-fpm/php-fpm.sock;
    }
}
```

The implementation must verify the real CachyOS `fastcgi.conf` include path and
PHP-FPM socket path before committing templates.

## Phase 4: Site factory scripts

### Deliverables

Add host-local development helpers, likely under `scripts/local-wp/`:

```text
scripts/local-wp/bootstrap-host.sh       one-time host preflight and guidance
scripts/local-wp/provision-site.sh       create/update site for current worktree
scripts/local-wp/reset-site.sh           reset database/uploads and reactivate plugin
scripts/local-wp/snapshot-site.sh        save named database snapshot
scripts/local-wp/restore-site.sh         restore named database snapshot
scripts/local-wp/destroy-site.sh         remove site files, nginx conf, and DB
scripts/local-wp/list-sites.sh           show known generated sites
```

Potential later convenience wrappers may be added under `bin/` after the script
interfaces stabilize.

### Provisioning behavior

`provision-site.sh` should:

1. Find the current git worktree root with `git rev-parse --show-toplevel`.
2. Derive a safe, unique site slug. Allow override with `SUPERDAV_WP_SITE_SLUG`.
3. Create site directories.
4. Create the database if missing.
5. Download WordPress core with WP-CLI if missing.
6. Create `wp-config.php` configured for the per-site DB and HTTPS URL.
7. Install WordPress if not already installed.
8. Symlink the current worktree as
   `wp-content/plugins/superdav-ai-agent`.
9. Run `composer install` and `npm install` only when requested or missing.
10. Run `npm run build` unless skipped explicitly.
11. Activate `superdav-ai-agent`.
12. Generate the per-site certificate.
13. Generate/enable the nginx site config.
14. Reload nginx.
15. Write `site.json` metadata.

### Site metadata

`site.json` should be stable and readable by other helpers:

```json
{
  "schema": 1,
  "slug": "feature-x",
  "host": "feature-x.superdav.test",
  "url": "https://feature-x.superdav.test",
  "wp_root": "/home/dave/local-wp-sites/feature-x/public",
  "site_root": "/home/dave/local-wp-sites/feature-x",
  "plugin_dir": "/home/dave/superdav-ai-agent-wt/feature-x",
  "plugin_slug": "superdav-ai-agent",
  "db_name": "wp_sd_feature_x",
  "created_at": "2026-06-03T00:00:00Z"
}
```

## Phase 5: Worktree workflow

### Deliverables

- A documented flow for branch-specific WordPress sites.
- Collision-safe slugging for worktrees with the same basename.
- A list command that maps worktrees to sites.

Example workflow:

```bash
git worktree add ../superdav-ai-agent-wt/feature-x -b feature-x
cd ../superdav-ai-agent-wt/feature-x
scripts/local-wp/provision-site.sh
```

Expected output:

```text
Site ready: https://feature-x.superdav.test/wp-admin
Admin: admin / admin
WordPress root: /home/dave/local-wp-sites/feature-x/public
Database: wp_sd_feature_x
Plugin symlink: .../plugins/superdav-ai-agent -> /home/dave/superdav-ai-agent-wt/feature-x
```

Slugging rules:

- Default slug uses the worktree basename.
- Convert to lowercase.
- Replace non-alphanumeric runs with `-`.
- Trim leading/trailing hyphens.
- If a site exists for a different `plugin_dir`, append a short hash.
- Support explicit override with `SUPERDAV_WP_SITE_SLUG=...`.

## Phase 6: Reset, snapshots, and teardown

### Deliverables

- Fast reset to a clean installed WordPress state.
- Named snapshots for bug reproduction.
- Safe destroy that avoids deleting arbitrary directories.

Reset should:

```bash
wp db reset --yes --path="$WP_ROOT"
wp core install --path="$WP_ROOT" --url="$URL" \
  --title="Superdav $SLUG" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=admin@example.com
wp plugin activate superdav-ai-agent --path="$WP_ROOT"
rm -rf "$WP_ROOT/wp-content/uploads"/*
```

Snapshot/restore should wrap:

```bash
wp db export "$SITE_ROOT/snapshots/<name>.sql" --path="$WP_ROOT"
wp db import "$SITE_ROOT/snapshots/<name>.sql" --path="$WP_ROOT"
```

Destroy must verify it is operating under the configured sites root before
removing files and must drop only the DB named in `site.json`.

## Phase 7: Plugin-specific smoke testing

### Deliverables

- A smoke test command that proves the host-native site can run this plugin.
- Documentation for manual and automated checks.

Initial smoke checks:

```bash
wp core version --path="$WP_ROOT"
wp plugin is-active superdav-ai-agent --path="$WP_ROOT"
wp option get siteurl --path="$WP_ROOT"
wp rewrite structure '/%postname%/' --path="$WP_ROOT"
wp rewrite flush --path="$WP_ROOT"
```

Plugin-specific checks should include:

```bash
wp plugin activate superdav-ai-agent --path="$WP_ROOT"
wp eval 'echo defined("SD_AI_AGENT_VERSION") ? SD_AI_AGENT_VERSION : "missing";' --path="$WP_ROOT"
curl -k "https://$HOST/wp-json/" | jq '.namespaces'
```

Avoid using the agent-facing `wp-rest/execute` ability to call the private
`sd-ai-agent/v1` namespace during smoke testing; call services, WP-CLI, or
browser routes directly.

## Phase 8: Documentation and maintenance

### Deliverables

- Final user guide in this document or a shorter linked quickstart.
- Troubleshooting section for dnsmasq, CA trust, PHP-FPM socket mismatch, DB
  credentials, nginx reload failures, and browser certificate stores.
- Clear boundaries: these helpers are for local development only, not CI.
- Verification commands for script changes.

Recommended verification for future script PRs:

```bash
shellcheck scripts/local-wp/*.sh
bash -n scripts/local-wp/*.sh
scripts/local-wp/bootstrap-host.sh --check
scripts/local-wp/provision-site.sh --dry-run
```

## Subagent delegation briefs

Use these briefs to split implementation across independent subagents. Each
subagent should make the smallest durable change, include verification evidence,
and avoid touching unrelated package lockfiles or build artifacts.

### Subagent A: CachyOS host bootstrap research

Scope:

- Verify exact package names for PHP 8.5, php-fpm, MariaDB/MySQL, nginx,
  dnsmasq, WP-CLI, OpenSSL, NSS tools, and CA trust tools on CachyOS/Arch.
- Verify exact PHP-FPM service and socket names.
- Verify resolver integration for dnsmasq on the target host.

Deliverables:

- Update this document's package and service commands with confirmed names.
- Add `scripts/local-wp/bootstrap-host.sh --check` if straightforward, otherwise
  file a follow-up issue with exact unresolved host checks.

Verification:

```bash
paru -Ss '^php85'
systemctl list-unit-files | rg 'php|fpm|nginx|mariadb|dnsmasq'
php -v
```

### Subagent B: Local CA and certificate helper

Scope:

- Implement reusable functions or a script for creating the local root CA,
  trusting it system-wide, and generating per-site SAN certificates.
- Keep private keys in user-owned directories with restrictive permissions.
- Print manual browser/NSS trust steps when automation cannot safely complete.

Deliverables:

- Certificate helper script under `scripts/local-wp/`.
- Documentation updates for trust behavior and troubleshooting.

Verification:

```bash
openssl x509 -in ~/.local/share/superdav-local-ca/rootCA.pem -noout -subject -issuer
openssl verify -CAfile ~/.local/share/superdav-local-ca/rootCA.pem /path/to/site.crt
```

### Subagent C: Site provisioning and metadata

Scope:

- Implement `provision-site.sh` for current worktree detection, slugging,
  WordPress download/install, DB creation, plugin symlink, build activation, and
  `site.json` writing.
- Preserve canonical plugin slug/text domain and internal `sd-ai-agent` naming.

Deliverables:

- `scripts/local-wp/provision-site.sh`.
- Shared helper library if needed.
- Updated quickstart examples.

Verification:

```bash
scripts/local-wp/provision-site.sh --dry-run
wp plugin is-active superdav-ai-agent --path=/home/dave/local-wp-sites/<slug>/public
```

### Subagent D: nginx and dnsmasq integration

Scope:

- Generate per-site nginx server configs for HTTPS and PHP-FPM.
- Validate configs before reload.
- Add dnsmasq config guidance/checks without overwriting unrelated resolver
  configuration.

Deliverables:

- nginx template or generator inside `scripts/local-wp/`.
- `bootstrap-host.sh --check` coverage for nginx, PHP-FPM socket, dnsmasq, and
  domain resolution.

Verification:

```bash
sudo nginx -t
getent hosts feature-x.superdav.test
curl -I https://feature-x.superdav.test
```

### Subagent E: Reset, snapshot, list, and destroy helpers

Scope:

- Implement lifecycle helpers that read `site.json` and refuse unsafe paths.
- Add clean reset, named snapshots, restore, list, and destroy operations.

Deliverables:

- `reset-site.sh`, `snapshot-site.sh`, `restore-site.sh`, `list-sites.sh`, and
  `destroy-site.sh`.
- Safety checks for configured sites root and DB names.

Verification:

```bash
scripts/local-wp/list-sites.sh
scripts/local-wp/snapshot-site.sh clean
scripts/local-wp/reset-site.sh
scripts/local-wp/restore-site.sh clean
```

### Subagent F: Smoke tests and developer guide polish

Scope:

- Add a smoke test helper for WordPress/plugin health.
- Convert the plan into a concise quickstart once the scripts exist.
- Add troubleshooting for common CachyOS service, DNS, and certificate failures.

Deliverables:

- `scripts/local-wp/smoke-test.sh`.
- Final quickstart section in this document.

Verification:

```bash
scripts/local-wp/smoke-test.sh
curl -s https://<slug>.superdav.test/wp-json/ | jq '.namespaces'
```

## Open decisions

- Whether to use MariaDB root socket authentication or create a dedicated local
  database user such as `superdav_wp`.
- Whether scripts should require sudo for nginx/dnsmasq writes or emit config
  snippets for manual installation.
- Whether generated nginx site configs should live under `/etc/nginx/conf.d/` or
  `/etc/nginx/sites-enabled/` on this CachyOS host.
- Whether to make `npm run build` default during provisioning or require an
  explicit `--build` flag for speed.
- Whether to use `mkcert` if available, or keep the OpenSSL-based CA helper to
  avoid another dependency.
