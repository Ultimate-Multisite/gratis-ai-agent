# t170 — Block theme generation in onboarding (inspired by Automattic wp-site-creator)

## Pre-flight

- [x] Memory recall: `wp-site-creator wordpress agent skills` → 0 hits — no prior lessons; new territory in this repo, recall confirmed fresh
- [x] Discovery pass: 0 open PRs and 0 in-flight commits touch `OnboardingManager.php`, `BootstrapPrompt.php`, `onboarding-wizard.js`, `onboarding-bootstrap.js`, or `block-themes.md` in last 48h (verified on branch `fix/orphan-tool-result-trim`)
- [x] File refs verified: 8 refs checked, all present at HEAD (`OnboardingManager.php:341-456`, `BootstrapPrompt.php`, `onboarding-bootstrap.js`, `onboarding-wizard.js:326-463`, `GeneratePluginAbility.php`, `BlockAbilities.php:217-246`, `GlobalStylesAbilities.php`, `block-themes.md`, `kadence-theme.md`)
- [x] Tier: `tier:thinking` — parent task with UX + system-prompt design decisions; phase children re-evaluate their own tier when filed
- [x] Seeded draft PR decision recorded: skipped — parent task, no implementation. Phase 1 child should be the first PR.

## Session origin

Filed 2026-05-13 after studying [Automattic/wordpress-agent-skills/claude-code/wp-site-creator](https://github.com/Automattic/wordpress-agent-skills/tree/trunk/claude-code/wp-site-creator)
and comparing it with our existing onboarding flow (`OnboardingManager`,
`BootstrapPrompt`, `OnboardingWizard`, `OnboardingBootstrap`). Their plugin
generates a complete WordPress block theme from a one-line description via a
4-phase agent flow. Our onboarding is purely discovery — we explore an
existing site and present starter prompts, but we never *create* a theme.
For a fresh-install user, having the agent design and activate a custom
block theme during onboarding would be a real differentiator (currently
only 10Web/ZipWP do this, and neither runs as a plugin you own).

## What

Add a "Design a custom theme" branch to onboarding that, after the user has
a working AI provider, can interview the user, present 3 distinct visual
design directions, and on selection generate a complete activated block
theme — all inside the existing chat UI, without leaving WordPress.

The deliverable is a working onboarding branch where:

1. After provider setup, the user is offered three onboarding modes:
   - **Explore my existing site** (current behaviour, default for non-empty installs)
   - **Design a custom theme** (new, default for fresh installs)
   - **Skip — just chat** (current behaviour)
2. The theme-builder branch runs a 4-phase conversation:
   - **Phase 1** Interview: extract a site spec (name, type, audience, tone, brand keywords, key sections, typography). Uses the new `site-specification` skill.
   - **Phase 2** Present 3 topic-grounded design directions as HTML previews stored in `wp-content/uploads/sd-ai-agent/design-previews/<session>/design-{1,2,3}.html` and shown inline in chat.
   - **Phase 3** User picks a direction (1, 2, 3, or "2 but darker"). Modifications fold back into the spec.
   - **Phase 4** Generate the full block theme into `wp-content/themes/<slug>/` (theme.json, style.css, functions.php, parts/header.html, parts/footer.html, templates/index.html, templates/page.html) and activate it.
3. All theme files are validated via existing `sd-ai-agent/validate-block-content` before write.

We do **not** ship the Automattic plugin verbatim — it's a Claude Code
plugin, not a WordPress plugin, and depends on WordPress Studio CLI which
isn't relevant inside a running WP install. We adapt the *architecture*
(phases, skills, references) to our agent loop.

## Why

1. **Fresh-install conversion.** Right now a fresh-install user activates
   the plugin, sees an empty discovery summary, and gets generic starter
   prompts. They are not impressed and they bounce. A "design a theme for
   my SaaS in 5 minutes" flow is what 10Web and ZipWP use to convert.
2. **Showcases agent capability.** Generating a coherent block theme
   requires our agent to chain `create-block-content`, `validate-block-content`,
   `update-global-styles`, `list-block-patterns`, file writes, and theme
   activation — exactly the kind of multi-step tool-calling we built the
   loop for. It's a credible demo.
3. **PLANS.md gap closure.** PLANS.md identifies "AI site generation from
   prompt" as P0 (t060-t062 line) but those tasks are about generating
   *pages*, not themes. This complements them — themes are the visual
   container, pages are the content. Both are needed.
4. **Reusable skill scaffolding.** The `site-specification` skill alone
   improves every content-creation interaction (page builds, post drafts,
   product descriptions), not just onboarding.

## Tier

`tier:thinking` — parent/planning task. UX decisions, system-prompt design,
and integration sequencing are judgment work. The 4 phase children below
are individually `tier:standard` (skills + abilities + UI wiring with
clear file targets), but Phase 3 specifically may be `tier:thinking`.

## Phases

This is a parent task. Each phase is a separate PR.

- **Phase 1 — Site-specification skill** ~3h — add `includes/Models/skills/site-specification.md` mirroring Automattic's per-site-type inference tables. New memory category `site_brief` for storing extracted specs across sessions. No UI changes. [auto-fire:on-prior-merge]
- **Phase 2 — Block-themes skill expansion** ~4h — expand `includes/Models/skills/block-themes.md` with theme.json patterns, template-part composition, animation/motion classes, and editor-visibility CSS lifted from Automattic's `wordpress-block-theming.md` reference (29KB → distil to ~400 lines, keep our existing 150-line structure as the spine). [auto-fire:on-prior-merge]
- **Phase 3 — Theme-builder onboarding branch** ~12h — new `ThemeBuilderPrompt` (mirrors `BootstrapPrompt`), new REST endpoint `POST /onboarding/theme-builder-start`, choose-mode UI in `OnboardingWizard`, and two new abilities: `sd-ai-agent/scaffold-block-theme` (creates the directory + theme.json + style.css + functions.php) and `sd-ai-agent/activate-theme` (wraps `switch_theme()`). Existing `FileAbilities` handles HTML file writes. [auto-fire:on-prior-merge]
- **Phase 4 — Design-system reference skills** ~5h — add `includes/Models/skills/design-system-aesthetics.md` covering the "topic-grounded visual worlds" framework (brewery → taproom vs label-art vs industrial), referenced by Phase 3's system prompt during the design-direction step.

Each phase PR uses `For #NNN` referencing the parent issue until Phase 4
ships, which uses `Closes #NNN`.

## How (Approach)

### Progressive Context Plan

- **Read first:** `includes/Core/BootstrapPrompt.php` and `includes/Core/OnboardingManager.php` — the discovery-bootstrap flow this work mirrors and extends.
- **Read first:** `src/components/onboarding-bootstrap.js` and `src/components/onboarding-wizard.js` — the React entry points the new branch plugs into.
- **Load only if:** [Automattic wp-site-creator commands/quick-build.md](https://raw.githubusercontent.com/Automattic/wordpress-agent-skills/trunk/claude-code/wp-site-creator/commands/quick-build.md) — only when designing Phase 3's system prompt; we don't copy it, we use it as a reference for phase ordering.
- **Load only if:** [Automattic site-specification SKILL.md](https://raw.githubusercontent.com/Automattic/wordpress-agent-skills/trunk/claude-code/wp-site-creator/skills/site-specification/SKILL.md) — direct source for Phase 1 inference tables.
- **Stop when:** the parent issue body lists 4 phase children with clear file targets and acceptance criteria each.

### Files to Modify (across all phases)

- `NEW: includes/Models/skills/site-specification.md` — Phase 1
- `EDIT: includes/Models/skills/block-themes.md` — Phase 2 (expand from 150 → ~400 lines)
- `NEW: includes/Core/ThemeBuilderPrompt.php` — Phase 3 (model on `BootstrapPrompt.php`)
- `EDIT: includes/Core/OnboardingManager.php` — Phase 3 (add `rest_theme_builder_start` REST callback paralleling `rest_bootstrap_start`)
- `NEW: includes/Abilities/ScaffoldBlockThemeAbility.php` — Phase 3 (model on `GeneratePluginAbility.php`)
- `NEW: includes/Abilities/ActivateThemeAbility.php` — Phase 3 (wraps `switch_theme()` with cap check `switch_themes`)
- `EDIT: src/components/onboarding-wizard.js` — Phase 3 (add mode-picker step between provider setup and finish)
- `NEW: src/components/onboarding-theme-builder.js` — Phase 3 (new entry component, parallel to `onboarding-bootstrap.js`)
- `NEW: includes/Models/skills/design-system-aesthetics.md` — Phase 4

### Implementation Steps (high-level — each phase brief will detail its own)

1. **Phase 1:** Read Automattic's `site-specification/SKILL.md` (already in this session's context), adapt to our skill markdown format (matches `block-themes.md` and `kadence-theme.md` style), wire the new memory category `site_brief` via `Memory::create()`. Test: agent extracts spec from "build me a theme for my coffee shop Bean & Brew" and stores it in memory.

2. **Phase 2:** Diff Automattic's `wordpress-block-theming.md` (29KB) against our `block-themes.md` (150 lines). Lift the theme.json color/typography presets, animation classes, prefers-reduced-motion patterns, and editor-styles-wrapper CSS guidance. Keep our existing tool references intact. Test: word count and section ratchet, plus a manual read-pass for accuracy.

3. **Phase 3:** Largest phase. Subphases:
   - **3a** New abilities: `ScaffoldBlockThemeAbility` + `ActivateThemeAbility`. Cap gates: `install_themes` and `switch_themes` respectively.
   - **3b** New `ThemeBuilderPrompt` class generating a 4-phase system instruction (interview → designs → choose → build). Use `sd-ai-agent/memory-save` after each phase to checkpoint state.
   - **3c** REST endpoint `POST /onboarding/theme-builder-start` paralleling `rest_bootstrap_start`. Returns `{ session_id, kickoff_message, theme_builder_system_prompt }`.
   - **3d** UI: add a "Choose your start" step to `OnboardingWizard` with 3 buttons. New `OnboardingThemeBuilder` React component parallel to `OnboardingBootstrap`. Wire selection through the existing `bootstrap_system_prompt` channel.
   - **3e** E2E test: from a fresh install, complete the flow end-to-end, verify a theme exists at `wp-content/themes/<slug>/` and is the active stylesheet.

4. **Phase 4:** Topic-grounded aesthetics skill. Document the "explore visual worlds" thinking (brewery → 3 distinct authentic worlds, not generic style swaps). Reference from Phase 3's system prompt when generating design directions.

### Verification (parent task — phase children specify their own)

```bash
# Phase 1 lands
test -f includes/Models/skills/site-specification.md

# Phase 2 lands (skill expanded)
wc -l includes/Models/skills/block-themes.md  # should be 350-450 lines

# Phase 3 lands (E2E)
npm run test:e2e:playwright -- tests/e2e/onboarding-theme-builder.spec.js

# Phase 4 lands
test -f includes/Models/skills/design-system-aesthetics.md
```

### Files Scope

Parent task — phase children declare their own scopes. As an aggregate
guard, the following globs cover all phases:

- `includes/Models/skills/site-specification.md`
- `includes/Models/skills/block-themes.md`
- `includes/Models/skills/design-system-aesthetics.md`
- `includes/Core/ThemeBuilderPrompt.php`
- `includes/Core/OnboardingManager.php`
- `includes/Abilities/ScaffoldBlockThemeAbility.php`
- `includes/Abilities/ActivateThemeAbility.php`
- `src/components/onboarding-wizard.js`
- `src/components/onboarding-theme-builder.js`
- `src/components/__tests__/OnboardingThemeBuilder.test.js`
- `tests/e2e/onboarding-theme-builder.spec.js`
- `todo/PLANS.md`
- `todo/tasks/t170-brief.md`
- `todo/tasks/t170[a-z]-*.md`
- `TODO.md`

## Acceptance Criteria

- [ ] Phase 1 PR merged: `site-specification` skill markdown exists with all 7 site-type inference tables (SaaS, e-commerce, professional-services, restaurant, portfolio, blog, non-profit) and at least 3 worked examples (coffee shop, law firm, esports team).

  ```yaml
  verify:
    method: bash
    run: "test -f includes/Models/skills/site-specification.md && grep -c '^### ' includes/Models/skills/site-specification.md | xargs test 7 -le"
  ```

- [ ] Phase 2 PR merged: `block-themes.md` expanded with theme.json presets, animation classes, and editor-visibility CSS sections.

  ```yaml
  verify:
    method: codebase
    pattern: "Editor Visibility|editor-styles-wrapper|prefers-reduced-motion"
    path: "includes/Models/skills/block-themes.md"
  ```

- [ ] Phase 3 PR merged: theme-builder branch reachable from onboarding; activates a generated block theme on a fresh install.

  ```yaml
  verify:
    method: bash
    run: "test -f includes/Core/ThemeBuilderPrompt.php && test -f includes/Abilities/ScaffoldBlockThemeAbility.php && test -f includes/Abilities/ActivateThemeAbility.php"
  ```

- [ ] Phase 4 PR merged: `design-system-aesthetics.md` skill exists and is referenced from `ThemeBuilderPrompt`.

  ```yaml
  verify:
    method: codebase
    pattern: "design-system-aesthetics"
    path: "includes/Core/ThemeBuilderPrompt.php"
  ```

- [ ] No regression to existing discovery onboarding: the "Explore my existing site" branch continues to call `/onboarding/bootstrap-start` and behaves identically to today.

  ```yaml
  verify:
    method: codebase
    pattern: "bootstrap-start"
    path: "src/components/onboarding-bootstrap.js"
  ```

- [ ] Lint + tests clean on each phase PR: `npm run lint:js && npm run lint:php && npm run test:php`.

- [ ] No raw `wp-content/themes/` writes happen without `current_user_can('install_themes')` gating in the new abilities.

  ```yaml
  verify:
    method: codebase
    pattern: "current_user_can.*install_themes"
    path: "includes/Abilities/ScaffoldBlockThemeAbility.php"
  ```

## Context & Decisions

- **Adopt the architecture, not the code.** Automattic's plugin is a Claude Code plugin coupled to WordPress Studio CLI. We're a WP plugin running inside the install. We take the 4-phase flow, the skill structure, and the topic-grounded design thinking — we do *not* take the `.claude-plugin/` packaging, the `studio` CLI calls, the `block-fixer/cli.js` Node script (we have `validate-block-content`), or the parallel `Task()` subagent pattern (we run one model per `AgentLoop` iteration).
- **No stock image URLs ever.** Automattic's prompts repeat this rule; we should too. Generated themes use CSS gradients, color blocks, and typography for visual richness when the user hasn't provided their own images.
- **Phase boundary discipline.** Phases are independently shippable. If we ship only Phases 1–2 and stop, the site-specification skill still improves every other interaction. Phases 3–4 are where the theme-builder onboarding lights up.
- **Why not a full PRD?** The deliverable is well-scoped and adapts a known reference architecture. Phase briefs carry the detail; a separate PRD would duplicate this brief.
- **Non-goals:**
  - Generating Gutenberg patterns library (covered separately by t060-t062 page-generation work).
  - WooCommerce-aware theme variants (Phase 3 generates a generic theme; WooCommerce-specific templates can be a follow-up).
  - Multi-language theme generation.
  - Child-theme generation.
  - Importing existing themes and "AI-redesigning" them.

## Relevant Files

- `includes/Core/BootstrapPrompt.php` — model for `ThemeBuilderPrompt`.
- `includes/Core/OnboardingManager.php:341-456` — `rest_bootstrap_start` is the pattern for the new `rest_theme_builder_start`.
- `src/components/onboarding-bootstrap.js` — parallel structure for `onboarding-theme-builder.js`.
- `src/components/onboarding-wizard.js:326-463` — the step array gets a new mode-picker step inserted.
- `includes/Abilities/GeneratePluginAbility.php` — closest existing scaffolding ability to model `ScaffoldBlockThemeAbility` on.
- `includes/Abilities/BlockAbilities.php:217-246` — existing `list-block-templates` and validate/create patterns the new theme-builder leverages.
- `includes/Abilities/GlobalStylesAbilities.php` — theme.json update patterns we reuse.
- `includes/Models/skills/block-themes.md` — Phase 2 target.
- `includes/Models/skills/kadence-theme.md` — alternate skill style reference.
- [Automattic site-specification](https://raw.githubusercontent.com/Automattic/wordpress-agent-skills/trunk/claude-code/wp-site-creator/skills/site-specification/SKILL.md) — Phase 1 source.
- [Automattic quick-build](https://raw.githubusercontent.com/Automattic/wordpress-agent-skills/trunk/claude-code/wp-site-creator/commands/quick-build.md) — Phase 3 architectural reference.
- [Automattic preview-designs](https://raw.githubusercontent.com/Automattic/wordpress-agent-skills/trunk/claude-code/wp-site-creator/commands/preview-designs.md) — Phase 3 design-direction reference.
- [Automattic wordpress-block-theming reference (29KB)](https://github.com/Automattic/wordpress-agent-skills/blob/trunk/claude-code/wp-site-creator/references/wordpress-block-theming.md) — Phase 2 source.

## Dependencies

- **Blocked by:** none (Phase 1 can start immediately).
- **Blocks:** future fresh-install onboarding metrics and resale-API "white-label theme generation" follow-ups.
- **External:** none. All work happens inside the plugin and the WP install. No new API providers, no new credentials.

## Estimate Breakdown

| Phase | Time | Notes |
|-------|------|-------|
| Phase 1 (site-spec skill) | ~3h | Markdown only + 1 memory category constant |
| Phase 2 (block-themes expansion) | ~4h | Distil 29KB reference into ~250 added lines |
| Phase 3 (theme-builder branch) | ~12h | 2 new abilities, 1 new prompt class, 1 new REST endpoint, 1 new React component, wizard wiring, E2E test |
| Phase 4 (aesthetics skill) | ~5h | Markdown + cross-reference wiring |
| **Total** | **~24h** | Spread over 3-5 calendar weeks of background work |
