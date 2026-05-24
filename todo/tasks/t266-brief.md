# t266 — Wave 3.2: Block enrichers framework + `core/image` enricher

## Pre-flight

- [x] Memory recall: `block enricher transform read output augment` → 0 hits.
- [x] Discovery pass: 0 open PRs touch read-side block transforms. `get-page-blocks` returns the raw parsed block; agents currently make a second round-trip (`get-post` → attachment metadata) to resolve srcset/sizes/aspect ratios for image blocks.
- [x] File refs verified — block-mcp source: `~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/block-enrichers/class-core-image-enricher.php` (~140 LOC), `class-core-block-enricher.php` (orchestrator). Superdav existing: `includes/Abilities/BlockAbilities.php` (read serialiser).
- [x] Tier: `tier:thinking` — introduces a new extension point (filter + registry) that other plugins may rely on; the contract needs careful naming and stability.
- [x] Seeded draft PR decision: skipped.

## Origin

- **Created:** 2026-05-24
- **Session:** opencode interactive (block-mcp wave-3 adoption)
- **Parent task:** TBD
- **Conversation context:** Wave 3 child 2/11. block-mcp's read pipeline runs every block through a chain of "enrichers" before serialisation. Each enricher inspects the block name and optionally adds derived fields. Result: agents get richer, immediately-useful read output without extra round trips. For images: srcset, sizes, focal point, alt text from the attachment, intrinsic dimensions. This is a genuine differentiator and aligns with WordPress's filter-chain idiom.

## What

### Framework

Add `includes/Core/BlockEnricherRegistry.php`:

```php
final class BlockEnricherRegistry {
    public function register( string $block_name, BlockEnricherInterface $enricher ): void;
    public function enrich( array $block, array $context ): array;   // walks registered enrichers for $block['blockName']
}
```

Define `BlockEnricherInterface`:

```php
interface BlockEnricherInterface {
    public function supports( string $block_name ): bool;
    public function enrich( array $block, array $context ): array;   // returns mutated block array
}
```

Public filter for third-party extension:

```php
do_action( 'sd_ai_agent_register_block_enrichers', $registry );
```

Wire the registry into the `get-page-blocks` serialiser. Each enriched block dict gets an additional top-level key `enriched: { <enricher_id>: { ... } }` so enricher output never collides with `attributes` or `innerHTML`.

### Concrete enricher (`core/image`)

`includes/Enrichers/CoreImageEnricher.php`:

For every `core/image` block, populate `block.enriched.core_image`:

```json
{
  "attachment_id":   42,
  "url":             "…/foo.jpg",
  "alt":             "Latte art",
  "width":           1600,
  "height":          1067,
  "aspect_ratio":    "3:2",
  "mime_type":       "image/jpeg",
  "srcset":          "…/foo-300x200.jpg 300w, …/foo-768x512.jpg 768w, …",
  "sizes":           "(max-width: 1600px) 100vw, 1600px",
  "filesize_bytes":  238412,
  "missing":         false
}
```

If `attrs.id` is absent or the attachment was deleted, set `missing: true` and omit URL fields — never throw.

## Why

Two-fold value:

1. **Agent UX**: a `get-page-blocks { render: false }` call today returns `{ blockName: "core/image", attrs: { id: 42, sizeSlug: "large" }, innerHTML: "..." }`. To compose a responsive layout the agent has to fetch the attachment, parse `wp_get_attachment_metadata`, derive the URL. The enricher does it once, server-side, with WP's existing helpers.
2. **Extension point**: third-party plugins can ship enrichers for their own blocks (Yoast FAQ → FAQ Schema; WooCommerce Product → price/stock; etc.) without forking Superdav. The framework + one concrete enricher proves the pattern.

## Source pattern

`~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/block-enrichers/class-core-image-enricher.php` (the concrete enricher) and `class-core-block-enricher.php` (registry/dispatcher). GPL-2.0-or-later.

Block-mcp's enricher emits each property at top level; we should namespace under `enriched.<id>` to avoid colliding with `attributes` or any future read fields.

## Files to modify / create

- **New:** `includes/Core/BlockEnricherRegistry.php` — the registry + dispatch helper.
- **New:** `includes/Core/BlockEnricherInterface.php` — the contract.
- **New:** `includes/Enrichers/CoreImageEnricher.php` — first concrete enricher.
- **Modify:** `includes/Abilities/BlockAbilities.php` — instantiate the registry, fire the `sd_ai_agent_register_block_enrichers` action, register the core/image enricher by default, run every parsed block through `$registry->enrich(...)` before returning.
- **Modify:** `includes/Bootstrap/AbilitiesHandler.php` — bind the registry in DI (singleton).
- **Modify:** `docs/x-wp-di.md` — document the new singleton.
- **New:** `tests/SdAiAgent/Core/BlockEnricherRegistryTest.php` — register/dispatch order, multiple enrichers per block name, third-party action hook.
- **New:** `tests/SdAiAgent/Enrichers/CoreImageEnricherTest.php` — populated metadata, missing attachment, missing id attr, non-image attachments (returns un-enriched).

## Acceptance criteria

1. `get-page-blocks` on a post containing a `core/image` block (with a valid attachment) returns `blocks[i].enriched.core_image` populated with `url`, `width`, `height`, `srcset`, `sizes`, `alt`.
2. Same block with a deleted attachment → `enriched.core_image.missing = true` and no URL fields.
3. Same block with no `attrs.id` → `enriched.core_image.missing = true`.
4. Blocks of other types do not get `enriched.core_image` (key absent).
5. The action `do_action( 'sd_ai_agent_register_block_enrichers', $registry )` fires once per request before serialisation; a unit test that registers a stub enricher in the action sees its `enrich` method called.
6. `render: true` mode (from #1762/t260) and the enrichers compose: rendered HTML AND `enriched.*` both present.
7. Performance: a single `get-page-blocks` call on a post with 20 `core/image` blocks issues at most 20 `get_post(...)` calls (no N+1 multiplier inside the enricher).
8. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
wp eval --user=admin '
  $r = wp_get_ability("sd-ai-agent/get-page-blocks")->execute(["post_id" => /* a post with images */]);
  foreach ($r["blocks"] as $b) {
    if ($b["name"] === "core/image") {
      print_r($b["enriched"]["core_image"] ?? "(no enrichment)");
    }
  }
'
```

## Tier rationale

`tier:thinking` — introduces a public extension point. Action name, interface signature, and `enriched.<id>` shape are part of the long-term contract. The registry has to behave well when third parties register conflicting handlers (later wins; document this).

## Dependencies

- **Blocked by:** none.
- **Soft dependency:** future Yoast FAQ enricher (separate task) and WooCommerce Product enricher (separate task) will register against the same action.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #<parent>`.
