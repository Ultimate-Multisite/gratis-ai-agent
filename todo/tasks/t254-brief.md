# t254 — Tier 3.11: SSRF guard for URL sideload paths

## Pre-flight

- [x] Memory recall: `ssrf rfc1918 link-local cloud metadata wp http` → 0 hits
- [x] Discovery pass: 0 open PRs touch image/media download paths
- [x] File refs verified: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-media-manager.php` (681 LOC, SSRF block); Superdav callers — `git grep` shows `includes/Abilities/GenerateLogoSvgAbility.php`, `includes/Abilities/ImageAbilities.php`, `includes/Abilities/ImageAbilities/GenerateImageAbility.php`, `includes/Abilities/ImageSources/AiGenerateSource.php`, `includes/Abilities/ImageSources/ImageSourceFactory.php`, `includes/Abilities/ImageSources/OpenverseImageSource.php`, `includes/Abilities/ImageSources/PixabayImageSource.php`, `includes/Abilities/MediaAbilities.php`, `includes/Abilities/PluginDownloadAbilities.php`, `includes/Abilities/SeoAbilities.php`
- [x] Tier: `tier:thinking` — security primitive, wired into 10+ call sites, must fail closed
- [x] Seeded draft PR decision: skipped — open as security-labelled PR with explicit threat model in body

## Origin

- **Created:** 2026-05-22
- **Session:** opencode interactive (block-mcp adoption review)
- **Parent task:** none (sibling group t244–t254)
- **Conversation context:** Tier 3 item 3 — defence-in-depth against agent-driven SSRF via URL inputs to image/plugin/media downloads.

## What

Add a shared SSRF guard that every URL-sideload call site runs **before** issuing `wp_safe_remote_get` / `download_url` / `media_handle_sideload`.

The guard blocks:

- RFC1918 (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`).
- Loopback (`127.0.0.0/8`, `::1`).
- Link-local (`169.254.0.0/16`, `fe80::/10`).
- Cloud metadata services (`169.254.169.254`, GCP `metadata.google.internal`, Azure IMDS).
- Non-public unicast (multicast, broadcast, `0.0.0.0/8`, IPv6 ULA `fc00::/7`).

Performs DNS resolution **before** the HTTP call and re-checks each resolved A/AAAA record (TOCTOU defence: an attacker-controlled hostname could resolve to a public IP on first lookup and an internal IP on the second). The HTTP client must be pinned to the resolved IP, with `Host:` header preserved.

## Why

Today, abilities accept URLs from agents and pass them to WordPress download helpers. WP's default safeguards reject `localhost`/`127.0.0.1` by name but not by resolved IP. Without a real guard, an agent prompt-injected via tool output can be coerced into:

- Probing the cloud-metadata service for IAM credentials.
- Internal port-scanning.
- Reading the dev WordPress install's wp-admin via the production server's network position.

This is **security-sensitive** — label the PR `security`.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-media-manager.php` (681 LOC) — the SSRF block lives in the URL-sideload path. Note the upstream uses a 25 MB cap and 10s timeout default. Both filterable.

## Files to modify / create

- **New:** `includes/Core/Net/SsrfGuard.php` — `assert_safe_url( string $url ): true|WP_Error`, plus filterable allowed/blocked ranges via `sd_ai_agent_ssrf_blocked_ranges` and `sd_ai_agent_ssrf_allow_hosts`.
- **New:** `includes/Core/Net/SafeHttpClient.php` — thin wrapper around `wp_safe_remote_get` that pre-resolves DNS, runs `SsrfGuard`, pins the IP, preserves Host header, applies 25 MB cap / 10s default timeout.
- **Modify (wire-through):** all 10 call sites listed in pre-flight above. Each should swap `wp_safe_remote_get(...)` / `download_url(...)` for the new safe wrapper.
- **New:** `tests/SdAiAgent/Core/Net/SsrfGuardTest.php` — every blocked range + cloud-metadata + RFC1918 IPv4 and IPv6 ULA cases; allow-list filter override.
- **New:** `tests/SdAiAgent/Core/Net/SafeHttpClientTest.php` — happy path against `example.com`, TOCTOU rebinding (mocked DNS returning two different IPs), timeout enforcement.

## Acceptance criteria

1. `assert_safe_url( "http://169.254.169.254/" )` → `WP_Error('ssrf_blocked')` with `data.ip` populated.
2. Same for `http://10.0.0.5/`, `http://[fe80::1]/`, `http://localhost/`, `http://127.0.0.1/`, `http://[::1]/`, `http://0.0.0.0/`.
3. `assert_safe_url( "https://example.com/" )` → `true`.
4. Hostname that resolves to a public IP at first lookup and an RFC1918 IP at second is blocked (mock-injected via filter or fixture DNS).
5. URL > 25 MB content-length is rejected without download body completing.
6. 10s timeout enforced (mocked via filter `sd_ai_agent_safe_http_timeout`).
7. Filter `sd_ai_agent_ssrf_blocked_ranges` adds a custom CIDR; `sd_ai_agent_ssrf_allow_hosts` whitelists an internal hostname for testing (off by default).
8. All 10 call sites updated; grep for remaining bare `wp_safe_remote_get` calls in `includes/Abilities/` returns zero.
9. Full PHPUnit + phpstan + lint clean. Add `security` label to the PR.

## Verification

`npm run verify`. Plus a targeted security smoke:

```bash
wp eval '
  $g = new SdAiAgent\Core\Net\SsrfGuard();
  foreach ( ["http://169.254.169.254/", "http://10.0.0.1/", "http://localhost/", "https://example.com/"] as $u ) {
    $r = $g->assert_safe_url( $u );
    echo $u . " -> " . ( is_wp_error( $r ) ? $r->get_error_code() : "ok" ) . PHP_EOL;
  }
'
```

## Tier rationale

`tier:thinking`. Security primitive — fail-closed correctness, TOCTOU defence, multi-call-site refactor. Easy to ship a broken guard if not careful.

## Dependencies

- **Blocked by:** none.
- **Standalone-shippable.** Independent of the block-mcp adoption work; included here because it's a borrow from the same upstream codebase.

## PR conventions

Leaf — `Resolves #<this-issue>`. Label PR `security`.
