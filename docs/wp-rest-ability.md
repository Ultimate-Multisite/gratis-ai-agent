# WP REST Ability

The `wp-rest/*` ability family lets the AI agent read and write any registered
WordPress REST endpoint through the internal dispatcher (`rest_do_request()`),
without an HTTP loopback. Use it when a third-party plugin exposes a REST route
and no dedicated ability exists for the operation you need. Prefer a named
ability (`posts`, `options`, `media`, etc.) whenever one is available — they
carry tighter parameter validation, richer error messages, and purpose-built
audit entries.

## The three abilities

| Ability | Purpose |
| --- | --- |
| `wp-rest/discover` | List registered namespaces and route paths (read-only, no side effects). |
| `wp-rest/inspect` | Return schema, accepted methods, argument definitions, and permission summary for one route. |
| `wp-rest/execute` | Dispatch a `GET`, `POST`, `PUT`, `PATCH`, or `DELETE` request to a registered route. |

## Self-documenting flow

The recommended pattern — discover, inspect, then execute — lets the agent
confirm a route exists and understand its contract before making a write or
destructive call.

**Scenario:** Clear the Elementor CSS cache via the Elementor REST API.

### Step 1 — Discover routes in the Elementor namespace

```json
// Ability: wp-rest/discover
{ "namespace": "elementor/v1" }
```

Response (excerpt):

```json
[
  { "route": "/elementor/v1/kit-data", "methods": ["GET", "POST"] },
  { "route": "/elementor/v1/reset-api-data", "methods": ["POST"] }
]
```

The agent identifies `/elementor/v1/reset-api-data` as a likely cache-clear
target and proceeds to inspect it.

### Step 2 — Inspect the target route

```json
// Ability: wp-rest/inspect
{ "route": "/elementor/v1/reset-api-data" }
```

Response (excerpt):

```json
{
  "route": "/elementor/v1/reset-api-data",
  "endpoints": [
    {
      "methods": ["POST"],
      "args": { "type": { "type": "string", "required": true } },
      "permission": "requires capability: manage_options"
    }
  ]
}
```

The agent learns the route requires a `type` body parameter and `manage_options`
capability.

### Step 3 — Execute

```json
// Ability: wp-rest/execute
{
  "method": "POST",
  "route": "/elementor/v1/reset-api-data",
  "params": { "type": "css" }
}
```

Response:

```json
{ "status": 200, "data": { "success": true }, "headers": {} }
```

Elementor's CSS cache is now cleared. The change is recorded in the `ChangesLog`
table under object type `wp_rest`.

## Security model

Four layers protect against privilege escalation and self-referential loops:

### 1. Namespace blocklist

The `sd-ai-agent/v1` namespace is permanently blocked. The agent cannot call its
own REST controller through `wp-rest/execute`, which would create an infinite
request loop.

**Default value:** `['sd-ai-agent/v1']`
**Filter:** `sd_ai_agent_wp_rest_namespace_blocklist`

### 2. Route blocklist

Specific `<METHOD> <route_prefix>` patterns are blocked unconditionally. The
prefix check covers the listed route and every sub-path below it.

**Default value:** `['DELETE /wp/v2/users', 'POST /wp/v2/users']`
**Filter:** `sd_ai_agent_wp_rest_route_blocklist`

### 3. Method classification and capability gate

Requests are classified into three levels, each requiring a minimum WordPress
capability:

| Classification | Methods | Required capability |
| --- | --- | --- |
| `read` | `GET`, `HEAD` | `manage_options` |
| `write` | `POST`, `PUT`, `PATCH` | `manage_options` |
| `destructive` | `DELETE` | `manage_network` (falls back to `manage_options` on single-site) |

The classification step is filterable; the capability check is not.
**Classification filter:** `sd_ai_agent_wp_rest_classify`

### 4. Loop guard

Before dispatching, the ability walks up to 30 frames of the call stack. If
`SdAiAgent\REST\AgentController` appears anywhere in the stack, the request is
refused with error code `wp_rest_loop_blocked`. This is a hard guard that cannot
be bypassed by filters.

## Filters reference

### `sd_ai_agent_wp_rest_namespace_blocklist`

Extend or replace the namespace blocklist.

```php
/**
 * @param string[] $blocklist Namespace strings to block (default: ['sd-ai-agent/v1']).
 * @return string[]
 */
add_filter(
    'sd_ai_agent_wp_rest_namespace_blocklist',
    static function ( array $blocklist ): array {
        // Also block a private internal API.
        $blocklist[] = 'my-plugin/internal';
        return $blocklist;
    }
);
```

### `sd_ai_agent_wp_rest_route_blocklist`

Extend or replace the route blocklist. Entries use `"<METHOD> <route_prefix>"` format.

```php
/**
 * @param string[] $blocklist Method+route patterns to block.
 * @return string[]
 */
add_filter(
    'sd_ai_agent_wp_rest_route_blocklist',
    static function ( array $blocklist ): array {
        // Block all DELETE calls under a custom namespace.
        $blocklist[] = 'DELETE /my-plugin/v1/orders';
        return $blocklist;
    }
);
```

### `sd_ai_agent_wp_rest_classify`

Override the access-level classification for a specific route or method
combination. Useful when a plugin registers a mutating `GET` endpoint or a safe
`DELETE` (rare but documented cases exist).

```php
/**
 * @param string $level  Default classification: 'read', 'write', or 'destructive'.
 * @param string $method Upper-cased HTTP method.
 * @param string $route  Route path (starts with /).
 * @return string
 */
add_filter(
    'sd_ai_agent_wp_rest_classify',
    static function ( string $level, string $method, string $route ): string {
        // The Elementor cache-clear endpoint mutates state — treat it as 'write'.
        if ( 'POST' === $method && '/elementor/v1/reset-api-data' === $route ) {
            return 'write';
        }
        return $level;
    },
    10,
    3
);
```

## Audit and secret scrubbing

Every non-read call (`write` or `destructive`) is logged to the `ChangesLog`
database table. The record contains:

| Field | Value stored |
| --- | --- |
| `object_type` | `wp_rest` |
| `object_title` | `<METHOD> <route>` — e.g. `POST /elementor/v1/reset-api-data` |
| `after_value` | JSON-encoded `params`, with sensitive keys redacted |
| `revertable` | `false` |
| `session_id` | Active agent session ID |

**Secret scrubbing:** before the `params` array is encoded for the log, any key
whose name matches `/(secret|token|password|key|auth)/i` has its value replaced
with `***`. Nested arrays are scrubbed recursively.

To audit a call, query the `ChangesLog` table in Settings → Agent Logs, or via:

```bash
wp post list --post_type=sd_ai_agent_changes_log --fields=ID,post_title
```

Read-only `GET` and `HEAD` requests are not logged (noise-reduction policy).

## Differences from `wp-cli/execute`

| Aspect | `wp-rest/execute` | `wp-cli/execute` |
| --- | --- | --- |
| Execution model | In-process via `rest_do_request()` | Subprocess via `shell_exec` + `wp` binary |
| Binary discovery | Not required | Requires WP-CLI binary (`wp`) |
| Permission enforcement | Runs the route's own `permission_callback` | Runs as the CLI user; bypasses `current_user_can` unless coded in the command |
| Output format | Structured JSON response | Raw CLI output (text, JSON, CSV depending on the command) |
| Multipart / file upload | Not supported — use `media/upload` | Supported via `wp media import` |
| Audit trail | `ChangesLog` with secret scrubbing | `ChangesLog` under `wp_cli` object type |
| Blocked by loop guard | Yes | No (separate process) |

## Multisite

`rest_do_request()` dispatches against the current WordPress site context —
whichever site `switch_to_blog()` last activated, or the main site if none. The
`wp-rest/execute` ability does not call `switch_to_blog()` on your behalf; if
you need to operate on a subsite, switch context before the ability is invoked.
Network-admin REST routes are accessible only when `is_multisite()` is true and
the current user holds `manage_network`.

## Why we exclude file uploads

The WordPress REST API handles file uploads as `multipart/form-data` requests.
The internal dispatcher (`WP_REST_Request`) accepts structured JSON, not binary
multipart streams. Accepting raw `multipart/form-data` through the ability input
schema would require base64 encoding, stream buffering, and content-type
negotiation that belongs in a dedicated tool.

Use the `media/upload` ability for file uploads. It handles chunking, MIME-type
validation, and the correct content-type handshake. The `wp-rest/discover`
ability hides `/wp/v2/media POST` from its route list for the same reason —
surfacing it would mislead the agent into attempting an upload that would fail.

## Related

- Parent meta-issue: [#1685](https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1685)
- Source file: `includes/Abilities/WpRestAbilities.php`
- Test file: `tests/SdAiAgent/Abilities/WpRestAbilitiesTest.php`
- Sibling document: `docs/wp-cli-discovery.md` (WP-CLI binary discovery for `wp-cli/execute`)
