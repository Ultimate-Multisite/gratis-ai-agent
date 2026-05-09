# WordPress.org Plugin Directory Submission

This document covers the complete process for submitting Superdav AI Agent to the
WordPress.org plugin directory and managing subsequent releases via SVN.

**Current status:** Pre-submission. The SVN repository at
`https://plugins.svn.wordpress.org/superdav-ai-agent/` does not yet exist — it is
created by WordPress.org only after the plugin passes manual review.

---

## Table of Contents

- [Build Matrix: Full vs. WP.org Variants](#build-matrix-full-vs-wporg-variants)
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

## Build Matrix: Full vs. WP.org Variants

This repository produces **two** distribution zips on every release. The
WordPress.org plugin guidelines prohibit features that the GitHub-channel
audience legitimately needs (the AI plugin builder, WP-CLI custom tools,
autonomous plugin state changes, install-from-arbitrary-URL, and arbitrary
filesystem writes inside `wp-content`), so we ship the full feature set on
GitHub and a stripped-but-still-useful variant on WP.org.

| Feature surface                              | Full (`*.zip`) | WP.org (`*-wporg.zip`) | Why gated                                                                                |
| -------------------------------------------- | :------------: | :--------------------: | ---------------------------------------------------------------------------------------- |
| AI plugin generation / sandbox / activate / update | ✅       |          ❌            | WP.org Guideline 4 — "attempting to process custom CSS/JS/PHP / allowing arbitrary script insertion" |
| WP-CLI custom tools (shell `exec`)            |       ✅       |          ❌            | Same as above (arbitrary command execution)                                              |
| Autonomous activate / deactivate / delete / switch / update plugin | ✅ |    ❌    | WP.org "Changing Active Plugins" — plugins must not change other plugins' state without per-action user intervention |
| Install plugin from arbitrary ZIP URL / GitHub |     ✅       |          ❌            | "Changing Active Plugins" only exempts WP.org-directory installs                          |
| `file-write` / `file-edit` / `file-delete`, plus `git-restore` / `git-revert-package` | ✅ | ❌ | Writes resolve under `WP_CONTENT_DIR`, which includes `plugins/` and `themes/` — same arbitrary third-party-code modification risk as "Changing Active Plugins" |
| Install plugin from WordPress.org directory by slug | ✅      |          ✅            | Allowed exception under "Changing Active Plugins"                                        |
| Read-only file ops (`file-read`, `file-list`, `file-search`, `content-search`) | ✅ | ✅ | Read-only — cannot mutate plugin/theme source                                            |
| Read-only git ops (`git-snapshot`, `git-diff`, `git-list`, `git-package-summary`) | ✅ | ✅ | `git-snapshot` writes only to the plugin's own DB tracking table, not to the filesystem  |
| List / search / recommend plugins (read-only) |       ✅       |          ✅            | Read-only operations are unrestricted                                                    |
| `run-php` (whitelisted WordPress functions only) |   ✅      |          ✅            | Whitelist excludes `activate_plugin`, `deactivate_plugins`, etc.                         |
| Memory, knowledge, automations, abilities, chat | ✅           |          ✅            | Core agent functionality — no policy concerns                                            |

### How the gates work

Each gated feature has a corresponding `SD_AI_AGENT_FEATURE_*` constant
defined in `superdav-ai-agent.php`. The `Features` registry
(`includes/Core/Features.php`) reads these constants at runtime and the
`AbilitiesHandler`, `WordPressAbilities`, and `CustomToolExecutor`
classes skip registration when a flag is `false`.

The full build leaves all flags at their default `true` value. Site
owners on the GitHub channel can still individually disable any feature
by adding `define( 'SD_AI_AGENT_FEATURE_NAME', false );` to
`wp-config.php`.

The WP.org build (`bin/build.sh --target=wporg`) does two extra things
on top of the runtime gates, both as defence-in-depth for the WP.org
review:

1. **Hard-defines the five constants to `false`** in the bundled
   `superdav-ai-agent.php`. Because each constant uses
   `defined( 'NAME' ) || define( 'NAME', true )`, hard-defining the
   constant earlier in the file prevents any later override (including
   from `wp-config.php`). The five constants are
   `SD_AI_AGENT_FEATURE_PLUGIN_BUILDER`,
   `SD_AI_AGENT_FEATURE_CUSTOM_TOOLS_CLI`,
   `SD_AI_AGENT_FEATURE_PLUGIN_STATE_CHANGES`,
   `SD_AI_AGENT_FEATURE_PLUGIN_INSTALL_FROM_URL`, and
   `SD_AI_AGENT_FEATURE_FILE_WRITE`.
2. **Strips the gated source files** from the zip via
   `.distignore-wporg`. The `PluginBuilder/`, `GeneratePluginAbility`,
   `SandboxActivatePluginAbility`, etc. files are not present in the
   submitted zip, so the WP.org reviewer can `grep` for the offending
   class names and see they are absent. (The `FileAbilities` and
   `GitAbilities` source files cannot be stripped because they also
   contain read-only abilities that remain available on WP.org; their
   write surfaces are gated at runtime via `Features::FILE_WRITE`.)

The WP.org Plugin Review team can therefore verify compliance with a
single command:

```bash
unzip -p superdav-ai-agent-X.Y.Z-wporg.zip superdav-ai-agent/superdav-ai-agent.php \
    | grep -E "SD_AI_AGENT_FEATURE_(PLUGIN_BUILDER|CUSTOM_TOOLS_CLI|PLUGIN_STATE_CHANGES|PLUGIN_INSTALL_FROM_URL|FILE_WRITE)"
# Expected: each constant is hard-defined to false with a "wporg-build:" comment.
```

### Producing the variants

```bash
bin/build.sh                  # full target (default)
bin/build.sh --target=wporg   # WP.org variant only
bin/build.sh --target=both    # both, in one run
```

The `release.yml` GitHub Actions workflow runs `--target=both` on every
tag push and uploads all three zips (`*.zip`,
`*-${VERSION}.zip`, `*-${VERSION}-wporg.zip`) to the GitHub release.

### When to update which list

If you add a new ability or feature that touches arbitrary code/CSS/JS
execution, plugin-state changes, or arbitrary-URL fetches, you must:

1. Add a runtime gate via `Features::is_enabled()` in the registration
   path (and, where the source file is dedicated to the feature, add it
   to `.distignore-wporg`).
2. Update the table above and the `bin/build.sh` `flags` array if you
   added a new feature flag.
3. If your change affects the WP.org-permissibility classification of an
   existing ability (e.g. you added a new write surface to a
   previously-read-only ability), revisit the table and move the row to
   the gated section.

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
WordPress 6.9 instance, then:

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
   - **Plugin name**: Superdav AI Agent
   - **Plugin description**: (paste the short description from `readme.txt`)
   - **Plugin ZIP**: Upload the ZIP built by `bin/build.sh` (see below)
4. Submit the form

### Build the submission ZIP

Always submit the **WP.org-compliant variant** — never the full GitHub-release
zip. The full zip contains features that violate the WP.org guidelines (see
the [Build Matrix](#build-matrix-full-vs-wporg-variants) above).

```bash
# From the repo root — builds the WP.org-compliant zip with the AI plugin
# builder, WP-CLI custom tools, autonomous plugin-state changes, and
# install-from-arbitrary-URL features stripped out.
bin/build.sh --target=wporg
# Output: superdav-ai-agent-1.2.0-wporg.zip
```

The ZIP must contain a single top-level directory named `superdav-ai-agent/`
with `superdav-ai-agent.php` at its root. `bin/build.sh` handles this
automatically.

### What to include in the submission notes

The review team reads these. Be specific:

```
Superdav AI Agent is an agentic AI assistant for WordPress built on the official
WordPress 6.9 AI Client SDK and Abilities API. It requires a connector plugin
(e.g., the OpenAI connector) to function — it does not bundle any AI provider
credentials or make API calls without explicit user configuration.

External API calls: The plugin calls the user's configured AI provider endpoint
(OpenAI, Anthropic, or any OpenAI-compatible URL). The endpoint URL and API key
are entered by the site administrator in Settings > AI Credentials. No data is
sent to any third-party server controlled by the plugin author.

This zip is built with `bin/build.sh --target=wporg`, which removes four
classes of feature that the standard GitHub release retains:

  1. AI plugin generation / sandbox activation / sandboxed update / hook
     scanning (would constitute "arbitrary script insertion" per Guideline 4).
  2. WP-CLI custom-tool type (shell `exec()` execution).
  3. Autonomous activate / deactivate / delete / switch / update of other
     plugins (per "Changing Active Plugins" — only WP.org-directory installs
     are exempt from the no-autonomous-state-change rule).
  4. Install-plugin-from-arbitrary-URL (e.g. GitHub release ZIPs).

The four `SD_AI_AGENT_FEATURE_*` constants gating these features are
hard-defined to `false` in the bundled `superdav-ai-agent.php`; the source
files for surfaces 1 and 2 are also stripped from the zip. Reviewers can
verify with:

  unzip -p superdav-ai-agent-X.Y.Z-wporg.zip superdav-ai-agent/superdav-ai-agent.php \
      | grep "SD_AI_AGENT_FEATURE_"

The plugin still installs from the WordPress.org directory by slug
(`sd-ai-agent/install-plugin`), which is the allowed exception under
"Changing Active Plugins".

PHP 8.2+ is required (strict types, enums). WordPress 6.9+ is required (AI
Client SDK, Abilities API).
```

---

## Step 2 — Wait for Approval

- Review typically takes **1–4 weeks**
- You will receive an email at your WordPress.org account address
- The review team may request changes — respond promptly via the ticket system
- Do not resubmit the same plugin while a review is pending

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
4. Run `bin/build.sh` to build the ZIP
5. Run `bin/deploy-wporg.sh --version X.Y.Z` (see below) or follow the manual steps above
6. Tag the release: `svn copy trunk/ tags/X.Y.Z -m "Tag vX.Y.Z"`

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

Our assets are already prepared in `assets/` in the Git repo. Copy them to SVN:

```bash
cd ~/svn/superdav-ai-agent

# Copy assets from Git repo
cp /path/to/superdav-ai-agent-repo/assets/banner-772x250.png  assets/
cp /path/to/superdav-ai-agent-repo/assets/icon-128x128.png    assets/
cp /path/to/superdav-ai-agent-repo/assets/icon-256x256.png    assets/

# Copy screenshots (rename to screenshot-N.png matching readme.txt order)
cp /path/to/superdav-ai-agent-repo/assets/screenshots/screenshot-1.png assets/screenshot-1.png
# … repeat for each screenshot

svn add assets/*
svn commit -m "Add plugin assets (banner, icon, screenshots)" \
    --username YOUR_WP_USERNAME
```

Screenshot filenames in SVN must be `screenshot-1.png`, `screenshot-2.png`, etc. —
not the descriptive names used in the Git repo.

---

## Automated SVN Deployment

`bin/deploy-wporg.sh` automates the trunk update and tagging steps.

```bash
# First deployment (after SVN checkout already exists at ~/svn/superdav-ai-agent)
bin/deploy-wporg.sh --version 1.2.0 --username YOUR_WP_USERNAME

# Subsequent releases
bin/deploy-wporg.sh --version 1.3.0 --username YOUR_WP_USERNAME
```

The script:
1. Builds the production ZIP via `bin/build.sh`
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
