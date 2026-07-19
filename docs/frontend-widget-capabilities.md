# Frontend widget capabilities

Issue #1953 reviewed the frontend floating widget gate against the REST chat
gate and the per-ability dual gate in `ToolCapabilities::current_user_can()`.
The widget can start a chat from a public page, but tool execution still passes
through the same per-tool capability layer and core-capability layer used by the
admin chat UI.

## Recommendation

The frontend widget should require `RolePermissions::current_user_has_chat_access()`
instead of `manage_options` so the rendered UI matches the REST chat endpoints
that it calls. This keeps the default administrator path, allows configured
editor/author chat access, and leaves each ability constrained by
`ToolCapabilities::current_user_can()`: the per-tool cap must be granted and the
mapped WordPress core capability must also pass. Do not lower the widget to a
single broad core cap such as `edit_posts`; that would hide admin-only ability
differences and would not represent the configured role-permission model.

## Cache-bust strategy

Reflector page refreshes use two cache bypasses:

- `fetchFreshPage()` sends `cache: 'no-store'` and `X-Sd-Ai-Agent-Reflector: 1`.
- `fetchFreshPage()` appends a timestamp `_=` query parameter to bypass page
  caches/CDNs that key by URL.

The PHP `CachePolicy` handler sends `Cache-Control: no-store, no-cache,
must-revalidate` plus `X-Sd-Ai-Agent-Cache-Policy: no-store` on requests that
carry `X-Sd-Ai-Agent-Reflector: 1`.

Compatibility notes:

- WP Super Cache and W3 Total Cache default page caches key by URL including the
  query string, so the unique `_=` parameter bypasses the cached HTML.
- Cloudflare Cache Rules using the default WordPress recipe and Nginx FastCGI
  cache using the WordPress recipe also bypass unique query-string URLs by
  default.
- If a cache layer ignores query strings, configure it to bypass when the
  request has header `X-Sd-Ai-Agent-Reflector: 1`.

## Ability gate review

Evidence sources:

- Frontend render gate before this change: `includes/Admin/FloatingWidget.php:79-82` used `manage_options`.
- REST chat gate: `includes/REST/PermissionTrait.php:42-60` calls `RolePermissions::current_user_has_chat_access()`.
- Role chat access: `includes/Core/RolePermissions.php:154-174` grants logged-in users by role configuration, with administrators always allowed.
- Per-ability dual gate: `includes/Abilities/ToolCapabilities.php:372-399` requires the per-tool cap plus every mapped core cap.

| Ability | Required WP core cap(s) | Verdict | Evidence |
|---|---|---|---|
| `sd-ai-agent/get-option` | `manage_options` | admin-only | `ToolCapabilities.php:77` |
| `sd-ai-agent/update-option` | `manage_options` | admin-only | `ToolCapabilities.php:78` |
| `sd-ai-agent/delete-option` | `manage_options` | admin-only | `ToolCapabilities.php:79` |
| `sd-ai-agent/list-options` | `manage_options` | admin-only | `ToolCapabilities.php:80` |
| `sd-ai-agent/list-users` | `list_users` | admin-only | `ToolCapabilities.php:83` |
| `sd-ai-agent/create-user` | `create_users` + `promote_users` | admin-only | `ToolCapabilities.php:88` |
| `sd-ai-agent/update-user-role` | `promote_users` + `edit_users` | admin-only | `ToolCapabilities.php:92` |
| `sd-ai-agent/get-plugins` | `activate_plugins` | admin-only | `ToolCapabilities.php:95` |
| `sd-ai-agent/recommend-plugin` | `activate_plugins` | admin-only | `ToolCapabilities.php:96` |
| `sd-ai-agent/search-plugin-directory` | `install_plugins` | admin-only | `ToolCapabilities.php:97` |
| `sd-ai-agent/list-plugin-updates` | `update_plugins` | admin-only | `ToolCapabilities.php:98` |
| `sd-ai-agent/install-plugin` | `install_plugins` | admin-only | `ToolCapabilities.php:99` |
| `sd-ai-agent/install-plugin-from-url` | `install_plugins` | admin-only | `ToolCapabilities.php:100` |
| `sd-ai-agent/update-plugin` | `update_plugins` | admin-only | `ToolCapabilities.php:101` |
| `sd-ai-agent/activate-plugin` | `activate_plugins` | admin-only | `ToolCapabilities.php:102` |
| `sd-ai-agent/deactivate-plugin` | `activate_plugins` | admin-only | `ToolCapabilities.php:103` |
| `sd-ai-agent/switch-plugin` | `activate_plugins` | admin-only | `ToolCapabilities.php:104` |
| `sd-ai-agent/delete-plugin` | `delete_plugins` | admin-only | `ToolCapabilities.php:105` |
| `sd-ai-agent/list-modified-plugins` | `activate_plugins` | admin-only | `ToolCapabilities.php:106` |
| `sd-ai-agent/get-plugin-download-url` | `activate_plugins` | admin-only | `ToolCapabilities.php:107` |
| `sd-ai-agent/generate-plugin` | `install_plugins` | admin-only | `ToolCapabilities.php:108` |
| `sd-ai-agent/sandbox-test-plugin` | `install_plugins` | admin-only | `ToolCapabilities.php:109` |
| `sd-ai-agent/sandbox-activate-plugin` | `install_plugins` | admin-only | `ToolCapabilities.php:110` |
| `sd-ai-agent/update-plugin-sandboxed` | `update_plugins` | admin-only | `ToolCapabilities.php:111` |
| `sd-ai-agent/scan-plugin-hooks` | `install_plugins` | admin-only | `ToolCapabilities.php:112` |
| `sd-ai-agent/scan-theme-hooks` | `install_plugins` | admin-only | `ToolCapabilities.php:113` |
| `sd-ai-agent/get-themes` | `edit_theme_options` | admin-only | `ToolCapabilities.php:116` |
| `sd-ai-agent/activate-theme` | `switch_themes` | admin-only | `ToolCapabilities.php:117` |
| `sd-ai-agent/get-global-styles` | `edit_theme_options` | admin-only | `ToolCapabilities.php:118` |
| `sd-ai-agent/update-global-styles` | `edit_theme_options` | admin-only | `ToolCapabilities.php:119` |
| `sd-ai-agent/get-theme-json` | `edit_theme_options` | admin-only | `ToolCapabilities.php:120` |
| `sd-ai-agent/reset-global-styles` | `edit_theme_options` | admin-only | `ToolCapabilities.php:121` |
| `sd-ai-agent/inject-custom-css` | `edit_theme_options` | admin-only | `ToolCapabilities.php:122` |
| `sd-ai-agent/curated-block-patterns` | `edit_theme_options` | admin-only | `ToolCapabilities.php:123` |
| `sd-ai-agent/theme-json-presets` | `edit_theme_options` | admin-only | `ToolCapabilities.php:124` |
| `sd-ai-agent/set-site-logo` | `edit_theme_options` | admin-only | `ToolCapabilities.php:125` |
| `sd-ai-agent/scaffold-block-theme` | `edit_themes` | admin-only | `ToolCapabilities.php:126` |
| `sd-ai-agent/render-design-previews` | `edit_theme_options` | admin-only | `ToolCapabilities.php:127` |
| `sd-ai-agent/validate-palette-contrast` | `edit_theme_options` | admin-only | `ToolCapabilities.php:128` |
| `sd-ai-agent/compile-design-tokens` | `edit_theme_options` | admin-only | `ToolCapabilities.php:136` |
| `sd-ai-agent/generate-logo-svg` | `upload_files` | frontend-safe | `ToolCapabilities.php:129` |
| `sd-ai-agent/list-menus` | `edit_theme_options` | admin-only | `ToolCapabilities.php:132` |
| `sd-ai-agent/get-menu` | `edit_theme_options` | admin-only | `ToolCapabilities.php:133` |
| `sd-ai-agent/create-menu` | `edit_theme_options` | admin-only | `ToolCapabilities.php:134` |
| `sd-ai-agent/delete-menu` | `edit_theme_options` | admin-only | `ToolCapabilities.php:135` |
| `sd-ai-agent/add-menu-item` | `edit_theme_options` | admin-only | `ToolCapabilities.php:136` |
| `sd-ai-agent/remove-menu-item` | `edit_theme_options` | admin-only | `ToolCapabilities.php:137` |
| `sd-ai-agent/assign-menu-location` | `edit_theme_options` | admin-only | `ToolCapabilities.php:138` |
| `sd-ai-agent/generate-menu-page` | `edit_theme_options` | admin-only | `ToolCapabilities.php:139` |
| `sd-ai-agent/get-post` | `edit_posts` | frontend-safe | `ToolCapabilities.php:142` |
| `sd-ai-agent/list-posts` | `edit_posts` | frontend-safe | `ToolCapabilities.php:143` |
| `sd-ai-agent/create-post` | `edit_posts` | frontend-safe | `ToolCapabilities.php:144` |
| `sd-ai-agent/update-post` | `edit_posts` | frontend-safe | `ToolCapabilities.php:145` |
| `sd-ai-agent/append-post-content` | `edit_posts` | frontend-safe | `ToolCapabilities.php:146` |
| `sd-ai-agent/batch-create-posts` | `edit_posts` | frontend-safe | `ToolCapabilities.php:147` |
| `sd-ai-agent/delete-post` | `delete_posts` | frontend-safe | `ToolCapabilities.php:148` |
| `sd-ai-agent/set-featured-image` | `edit_posts` | frontend-safe | `ToolCapabilities.php:149` |
| `sd-ai-agent/revert-to-revision` | `edit_posts` | frontend-safe | `ToolCapabilities.php:150` |
| `sd-ai-agent/generate-title` | `edit_posts` | frontend-safe | `ToolCapabilities.php:151` |
| `sd-ai-agent/generate-excerpt` | `edit_posts` | frontend-safe | `ToolCapabilities.php:152` |
| `sd-ai-agent/summarize-content` | `edit_posts` | frontend-safe | `ToolCapabilities.php:153` |
| `sd-ai-agent/review-block` | `edit_posts` | frontend-safe | `ToolCapabilities.php:154` |
| `sd-ai-agent/generate-alt-text` | `edit_posts` | frontend-safe | `ToolCapabilities.php:155` |
| `sd-ai-agent/generate-image-prompt` | `edit_posts` | frontend-safe | `ToolCapabilities.php:156` |
| `sd-ai-agent/create-contact-form` | `edit_posts` | frontend-safe | `ToolCapabilities.php:157` |
| `sd-ai-agent/markdown-to-blocks` | `edit_posts` | frontend-safe | `ToolCapabilities.php:160` |
| `sd-ai-agent/parse-block-content` | `edit_posts` | frontend-safe | `ToolCapabilities.php:161` |
| `sd-ai-agent/validate-block-content` | `edit_posts` | frontend-safe | `ToolCapabilities.php:162` |
| `sd-ai-agent/list-block-types` | `edit_posts` | frontend-safe | `ToolCapabilities.php:163` |
| `sd-ai-agent/get-block-type` | `edit_posts` | frontend-safe | `ToolCapabilities.php:164` |
| `sd-ai-agent/list-block-patterns` | `edit_posts` | frontend-safe | `ToolCapabilities.php:165` |
| `sd-ai-agent/list-block-templates` | `edit_posts` | frontend-safe | `ToolCapabilities.php:166` |
| `sd-ai-agent/create-block-content` | `edit_posts` | frontend-safe | `ToolCapabilities.php:167` |
| `sd-ai-agent/get-page-blocks` | `edit_posts` | frontend-safe | `ToolCapabilities.php:168` |
| `sd-ai-agent/get-site-block-usage` | `edit_posts` | frontend-safe | `ToolCapabilities.php:169` |
| `sd-ai-agent/edit-block-tree` | `edit_posts` | frontend-safe | `ToolCapabilities.php:170` |
| `sd-ai-agent/update-blocks` | `edit_posts` | frontend-safe | `ToolCapabilities.php:171` |
| `sd-ai-agent/rewrite-post-blocks` | `edit_posts` | frontend-safe | `ToolCapabilities.php:172` |
| `sd-ai-agent/insert-pattern` | `edit_posts` | frontend-safe | `ToolCapabilities.php:173` |
| `sd-ai-agent/replace-block-range` | `edit_posts` | frontend-safe | `ToolCapabilities.php:174` |
| `sd-ai-agent/scan-storage-modes` | `edit_posts` | frontend-safe | `ToolCapabilities.php:175` |
| `sd-ai-agent/list-media` | `upload_files` | frontend-safe | `ToolCapabilities.php:178` |
| `sd-ai-agent/upload-media` | `upload_files` | frontend-safe | `ToolCapabilities.php:179` |
| `sd-ai-agent/upload-media-from-url` | `upload_files` | frontend-safe | `ToolCapabilities.php:180` |
| `sd-ai-agent/delete-media` | `upload_files` | needs-narrower-cap | `ToolCapabilities.php:181` |
| `sd-ai-agent/import-base64-image` | `upload_files` | frontend-safe | `ToolCapabilities.php:182` |
| `sd-ai-agent/generate-image` | `upload_files` | frontend-safe | `ToolCapabilities.php:183` |
| `sd-ai-agent/stock-image` | `upload_files` | frontend-safe | `ToolCapabilities.php:184` |
| `sd-ai-agent/list-terms` | `manage_categories` | frontend-safe | `ToolCapabilities.php:187` |
| `sd-ai-agent/list-taxonomies` | `manage_options` | admin-only | `ToolCapabilities.php:188` |
| `sd-ai-agent/register-taxonomy` | `manage_options` | admin-only | `ToolCapabilities.php:189` |
| `sd-ai-agent/delete-taxonomy` | `manage_options` | admin-only | `ToolCapabilities.php:190` |
| `sd-ai-agent/list-post-types` | `manage_options` | admin-only | `ToolCapabilities.php:191` |
| `sd-ai-agent/register-post-type` | `manage_options` | admin-only | `ToolCapabilities.php:192` |
| `sd-ai-agent/delete-post-type` | `manage_options` | admin-only | `ToolCapabilities.php:193` |
| `sd-ai-agent/file-read` | `manage_options` | admin-only | `ToolCapabilities.php:196` |
| `sd-ai-agent/file-list` | `manage_options` | admin-only | `ToolCapabilities.php:197` |
| `sd-ai-agent/file-search` | `manage_options` | admin-only | `ToolCapabilities.php:198` |
| `sd-ai-agent/content-search` | `manage_options` | admin-only | `ToolCapabilities.php:199` |
| `sd-ai-agent/file-write` | `manage_options` + `edit_files` | admin-only | `ToolCapabilities.php:200` |
| `sd-ai-agent/file-edit` | `manage_options` + `edit_files` | admin-only | `ToolCapabilities.php:201` |
| `sd-ai-agent/file-delete` | `manage_options` + `edit_files` | admin-only | `ToolCapabilities.php:202` |
| `sd-ai-agent/git-list` | `manage_options` | admin-only | `ToolCapabilities.php:205` |
| `sd-ai-agent/git-diff` | `manage_options` | admin-only | `ToolCapabilities.php:206` |
| `sd-ai-agent/git-package-summary` | `manage_options` | admin-only | `ToolCapabilities.php:207` |
| `sd-ai-agent/git-snapshot` | `manage_options` | admin-only | `ToolCapabilities.php:208` |
| `sd-ai-agent/git-restore` | `manage_options` + `edit_files` | admin-only | `ToolCapabilities.php:209` |
| `sd-ai-agent/git-revert-package` | `manage_options` + `edit_files` | admin-only | `ToolCapabilities.php:210` |
| `sd-ai-agent/check-disk-space` | `manage_options` | admin-only | `ToolCapabilities.php:213` |
| `sd-ai-agent/check-performance` | `manage_options` | admin-only | `ToolCapabilities.php:214` |
| `sd-ai-agent/check-security` | `manage_options` | admin-only | `ToolCapabilities.php:215` |
| `sd-ai-agent/check-plugin-updates` | `update_plugins` | admin-only | `ToolCapabilities.php:216` |
| `sd-ai-agent/scan-php-error-log` | `manage_options` | admin-only | `ToolCapabilities.php:217` |
| `sd-ai-agent/site-health-summary` | `manage_options` | admin-only | `ToolCapabilities.php:218` |
| `sd-ai-agent/detect-fresh-install` | `manage_options` | admin-only | `ToolCapabilities.php:219` |
| `sd-ai-agent/site-loopback-check` | `manage_options` | admin-only | `ToolCapabilities.php:220` |
| `sd-ai-agent/seo-audit-url` | `edit_posts` | frontend-safe | `ToolCapabilities.php:223` |
| `sd-ai-agent/seo-analyze-content` | `edit_posts` | frontend-safe | `ToolCapabilities.php:224` |
| `sd-ai-agent/content-analyze` | `edit_posts` | frontend-safe | `ToolCapabilities.php:225` |
| `sd-ai-agent/content-performance-report` | `edit_posts` | frontend-safe | `ToolCapabilities.php:226` |
| `sd-ai-agent/fetch-url` | `manage_options` | admin-only | `ToolCapabilities.php:227` |
| `sd-ai-agent/analyze-headers` | `manage_options` | admin-only | `ToolCapabilities.php:228` |
| `sd-ai-agent/knowledge-search` | `manage_options` | admin-only | `ToolCapabilities.php:231` |
| `sd-ai-agent/memory-save` | `manage_options` | admin-only | `ToolCapabilities.php:232` |
| `sd-ai-agent/memory-list` | `manage_options` | admin-only | `ToolCapabilities.php:233` |
| `sd-ai-agent/memory-delete` | `manage_options` | admin-only | `ToolCapabilities.php:234` |
| `sd-ai-agent/skill-load` | `manage_options` | admin-only | `ToolCapabilities.php:235` |
| `sd-ai-agent/skill-list` | `manage_options` | admin-only | `ToolCapabilities.php:236` |
| `sd-ai-agent/ga-traffic-summary` | `manage_options` | admin-only | `ToolCapabilities.php:239` |
| `sd-ai-agent/ga-top-pages` | `manage_options` | admin-only | `ToolCapabilities.php:240` |
| `sd-ai-agent/ga-realtime` | `manage_options` | admin-only | `ToolCapabilities.php:241` |
| `sd-ai-agent/gsc-site-summary` | `manage_options` | admin-only | `ToolCapabilities.php:242` |
| `sd-ai-agent/gsc-top-queries` | `manage_options` | admin-only | `ToolCapabilities.php:243` |
| `sd-ai-agent/gsc-query-details` | `manage_options` | admin-only | `ToolCapabilities.php:244` |
| `sd-ai-agent/gsc-page-performance` | `manage_options` | admin-only | `ToolCapabilities.php:245` |
| `sd-ai-agent/internet-search` | `manage_options` | admin-only | `ToolCapabilities.php:248` |
| `sd-ai-agent/resolve-url` | `manage_options` | admin-only | `ToolCapabilities.php:249` |
| `sd-ai-agent/configure-search-provider` | `manage_options` | admin-only | `ToolCapabilities.php:250` |
| `sd-ai-agent/navigate` | `manage_options` | admin-only | `ToolCapabilities.php:253` |
| `sd-ai-agent/get-page-html` | `manage_options` | admin-only | `ToolCapabilities.php:254` |
| `sd-ai-agent/db-query` | `manage_options` + `unfiltered_html` | admin-only | `ToolCapabilities.php:257` |
| `sd-ai-agent/run-php` | `manage_options` + `update_core` + `unfiltered_html` | admin-only | `ToolCapabilities.php:265` |
| `sd-ai-agent/report-inability` | per-tool cap only; no core cap | frontend-safe | `ToolCapabilities.php:281-285` |

`sd-ai-agent/delete-media` is the only reviewed ability marked
`needs-narrower-cap`: WordPress core maps it to `upload_files`, but deleting
attachments is more destructive than upload/list/generation. The current dual
gate still protects it with the per-tool `sd_ai_agent_tool_delete_media` layer;
the verdict documents that a future hardening pass should consider a stricter
core cap or attachment ownership check before granting it broadly to frontend
roles.
