# t272 — Wave 3.8: Strip `Undefined array key "type"` warnings from ability schemas

## Pre-flight

- [x] Memory recall: `Undefined array key type rest-api.php schema validation` → noted during wave-2 verification on every `insert-pattern` call.
- [x] Discovery pass: 0 PRs touch schema cleanup. Warning surfaces at `wp-includes/rest-api.php:2207, 2222, 2231` — core's `rest_validate_value_from_schema` recursing into a nested property whose definition is missing `type`.
- [x] File refs verified — Superdav existing: every `*_schema` array in `includes/Abilities/*.php` (30+ files).
- [x] Tier: `tier:thinking` — fix is mechanical, but the audit needs judgment about which property defaults to `string` vs `object` vs `array`.
- [x] Seeded draft PR decision: skipped.

## Origin

- **Created:** 2026-05-24
- **Session:** opencode interactive (block-mcp wave-3 adoption)
- **Parent task:** TBD
- **Conversation context:** Wave 3 child 8/11. During wave-2 verification (insert-pattern matrix), every call emitted three `Notice: Undefined array key "type" in wp-includes/rest-api.php on line 2207/2222/2231`. The warnings are cosmetic (functionality works), but they pollute logs and fail strict deprecation gates. Root cause: one or more JSON Schema properties in `BlockAbilities.php` (and likely other ability files) omit the `type` field, which WP's `rest_validate_value_from_schema` requires.

## What

1. **Audit pass** — grep every `'properties' => [` in `includes/Abilities/` and `includes/REST/` and assert each child property has a `'type' =>` entry. Use a one-off script:

   ```bash
   php -r '
     $files = glob("includes/Abilities/*.php"); // and includes/REST/
     // tokenise, walk arrays, report any "properties" subarray entries lacking "type"
   '
   ```

   Capture the report as a checklist.

2. **Fix pass** — for each finding, add the correct `'type' => '...'`. Guidance:
   - If the property is described as "object" / "map" → `'type' => 'object'`.
   - If it's an array (e.g. block tree) → `'type' => 'array'` plus `'items' => [ 'type' => 'object' ]` when items are objects.
   - If it's `description`-only with no obvious type → audit the handler; pick the type the handler actually consumes.

3. **Regression guard** — add a unit test that walks every registered `sd-ai-agent/*` ability's `input_schema` and `output_schema` and asserts every nested property has a `type`. If a future PR forgets one, CI fails. Example:

   ```php
   public function test_every_ability_schema_property_has_type(): void {
     foreach ( wp_get_abilities() as $ability ) {
       if ( ! str_starts_with( $ability->name, 'sd-ai-agent/' ) ) continue;
       foreach ( [ 'input_schema', 'output_schema' ] as $kind ) {
         $schema = $ability->$kind ?? null;
         if ( $schema ) $this->assert_every_property_has_type( $schema, "{$ability->name}.{$kind}" );
       }
     }
   }
   ```

4. **Verify clean** — run the wave-2 `insert-pattern` repro and confirm no `Undefined array key "type"` notices fire.

## Why

Three small wins:
- Clean test/log output (the warnings have been a red herring during debugging more than once).
- Stricter schema = better client codegen (clients that generate types from `output_schema` currently see `unknown` for these fields).
- Future-proofs against WP core hardening: WP has historically promoted "undefined index" notices to fatal errors over releases.

## Source pattern

Not a port — internal cleanup. JSON Schema reference: `https://json-schema.org/draft/2020-12/json-schema-core.html#name-type`.

## Files to modify / create

- **Modify:** ability files with missing `type` entries — exact list to be produced by the audit pass above. Expect 5–15 hits across `includes/Abilities/` and possibly `includes/REST/`.
- **New:** `tests/SdAiAgent/Bootstrap/AbilitySchemaIntegrityTest.php` — the regression guard.
- **No changes** to ability behaviour or handler code expected.

## Acceptance criteria

1. The audit script (kept in the PR commit history as `tools/audit/schema-type-audit.php`) reports zero findings.
2. The regression test passes against every registered `sd-ai-agent/*` ability.
3. `wp eval --user=admin '$r = wp_get_ability("sd-ai-agent/insert-pattern")->execute([...]); ...'` against a fresh install emits zero `Undefined array key "type"` notices.
4. No ability `input_schema` or `output_schema` changes behaviour — only adds `type` where missing.
5. Full PHPUnit + phpstan + lint clean.

## Verification

```bash
wp eval --user=admin '
  $r = wp_get_ability("sd-ai-agent/insert-pattern")->execute([
    "post_id" => 156, "pattern" => "core/navigation-overlay",
  ]);
  echo is_wp_error($r) ? $r->get_error_code() : "ok" . PHP_EOL;
' 2>&1 | grep "Undefined array key" || echo "CLEAN — no warnings"
```

## Tier rationale

`tier:thinking` — the fix is one-line per finding, but the type-choice judgment requires reading each handler to confirm what shape the field actually carries. A standard-tier worker would risk picking `'type' => 'string'` for an array field, breaking real clients.

## Dependencies

- **Blocked by:** none.

## PR conventions

Leaf — `Resolves #<this-issue>`. `For #<parent>`.
