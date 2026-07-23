# WordPress.org Plugin Directory Submission

This document covers the complete process for submitting SD AI Agent to the
WordPress.org plugin directory and managing subsequent releases via SVN.

**Current status:** Approved for the WordPress.org plugin directory. The core
package is `superdav-ai-agent`; advanced features are distributed separately as
`superdav-ai-agent-advanced` from the same Git repository.

---

## Table of Contents

- [Build Matrix: Core vs. Advanced Packages](#build-matrix-core-vs-advanced-packages)
- [Prerequisites](#prerequisites)
- [Step 1 — Submit for Review](#step-1--submit-for-review)
- [Step 2 — Wait for Approval](#step-2--wait-for-approval)
- [Step 3 — First SVN Deployment](#step-3--first-svn-deployment)
- [Step 4 — Tag the Release](#step-4--tag-the-release)
- [Subsequent Releases](#subsequent-releases)
- [Assets (Banner, Icon, Screenshots)](#assets-banner-icon-screenshots)
- [Automated SVN Deployment](#automated-svn-deployment)
- [Troubleshooting](#troubleshooting)

---

## Build Matrix: Core vs. Advanced Packages

This repository now produces **two separate WordPress plugins**:

- `superdav-ai-agent` — the WordPress.org-approved core plugin.
- `superdav-ai-agent-advanced` — a separately distributed companion plugin under
  `advanced-plugin/` for self-hosted administrator/developer tools that are not
  permitted in the WordPress.org directory.

The core plugin remains the canonical WordPress.org package. Advanced code is no
longer stripped from the core zip at build time; it lives in `advanced-plugin/`
and is packaged as its own plugin zip.

| Feature surface | Core `superdav-ai-agent` | Advanced `superdav-ai-agent-advanced` |
| --- | :---: | :---: |
| Memory, knowledge, automations, abilities, chat | ✅ | Extends core |
| WordPress.org plugin directory install by slug | ✅ | — |
| Read-only file ops (`file-read`, `file-list`, `file-search`, `content-search`) | ✅ | — |
| AI plugin generation / sandbox / activate / update | ❌ | ✅ |
| WP-CLI custom tools and `wp-cli/execute` | ❌ | ✅ |
| WP REST dispatcher (`wp-rest/*`) | ❌ | ✅ |
| Raw SQL diagnostics (`sd-ai-agent/db-query`) | ❌ | ✅ |
| `run-php` low-level dispatcher | ❌ | ✅ |
| `file-write` / `file-edit` / `file-delete` and git source snapshots/reverts | ❌ | ✅ |
| Autonomous activate / deactivate / delete / switch / update plugin | ❌ | ✅ |
| Install plugin from arbitrary ZIP URL / GitHub | ❌ | ✅ |
| Mutating user-management abilities | ❌ | ✅ |
| Block-theme filesystem scaffolder | ❌ | ✅ |
| Benchmark WP-CLI command/engine | ❌ | ✅ |

### Packaging commands

```bash
bin/build.sh                    # core package (default)
bin/build.sh --target=core      # core package: superdav-ai-agent-X.Y.Z.zip
bin/build.sh --target=wporg     # alias for core
bin/build.sh --target=advanced  # advanced package: superdav-ai-agent-advanced-X.Y.Z.zip
bin/build.sh --target=both      # both packages
npm run archive                 # both packages plus slug-only local aliases
```

The core zip contains one top-level directory named `superdav-ai-agent/`. The
advanced zip contains one top-level directory named `superdav-ai-agent-advanced/`.
The core package excludes `/advanced-plugin` via `.distignore` and
`composer.json` archive rules.

### Development checkout behaviour

In a repository checkout, `superdav-ai-agent.php` includes
`advanced-plugin/superdav-ai-agent-advanced.php` when that file exists before the
core DI container boots. This keeps local development simple: activating the core
checkout loads both core and advanced handlers. Distribution zips exclude
`advanced-plugin/`, so production users install the advanced companion plugin
separately if they need the advanced feature set.

### When to update which package

If a new feature touches arbitrary code/CSS/JS execution, shell/WP-CLI dispatch,
raw SQL, plugin/theme/user state changes, direct filesystem mutation, or other
surfaces disallowed in the WordPress.org directory, implement it under
`advanced-plugin/` and register it through the advanced companion module. Keep
WordPress.org-safe core features in the root plugin.

---

## Prerequisites

Before submitting, confirm all of the following:

| Requirement | Status |
|-------------|--------|
| GPL-2.0-or-later license header in all PHP files | Done (t124) |
| `readme.txt` with all required sections | Done (t124) |
| Screenshots listed in `readme.txt` match `assets/` | Done (t124) |
| Sanitization/escaping audit passed | Done (t124) |
| Plugin slug `superdav-ai-agent` is available on WP.org | Verify at `wordpress.org/plugins/superdav-ai-agent/` |
| WordPress.org account exists for the submitter | Required |
| `wp plugin check` passes (requires Plugin Check plugin) | Run before submitting |

### Run Plugin Check

Install the [Plugin Check plugin](https://wordpress.org/plugins/plugin-check/) on a
WordPress 7.0 instance, then:

```bash
wp plugin check superdav-ai-agent --format=table
```

All errors must be resolved. Warnings should be reviewed — some are acceptable with
justification in the submission notes.

---

## Step 1 — Submit for Review

1. Log in to your WordPress.org account at `https://login.wordpress.org/`
2. Navigate to: **`https://wordpress.org/plugins/developers/add/`**
3. Fill in the form:
   - **Plugin name**: SD AI Agent
   - **Plugin description**: (paste the short description from `readme.txt`)
   - **Plugin ZIP**: Upload the ZIP built by `bin/build.sh` (see below)
4. Submit the form

### Build the submission ZIP

Always submit the **core** package. The advanced companion plugin is distributed
separately and must not be uploaded to the WordPress.org plugin directory.

```bash
# From the repo root — builds the core WordPress.org package.
bin/build.sh --target=wporg
# Output: superdav-ai-agent-1.2.0.zip
```

The ZIP must contain a single top-level directory named `superdav-ai-agent/`
with `superdav-ai-agent.php` at its root. `bin/build.sh` handles this
automatically.

### What to include in the submission notes

The review team reads these. Be specific:

```
SD AI Agent is an agentic AI assistant for WordPress built on the official
WordPress 7.0 AI Client SDK and Abilities API. It includes the Superdav AI
managed service and also supports separately configured connector plugins (for
example, an OpenAI connector). The plugin does not bundle AI provider
credentials.

External API calls: During activation, the included Superdav AI managed service
registers the site installation and sends a durable installation ID, site URL,
plugin version, and WordPress version to Superdav AI. When an administrator
selects that service for AI responses, it receives the conversation messages,
system prompt, attached files (if any), and tool definitions needed to generate
a reply. Separately configured provider connectors send AI requests to the
provider endpoint selected by the administrator; their endpoint URL and API key
are configured by the site administrator in Settings > AI Credentials.

This zip is built with `bin/build.sh --target=wporg` (alias of
`--target=core`). Advanced features that are not permitted in the WordPress.org
plugin directory have been moved into a separate companion plugin under
`advanced-plugin/` and are not present in the submitted zip.

The submitted core package still installs plugins from the WordPress.org
directory by slug (`sd-ai-agent/install-plugin`), which is the allowed exception
under "Changing Active Plugins". Advanced surfaces such as plugin generation,
WP-CLI/REST dispatchers, raw SQL diagnostics, run-php, direct filesystem
mutation, arbitrary ZIP installs, mutating user management, and benchmark tooling
ship only in `superdav-ai-agent-advanced`.

PHP 8.2+ is required (strict types, enums). WordPress 7.0+ is required (AI
Client SDK, Abilities API).
```

---

## Step 2 — Wait for Approval

- Review typically takes **1–4 weeks**
- You will receive an email at your WordPress.org account address
- The review team may request changes — respond promptly via the ticket system
- Do not resubmit the same plugin while a review is pending

### Responding to review requests

Use a concise, evidence-backed reply when automated or manual review tools report
that the plugin is not ready for approval:

1. Thank the reviewer and confirm that each reported item was investigated.
2. List each finding separately, with the fix or rationale and file/pattern
   references where possible.
3. Include the verification commands that passed, such as `wp plugin check`,
   `composer phpcs`, `composer phpstan`, `npm run build`, or relevant tests.
4. Avoid defensive wording, marketing language, emojis, or unsupported claims.
5. State whether the updated ZIP has been resubmitted and whether any item needs
   reviewer confirmation.

### Common rejection reasons to pre-empt

| Issue | Our status |
|-------|-----------|
| Missing license headers | Fixed (t124) |
| Unescaped output | Fixed (t124) |
| Direct database queries without `$wpdb->prepare()` | Audited (t124) |
| Enqueuing scripts without version parameter | Audited (t124) |
| Calling external APIs without disclosure | Disclosed in submission notes |
| Bundling libraries that should be loaded from WP core | N/A — we use WP core APIs |

---

## Step 3 — First SVN Deployment

After approval, WordPress.org sends credentials and the SVN URL becomes active.

### Install SVN

```bash
# Ubuntu/Debian
sudo apt-get install subversion

# macOS (Homebrew)
brew install subversion

# macOS (Xcode tools — already installed on most Macs)
svn --version
```

### Check out the SVN repository

```bash
# Replace YOUR_WP_USERNAME with your WordPress.org username
svn checkout https://plugins.svn.wordpress.org/superdav-ai-agent/ \
    ~/svn/superdav-ai-agent \
    --username YOUR_WP_USERNAME
```

The checkout creates three directories:
- `trunk/` — the current development version (what users get when they install)
- `tags/` — immutable snapshots for each release
- `assets/` — banner, icon, and screenshot images (not bundled in the plugin ZIP)

### Copy plugin files to trunk

```bash
# Build the production ZIP first
cd /path/to/superdav-ai-agent-repo
bin/build.sh

# Extract into the SVN trunk
cd ~/svn/superdav-ai-agent
# Clear trunk (keep .svn metadata)
find trunk/ -mindepth 1 -delete

# Extract the built ZIP into trunk
unzip /path/to/superdav-ai-agent-1.2.0.zip -d /tmp/wporg-extract/
cp -r /tmp/wporg-extract/superdav-ai-agent/. trunk/
rm -rf /tmp/wporg-extract/
```

Alternatively, use `bin/deploy-wporg.sh` (see [Automated SVN Deployment](#automated-svn-deployment)).

### Add new files and commit

```bash
cd ~/svn/superdav-ai-agent

# Stage all new files (SVN does not auto-track new files)
svn status | grep '^?' | awk '{print $2}' | xargs svn add

# Remove files that were deleted
svn status | grep '^!' | awk '{print $2}' | xargs svn delete

# Review what will be committed
svn status

# Commit trunk
svn commit -m "Add Superdav AI Agent v1.2.0 to trunk" \
    --username YOUR_WP_USERNAME
```

SVN will prompt for your WordPress.org password. Use your account password (not an
application password — WP.org SVN does not support application passwords).

---

## Step 4 — Tag the Release

Tags are how WordPress.org knows which version to serve for a specific version number.
The `Stable tag` in `readme.txt` must match a tag in `tags/`.

```bash
cd ~/svn/superdav-ai-agent

# Copy trunk to a tag (SVN copy is instant — no file transfer)
svn copy trunk/ tags/1.2.0 -m "Tag Superdav AI Agent v1.2.0"

# Verify
svn list tags/
```

After tagging, the plugin is live on WordPress.org at:
`https://wordpress.org/plugins/superdav-ai-agent/`

---

## Subsequent Releases

For each new version:

1. Update `Version:` in `superdav-ai-agent.php`
2. Update `Stable tag:` in `readme.txt`
3. Add a changelog entry under `== Changelog ==` in `readme.txt`
4. Run `bin/build.sh --target=both` to build and validate both ZIPs locally
5. Push a `vX.Y.Z` tag. The GitHub release workflow attaches both ZIPs to the
   release, deploys only the core `superdav-ai-agent` package to WordPress.org
   SVN for stable tags, and skips SVN for pre-release tags.
6. If the workflow is unavailable, run `bin/deploy-wporg.sh --version X.Y.Z`
   (see below) or follow the manual steps above for the core package only.

---

## Assets (Banner, Icon, Screenshots)

WP.org assets live in the SVN `assets/` directory — they are **not** included in the
plugin ZIP. They are served directly from SVN by the WP.org CDN.

### Required files

| File | Size | Purpose |
|------|------|---------|
| `assets/banner-772x250.png` | 772×250 px | Plugin directory banner |
| `assets/banner-1544x500.png` | 1544×500 px | Retina banner (optional but recommended) |
| `assets/icon-128x128.png` | 128×128 px | Plugin icon |
| `assets/icon-256x256.png` | 256×256 px | Retina icon |
| `assets/screenshot-1.png` | Any | Screenshot 1 (matches `== Screenshots ==` in readme.txt) |
| `assets/screenshot-2.png` | Any | Screenshot 2 |
| … | … | … |

The source-controlled listing assets live in `.wordpress-org/assets/`. The
release deployer syncs banner, icon, and `screenshot-N.png` files to the SVN
`assets/` directory; use the manual commands below only when a one-off asset
update is needed.

```bash
cd ~/svn/superdav-ai-agent

# Copy assets from Git repo
cp /path/to/superdav-ai-agent-repo/.wordpress-org/assets/banner-772x250.png  assets/
cp /path/to/superdav-ai-agent-repo/.wordpress-org/assets/banner-1544x500.png assets/
cp /path/to/superdav-ai-agent-repo/.wordpress-org/assets/icon-128x128.png    assets/
cp /path/to/superdav-ai-agent-repo/.wordpress-org/assets/icon-256x256.png    assets/

# Copy screenshots (rename to screenshot-N.png matching readme.txt order)
cp /path/to/superdav-ai-agent-repo/.wordpress-org/assets/screenshot-1.png assets/screenshot-1.png
# … repeat for each screenshot

svn add --force assets
svn commit -m "Add plugin assets (banner, icon, screenshots)" \
    --username YOUR_WP_USERNAME
```

Screenshot filenames in SVN must be `screenshot-1.png`, `screenshot-2.png`, etc.
They must match the ordered captions in `readme.txt`. Capture real product UI
from a configured test site; do not use mockups, login pages, or images that
claim unshipped functionality. Use legible text, avoid personal or customer
data, and review the final image at directory-card size before committing it.

For the three current listing views, capture fresh scrubbed images with:

```bash
WP_BASE_URL=https://your-local-test-site node scripts/capture-wporg-screenshots.js
```

Set `WP_ADMIN_USER` and `WP_ADMIN_PASSWORD` through your local environment when
the test-site credentials differ from the E2E defaults. Never commit credentials
or screenshots containing customer data, API keys, email addresses, or live
conversation content. The capture script sends a safe site-health review prompt
to the configured provider so the chat screenshot shows real tool activity; use
a dedicated test site and review the prompt output before committing it.

---

## Automated SVN Deployment

The GitHub release workflow is the primary automation. It expects repository
secrets named `SVN_USERNAME` and `SVN_PASSWORD`; stable tags deploy only the core
ZIP to WordPress.org SVN, while the advanced companion ZIP remains a GitHub
release asset only.

This repository does not publish either ZIP to the WooCommerce Marketplace. The
`/wp-release` expectation for this repo is GitHub release asset creation plus
WordPress.org SVN deployment for stable core releases only. Do not claim a
WooCommerce Marketplace deployment unless a future workflow adds explicit
marketplace product targets, credentials, and a successful upload/update step.

`bin/deploy-wporg.sh` remains available for local/manual trunk updates and
tagging of the core package.

```bash
# First deployment (after SVN checkout already exists at ~/svn/superdav-ai-agent)
bin/deploy-wporg.sh --version 1.2.0 --username YOUR_WP_USERNAME

# Subsequent releases
bin/deploy-wporg.sh --version 1.3.0 --username YOUR_WP_USERNAME
```

The script:
1. Builds the core production ZIP via `bin/build.sh --target=wporg`
2. Syncs the built files into `trunk/` using `rsync`
3. Runs `svn add` on new files and `svn delete` on removed files
4. Commits trunk with a standard message
5. Creates the version tag via `svn copy`

See `bin/deploy-wporg.sh --help` for all options.

---

## Troubleshooting

### `svn: E170013: Unable to connect to a repository`

SVN is not installed or the URL is wrong. Verify:
```bash
svn info https://plugins.svn.wordpress.org/superdav-ai-agent/
```
If this returns a 404, the plugin has not been approved yet.

### `svn: E215004: Authentication failed`

Your WordPress.org password is incorrect, or you are using an application password
(not supported for SVN). Use your main account password.

### `svn: E155010: The node ... is not under version control`

You added files to `trunk/` without running `svn add`. Run:
```bash
svn status | grep '^?' | awk '{print $2}' | xargs svn add
```

### Plugin not appearing on WP.org after commit

- Check that `Stable tag:` in `readme.txt` matches an existing tag in `tags/`
- WP.org CDN can take up to 15 minutes to reflect changes
- Check the plugin page directly: `https://wordpress.org/plugins/superdav-ai-agent/`

### Review rejected — what next?

Read the rejection email carefully. The review team provides specific feedback.
Common fixes:
- Add missing `esc_*()` calls around output
- Add `$wpdb->prepare()` around raw SQL
- Remove or justify any external API calls not disclosed in the submission
- Fix any GPL-incompatible bundled libraries

After fixing, reply to the review ticket (do not resubmit via the form).
