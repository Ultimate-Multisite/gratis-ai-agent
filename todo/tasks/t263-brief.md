# t263 — Wave 2.9: Unified `upload_media` ability — path / URL / base64

## Pre-flight

- [x] Memory recall: `upload-media-from-url import-base64-image unified` → 0 hits
- [x] Discovery pass: existing surface — `includes/Abilities/MediaAbilities.php` (URL upload), `includes/Abilities/ImageAbilities.php` (base64 import). Both currently call `wp_safe_remote_get` / `media_handle_sideload`, now routed through the SsrfGuard from t254.
- [x] File refs verified — block-mcp source: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-media-manager.php` (681 LOC, unified surface)
- [x] Tier: `tier:standard` — convergence work; all three code paths exist, this collapses them behind one schema
- [x] Seeded draft PR decision: skipped

## Origin

- **Created:** 2026-05-23
- **Session:** opencode interactive (block-mcp wave-2 adoption)
- **Parent task:** #1739
- **Conversation context:** Wave 2 child 9/10. Today agents must pick between `upload-media-from-url` and `import-base64-image`. Agents frequently pick wrong (or invent a third name). A single `upload-media` with a `source` discriminator removes tool-pick ambiguity and reduces the surface area.

## What

Add a `sd-ai-agent/upload-media` ability:

```json
{
  "source": "url",                   // "url" | "base64" | "path"
  "url": "https://example.com/img.jpg",   // when source=url
  "data_base64": "iVBORw0KGgo...",        // when source=base64
  "mime_type": "image/png",               // required for base64; optional for url (sniffed)
  "filename": "img.png",                  // optional; default sniffed/generated
  "path": "/srv/uploads/staged/x.jpg",    // when source=path; MUST be inside ABSPATH (path-traversal guard)
  "post_id": 123,                          // optional, attach to post
  "alt_text": "...",                       // optional
  "title": "...",                          // optional
  "caption": "...",                        // optional
  "description": "..."                     // optional
}
```

Returns:

```json
{
  "attachment_id": 4567,
  "url": "https://site.test/wp-content/uploads/2026/05/img.png",
  "mime_type": "image/png",
  "filesize_bytes": 84321,
  "width": 800,
  "height": 600,
  "source": "url"   // echoes input discriminator
}
```

Branching:

- `source: "url"` → SsrfGuard + `download_url` → `media_handle_sideload`. (Wraps existing MediaAbilities path.)
- `source: "base64"` → decode, write to temp file with mime-validated extension, `media_handle_sideload`. (Wraps existing ImageAbilities path.)
- `source: "path"` → assert `realpath( $path )` starts with `realpath( ABSPATH )` (no escape via `../`); `media_handle_sideload` from that path. New path; tighter than URL/base64.

Old endpoints (`upload-media-from-url`, `import-base64-image`) stay registered but emit a `_doing_it_wrong` deprecation notice that the unified `upload-media` is preferred. Keep them functional for one release cycle.

## Why

Tool-pick ambiguity. Three names for one operation makes agents brittle. The block-mcp upstream has the unified surface as the only option, with the discriminator. Convergence is the readability win.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-media-manager.php` (681 LOC). GPL-2.0-or-later. Note their version doesn't split into legacy + unified — we keep legacy live for one release for backward compat.

## Files to modify / create

- **New:** `includes/Abilities/UploadMediaAbility.php` — single class, dispatches on `source`, calls into existing MediaAbilities/ImageAbilities helpers (refactor those to expose internals as static helpers if needed).
- **Modify:** `includes/Abilities/MediaAbilities.php` — extract URL-upload core into a public static helper; keep `upload-media-from-url` as a thin wrapper that emits `_doing_it_wrong`.
- **Modify:** `includes/Abilities/ImageAbilities.php` — same pattern for base64.
- **New:** `includes/Core/Net/AbsPathGuard.php` — `assert_inside_abspath( string $path ): true|WP_Error` for the new `source: "path"` branch.
- **New:** `tests/SdAiAgent/Abilities/UploadMediaAbilityTest.php` — covers all three sources, path-traversal rejection, base64-mime mismatch (`mime_type: image/png` but data is JPEG bytes → reject), missing source discriminator, SSRF block on URL (t254 integration).
- **Modify:** `docs/abilities-reference.md` (if exists) to note unification + legacy deprecation.

## Acceptance criteria

1. `source: "url"` with a public URL → attachment created, returned URL resolves.
2. `source: "base64"` with a valid PNG payload + `mime_type: image/png` → attachment created.
3. `source: "base64"` with mime/data mismatch → `WP_Error('mime_data_mismatch', ...)`.
4. `source: "path"` with `/srv/uploads/staged/x.jpg` (inside ABSPATH) → attachment created.
5. `source: "path"` with `../../etc/passwd` → `WP_Error('path_escape', ...)`.
6. `source: "url"` with `http://169.254.169.254/` → blocked by SsrfGuard (t254).
7. Missing `source` → `WP_Error('source_required', ...)`.
8. Old `upload-media-from-url` ability still callable, emits `_doing_it_wrong` once per request.
9. `post_id` provided → attachment is set as child of post (`post_parent = $post_id`).
10. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
wp eval '
  $r = wp_get_ability("sd-ai-agent/upload-media")->execute([
    "source" => "url",
    "url"    => "https://upload.wikimedia.org/wikipedia/commons/2/2f/Culture.png",
  ]);
  echo wp_json_encode($r) . PHP_EOL;
'
```

## Tier rationale

`tier:standard` — convergence/refactor, no new core logic, three well-understood code paths behind a discriminator.

## Dependencies

- **Blocked by:** t254 (SSRF guard) for the URL path — already merged via PR #1721, so this is satisfied.
- **Related:** old `upload-media-from-url` and `import-base64-image` abilities stay live with deprecation.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #1739`.
