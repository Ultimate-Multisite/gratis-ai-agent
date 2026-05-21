---
name: wp-rest-fallback
description: "Use when no purpose-built ability exists for a task but a registered WordPress REST endpoint provides the needed behaviour. Covers discovery, schema inspection, and dispatch via the wp-rest/* abilities. Prefer dedicated abilities (posts, options, media, …) whenever they exist."
compatibility: "Targets WordPress 7.0+ via the plugin's wp-rest abilities. No external dependencies."
---

# WP REST Fallback

## When to use

Before reaching for `wp-rest/*`, check the following in order:

- [ ] Is there a dedicated ability for this task? (`posts`, `options`, `media`, `terms`, `comments`, `nav-menus`, …). If yes, **use that instead** — it has better validation and error messages.
- [ ] Does the target route belong to a third-party plugin or an unexposed core endpoint?
- [ ] Is the data available through a WP-CLI command via `wp-cli/execute`? REST may be simpler, but CLI output is sometimes easier to parse.
- [ ] Is the operation a plain CRUD action on a standard post type? The `posts` ability handles this more safely.

If none of the above applies and a registered REST route exists for what you
need, proceed with the `wp-rest/*` abilities.

## When NOT to use

- **Never call `sd-ai-agent/v1/*` routes.** That namespace is permanently blocked to prevent the agent from recursively triggering itself.
- **Never use `wp-rest/execute` for file uploads.** The internal dispatcher cannot handle `multipart/form-data`. Use the `media/upload` ability instead.
- **Never use this to escalate user privileges.** The ability runs every route's own `permission_callback` as the current user. Attempting to create or modify users via blocked routes (`POST /wp/v2/users`, `DELETE /wp/v2/users`) will be refused.
- **Avoid using `wp-rest/execute` on untrusted output.** If the route or params were derived from untrusted content (e.g. a public comment), validate and sanitize before dispatching.

## Procedure

Follow the discover → inspect → execute pattern. Do not skip ahead to execute
without inspecting the route first, unless you have already inspected it in the
current session.

### 1. Discover

Call `wp-rest/discover` with the plugin's namespace to list available routes:

```json
{ "namespace": "elementor/v1" }
```

If you do not know the namespace, call `wp-rest/discover` with no arguments to
list all registered namespaces first.

### 2. Inspect

Pick the most likely route and call `wp-rest/inspect` to read its argument
definitions and permission requirements:

```json
{ "route": "/elementor/v1/reset-api-data" }
```

The response shows accepted methods, required parameters, and a permission
summary. Confirm you have the required capability before proceeding.

### 3. Execute

Dispatch the request with the correct method and params:

```json
{
  "method": "POST",
  "route": "/elementor/v1/reset-api-data",
  "params": { "type": "css" }
}
```

Check `response.status`. A `2xx` status means success. Non-`2xx` statuses
contain a `data.message` field with the error reason.

**Concrete example — Clear Elementor CSS cache:**

```
discover { namespace: "elementor/v1" }
  → finds /elementor/v1/reset-api-data [POST]
inspect { route: "/elementor/v1/reset-api-data" }
  → args: { type: string, required: true }, permission: manage_options
execute { method: "POST", route: "/elementor/v1/reset-api-data", params: { type: "css" } }
  → { status: 200, data: { success: true } }
```

## Failure modes

| Error | Meaning | What to try next |
| --- | --- | --- |
| `wp_rest_namespace_blocked` | The namespace (`sd-ai-agent/v1` or a site-configured addition) is permanently blocked. | Use a different ability or ask the maintainer to perform the action. |
| `wp_rest_route_blocked` | The specific `METHOD /route` pattern is on the route blocklist. | Check whether a dedicated ability exists, or ask the site administrator. |
| `wp_rest_forbidden` (403) | The current user lacks the required capability (`manage_options` or `manage_network`). | Confirm you are acting as a user with the required role. This ability cannot elevate permissions. |
| `wp_rest_loop_blocked` | `AgentController` was detected on the call stack — recursive REST call prevented. | Restructure the workflow to avoid calling `wp-rest/execute` from within the agent's own REST handler. |
| `wp_rest_route_not_found` (404) | The route is not registered. | Re-run `wp-rest/discover` — the plugin may not be active, or the route path may differ from what was expected. |
| Response `truncated: true` | The JSON response exceeded 64 KB. | Narrow the request: add `_fields=` to return only needed fields, and `per_page=` to reduce item count. |

## See also

- `wp-rest-api` — the development skill for building or debugging REST endpoints; use this when the task involves creating or modifying a route rather than calling one.
- `wp-wpcli-and-ops` — the WP-CLI skill; use when a CLI command covers the same task, or when the output format (CSV, table) is preferable to JSON.
- `docs/wp-rest-ability.md` — full human-readable reference: security model, all filter signatures, audit details, and differences from `wp-cli/execute`.
