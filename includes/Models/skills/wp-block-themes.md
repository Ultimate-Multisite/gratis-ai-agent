<!-- Adapted from github.com/WordPress/agent-skills (GPL-2.0-or-later) and extended with Superdav AI Agent theme-generation guardrails. -->
---
name: wp-block-themes
description: "Use when developing or generating WordPress block themes: theme.json, templates, template parts, patterns, style variations, Site Editor troubleshooting, and agent-safe block-theme composition."
compatibility: "Targets WordPress 7.0+ and this plugin's sd-ai-agent block-theme abilities."
---

# WP Block Themes

## When to Use

Use this skill for block theme work such as:

- creating a new block theme from a user/site specification
- editing `theme.json` presets, settings, styles, typography, spacing, or per-block styles
- adding or changing templates (`templates/*.html`) and template parts (`parts/*.html`)
- adding patterns (`patterns/*.php`) and controlling what appears in the inserter
- adding style variations (`styles/*.json`)
- debugging "styles not applying" or "editor doesn't reflect theme.json"

If the active theme is classic and the task is to modify that active theme, use `classic-themes` instead. If the task is to generate, convert to, or scaffold a block theme, use this skill even when the currently active theme is classic.

When a contributor-insight issue contains a pasted local-path excerpt headed `Block Themes`, `Full Site Editing`, `theme.json`, templates, parts, patterns, or style variations, treat it only as a source-mapping clue.
Update this committed skill (and root `AGENTS.md` when guidance is repo-wide); never copy private paths or duplicate the full excerpt in issues, PRs, templates, or generated theme files.
In mixed reports #2034, #2050, #2060, #2066, #2074, and #2081, this is only the block-theme target; map REST, Google Search Console, and WordPress.org review-response notes to their own committed sources.

## Inputs required

- Target theme directory or the slug/name for the new theme being generated.
- Target WordPress version range. This plugin targets WordPress 7.0+, so generated `theme.json` must use schema version 3.
- Where the issue manifests: Site Editor, post editor, frontend, or all.
- Whether user customizations in the Site Editor may override file-based `theme.json` values.

## Procedure

1. Triage the theme root: confirm `theme.json`, `templates/`, and `parts/` for existing block themes, or scaffold them before continuing for new themes.
2. Confirm override expectations: WordPress style resolution is core defaults → theme.json → child theme → user customizations. User customizations can make file edits look ignored.
3. Decide whether a `theme.json` change belongs under `settings` (what the editor allows) or `styles` (default visual output).
4. Put templates in `templates/*.html`; put template parts directly in `parts/*.html` (not nested subdirectories).
5. Prefer filesystem-owned patterns under `patterns/*.php` when the theme should ship reusable layouts.
6. Put style variations in `styles/*.json`; use the explicit `sd-ai-agent/*-style-variation` lifecycle rather than generic file mutation. Once a user selects a style variation, its selection is stored in the database.
7. Validate generated block markup before writing templates, parts, or patterns; before activation, run `sd-ai-agent/validate-block-theme-project` for its stylesheet and repair every error until `valid: true`. This project-wide, read-only gate checks declarations, references, patterns, variations, local assets, placeholders, and block markup without executing pattern PHP or fetching URLs.
8. Perform an explicit final quality review before declaring a generated theme complete. Prefer a browser, screenshot, or front-end render check when available; when visual QA is unavailable, perform the structural review in the verification checklist and report that limitation.
9. For contributor-insight or maintenance changes to this skill, verify future workers load the guidance with `rg -n "wp-block-themes|Full Site Editing|theme.json|validate-block-content" AGENTS.md includes/Models/skills/wp-block-themes.md`.

## Absolute Rules

These rules are hard constraints when generating or modifying block markup. They are surfaced at the top because the most common defects in agent-generated themes come from violating them.

- **No HTML blocks.** Never use `<!-- wp:html -->` (the `core/html` block). HTML blocks are opaque blobs in the block editor — users cannot select, style, or rearrange individual elements inside them. Every piece of content must use a proper core block (`wp:group`, `wp:heading`, `wp:paragraph`, `wp:columns`, etc.). If you find yourself reaching for `wp:html`, stop and decompose the content into core blocks with `className` attributes and CSS instead.
- **No decorative HTML comments.** Never insert non-block HTML comments like `<!-- Hero Section -->` or `<!-- Features -->` in templates, template parts, or patterns. The only HTML comments allowed are WordPress block delimiters (`<!-- wp:block-name -->` / `<!-- /wp:block-name -->`).
- **No stock image URLs.** Only use image URLs the user has explicitly provided. Stock-image URLs (Unsplash, Pexels, etc.) often fail to load in the block editor and break the design. When no user imagery is available, create visual richness through CSS gradients, color blocks, typography, and decorative pseudo-elements (see "Creating Visual Richness Without Images" below).
- **Validate before write.** Run `sd-ai-agent/validate-block-content` on every template, template part, and pattern body before saving. The validator catches the markup mistakes that produce silent "this block contains unexpected or invalid content" errors in the editor.

## How to confirm the active theme is a block theme

Use `sd-ai-agent/site-info` (preferred) or wp-cli:

```bash
wp option get template       # Active theme directory
wp option get stylesheet     # Active child theme directory (or same as template)
```

Programmatic check inside PHP: `wp_is_block_theme()`. A block theme has:
- `templates/` directory containing `.html` files
- `parts/` directory for header/footer/sidebar parts
- `theme.json` at the theme root

## Key Concepts

### Block Themes vs Classic Themes

| Aspect | Block theme | Classic theme |
|---|---|---|
| Templates | `.html` files in `templates/` | `.php` files in theme root |
| Template parts | `.html` files in `parts/` | `header.php`, `footer.php`, `sidebar.php` |
| Configuration | `theme.json` | `functions.php` + `add_theme_support()` |
| Header/footer editing | Site Editor | Customizer / template files |
| Global styles UI | Yes (Site Editor → Styles) | Customizer (limited) |

### Template Hierarchy

Block themes follow the standard WordPress template hierarchy, but with HTML files:

- `templates/index.html` — Default template (always required)
- `templates/single.html` — Single post
- `templates/page.html` — Single page
- `templates/archive.html` — Archive pages
- `templates/category.html`, `templates/tag.html`, `templates/author.html` — Taxonomy archives
- `templates/search.html` — Search results
- `templates/404.html` — Not found
- `templates/front-page.html` — Static front page
- `templates/home.html` — Posts page

### Template Parts

Reusable sections in `parts/`:

- `parts/header.html` — Site header
- `parts/footer.html` — Site footer
- `parts/sidebar.html` — Sidebar (if used)
- `parts/comments.html` — Comments area

Template parts are referenced inside templates via `wp:template-part`:

```html
<!-- wp:template-part {"slug":"header","tagName":"header"} /-->
```

### Template Part Composition

Generate template parts as reusable, editor-friendly block assemblies. A part should provide the structural frame only; page-specific hero, content, and CTA sections belong in templates or patterns.

**Header requirements:**

- Use a constrained or wide Group wrapper with site-appropriate padding from the spacing scale.
- Compose a flex Row or Group containing `wp:site-title` (usually level `0`, so it renders as non-heading text) and `wp:navigation`.
- Add a `className` for design-specific styling (`site-header`, `sticky-header`, `transparent-header`) rather than inline HTML.
- Keep menus editable through the Navigation block; do not hard-code link lists in HTML blocks.

**Footer requirements:**

- Use a Group wrapper whose background complements the header or final CTA band.
- Include editor-editable core blocks: site title, paragraph copy, navigation, social links, or columns as needed for the site type.
- Reset the default top margin with `.wp-site-blocks > footer { margin-block-start: 0; }` in `style.css` so the footer sits flush after full-bleed sections.

**Page-title part:**

For non-landing templates, create a reusable page-title part or pattern that uses the dynamic `wp:post-title` block. Matching its spacing, typography, and background treatment to the homepage hero makes generated pages feel cohesive instead of bolted on.

```html
<!-- wp:group {"className":"page-title-band","align":"full","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull page-title-band">
  <!-- wp:post-title {"level":1,"textAlign":"center"} /-->
</div>
<!-- /wp:group -->
```

## Available Tools

- `sd-ai-agent/list-block-templates` — List all templates with slugs and descriptions
- `sd-ai-agent/list-block-patterns` — Browse patterns for page creation and templates
- `sd-ai-agent/parse-block-content` — Inspect template structure
- `sd-ai-agent/create-block-content` / `sd-ai-agent/validate-block-content` — Build/check block markup before saving
- `sd-ai-agent/update-global-styles` — Apply the selected design direction with a non-empty theme.json `styles` partial. Never call it with `styles: []`, `settings: []`, `{}`, or unchanged empty arguments; include concrete colors, typography, spacing, or element styles.

### Safe existing-template edits

When modifying an existing template such as `front-page`, preserve every block that
the user did not ask to change:

1. Inspect the current template first. Use `sd-ai-agent/list-block-templates` to
   find the template, then fetch its current post/template content before writing.
2. If a REST template response includes a `wp_id`, use `sd-ai-agent/get-page-blocks`
   on that post ID and address the hero/section by `ref`, `path`, or `flat_index`.
3. Use `sd-ai-agent/update-blocks` or `sd-ai-agent/edit-block-tree` for the target
   hero background/image attributes only. Do not rebuild or replace the full
   template unless the user explicitly requested a complete redesign.
4. Validate the full resulting block markup with `sd-ai-agent/validate-block-content`
   before saving. If validation fails, repair the proposed change and retry; do
   not save a partial template body.
5. Do not POST partial `content` payloads to `/wp/v2/templates/...` through
   `wp-rest/execute`. That endpoint replaces the template body and can delete
   sibling sections when the payload only contains the edited hero block.

## theme.json Overview

`theme.json` controls global styles AND editor settings. Two top-level keys: `settings` (what users can do) and `styles` (default appearance). Always declare `$schema` and `version: 3`.

### Schema and Version

```json
{
  "$schema": "https://schemas.wp.org/trunk/theme.json",
  "version": 3
}
```

### Settings

Every palette must define the five semantic roles `foreground`, `background`, `surface`, `accent`, and `on-accent`. Use `foreground` on `background` for body text and headings, `accent` on `background` for links, and `on-accent` on `accent` for controls. `primary` and `secondary` may be added as optional aesthetic groupings, but never substitute them for a semantic role. Set `defaultPalette: false` and `defaultGradients: false` to keep the editor focused on the brand colours. Spacing: 6-step scale slugs `20`–`70` from `0.5rem` to `4rem`. Always set `appearanceTools: true` and `fluid: true` on typography.
```json
{
  "settings": {
    "appearanceTools": true,
    "color": { "palette": [
      { "slug": "foreground", "color": "#1a1a1a", "name": "Foreground" }, { "slug": "background", "color": "#ffffff", "name": "Background" },
      { "slug": "surface", "color": "#f5f5f5", "name": "Surface" }, { "slug": "accent", "color": "#b21f2d", "name": "Accent" }, { "slug": "on-accent", "color": "#ffffff", "name": "On Accent" }
    ], "defaultPalette": false, "defaultGradients": false },
    "typography": { "fluid": true, "fontFamilies": [], "fontSizes": [] },
    "spacing": { "units": ["px", "em", "rem", "%", "vw", "vh"], "spacingSizes": [ { "slug": "40", "size": "1rem", "name": "Regular" }, { "slug": "60", "size": "2.5rem", "name": "Looser" } ] },
    "layout": { "contentSize": "720px", "wideSize": "1200px" }
  }
}
```

### Styles

When calling `sd-ai-agent/update-global-styles`, pass only the inner `styles` and optional `settings` objects — not the full theme.json wrapper. The `styles` argument is required and must be non-empty.

```json
{
  "styles": {
    "color": { "background": "#ffffff", "text": "#1a1a1a" },
    "typography": { "fontFamily": "var(--wp--preset--font-family--body)", "fontSize": "1rem", "lineHeight": "1.6" },
    "elements": {
      "heading": { "typography": { "fontFamily": "var(--wp--preset--font-family--heading)", "lineHeight": "1.2" } },
      "link":    { "color": { "text": "var(--wp--preset--color--accent)" } },
      "button":  { "color": { "background": "var(--wp--preset--color--accent)", "text": "var(--wp--preset--color--on-accent)" }, "border": { "radius": "0.5rem" } }
    }
  }
}
```

### Compile a design-token contract first
Compilation is pure. Call `sd-ai-agent/compile-design-tokens` and follow its complete schema-v1 contract exactly (governance identity, bounded primitives, semantic roles, and a style-variation remap); repair any `WP_Error` and never reconstruct raw artifacts independently. Pass `theme_json` to `sd-ai-agent/scaffold-block-theme`, pass `global_styles.settings` and `global_styles.styles` only to `sd-ai-agent/update-global-styles`, validate `palette` with `sd-ai-agent/validate-palette-contrast`, and preserve the file-only `style_variation` plus `artifact_manifest` for `sd-ai-agent/apply-design-artifact-release`; the manifest must never own a second `wp_global_styles` record.

### Style-variation lifecycle
For a user-requested active-theme variation:
1. Call `sd-ai-agent/list-style-variations`; parent entries are read-only and child entries win filename collisions. Call `sd-ai-agent/validate-style-variation` and `sd-ai-agent/preview-style-variation` before create, update, or select; preview is in memory only and returns CSS plus changed paths, never a screenshot claim.
2. Create a complete `styles/{slug}.json` only through `sd-ai-agent/create-style-variation`; replace it only through `sd-ai-agent/update-style-variation` with the hash returned by list or validate.
3. Select only through `sd-ai-agent/select-style-variation` using the exact source hash. It records the original active-theme Global Styles document and always switches from that original baseline instead of accumulating changes.
4. Call `sd-ai-agent/reset-style-variation` only to undo a plugin-managed selection. It refuses to overwrite intervening Site Editor changes and restores the exact baseline rather than deleting all user Global Styles.
Use `sd-ai-agent/apply-design-artifact-release` for a governed generated artifact manifest; use this explicit lifecycle for one active-theme variation. Never use generic file write/edit or `reset-global-styles` to bypass either safety contract.
### Generated design artifact governance

Generated token sets, block patterns, and style variations are versioned releases, not ordinary edits.

1. Use one schema-v1 entry with a stable `sd-ai-agent/token_set/...`, `sd-ai-agent/pattern/...`, or `sd-ai-agent/style_variation/...` ID; numeric Semantic Versioning `version`; `maturity` (`stable`, `candidate`, `experimental`, or `deprecated`); provenance; compatibility; applicable deprecation metadata; and a canonical content hash. Do not invent parallel lifecycle fields.
2. Declare WordPress/`theme.json` ranges, required blocks/features, and theme constraints. Incompatible artifacts are rejected first; inspect the deterministic trace with `sd-ai-agent/resolve-design-artifacts`. Stable is default; candidate and experimental require explicit opt-in, deprecated is never a new default, and automatic selection never crosses a major version.
3. Use `sd-ai-agent/apply-design-artifact-release` for generated writes and `sd-ai-agent/rollback-design-artifact-release` only with an exact retained release ID. The manager stages and validates before moving the active pointer, restores complete prior state after failure, and refuses unmanaged targets.

Never use `sd-ai-agent/curated-block-patterns`, direct generic `patterns/`/`styles/` writes, or ordinary global-style editing to bypass this contract. The dedicated style-variation lifecycle above is the only exception for one validated active-theme variation. Ordinary user-created patterns and customizations remain outside the registry and must not be silently imported, rewritten, or deleted.

### Typography presets — choose distinctive fonts

Avoid generic fonts (Arial, Inter when used as a default). Pair a **distinctive display font** with a **refined body font**. A workable 6-step size scale:

```
0.875rem  /  1rem  /  1.25rem  /  1.75rem  /  2.25rem  /  clamp(2.5rem, 4vw, 3.5rem)
```

- Body line-height `1.5`–`1.65`; headings `1.1`–`1.3`. Never go below `1.0`.
- Cap display sizes around `3.5rem`. Sizes above `4rem` rarely improve design and often degrade it.
- Use `clamp()` for fluid display headings but always cap the upper bound.

### Custom Templates

Define custom page templates in theme.json so they appear in the editor's template picker:

```json
{
  "customTemplates": [
    { "name": "blank", "title": "Blank", "postTypes": [ "page" ] },
    { "name": "landing", "title": "Landing Page", "postTypes": [ "page" ] }
  ]
}
```

The corresponding `templates/blank.html` and `templates/landing.html` files must exist.

## Block Patterns and FSE

- Page-creation patterns appear in the modal when creating a new page (`blockTypes`: `core/post-content`)
- Template patterns can be inserted in the Site Editor
- Use `sd-ai-agent/list-block-patterns` to discover available patterns
- Synced patterns (formerly "reusable blocks") are stored as `wp_block` post type
- Pattern files live in `patterns/*.php` with a header docblock declaring `Title`, `Slug`, and `Categories`

```php
<?php
/**
 * Title: Hero Section
 * Slug: theme-slug/hero
 * Categories: featured
 */
?>
<!-- wp:group {"backgroundColor":"accent","textColor":"on-accent","layout":{"type":"constrained"}} -->
...
<!-- /wp:group -->
```

## Landing Page Composition

When generating a homepage, think like a landing page designer, not a template assembler. Every section is a visually distinct, full-width band that creates rhythm and visual impact as the user scrolls.

### Section Architecture

- **Section margin reset.** Add `"style":{"spacing":{"margin":{"top":"0"}}}` to every top-level Group block that wraps a landing-page section. This overrides WordPress's default top margin on direct children of `.wp-site-blocks`.
- **Full-bleed wrappers, constrained content.** Every major homepage section MUST be `"align":"full"` with `{"layout":{"type":"constrained"}}` inside. A bare `{"layout":{"type":"constrained"}}` without `align:full` renders at `contentSize` (~720–800px) and the page looks narrow and lifeless.
- **Columns alignment.** Always set `"align":"wide"` on `wp:columns` blocks (and add `alignwide` on the wrapper div) unless explicitly instructed otherwise.
- **No `<inner-blocks>`.** Output the full expanded markup inside each block.

### Visual Rhythm

Alternate visual treatments to avoid monotony:

| Technique | WordPress implementation |
|-----------|--------------------------|
| Alternating backgrounds | Alternate `backgroundColor` between `background` and `surface` (or `primary`/`secondary` for bold sections) |
| Full-bleed imagery | Cover blocks with `"align":"full"` and `overlayColor` from the brand palette |
| Edge-to-edge media-text | `wp:media-text` with `"align":"full"` for alternating image/content sides |
| Bold CTA bands | Full-width group with `accent` background and `on-accent` text, centered |
| Spacer breaks | `wp:spacer` between sections for breathing room |

If two adjacent sections share the same background and layout, the page feels monotonous — change the background, flip the image side, switch from grid to single-column, or insert a cover block break.

### Cover Block Pitfalls

- **Hero height.** Use `60vh` for hero covers as the default — `100vh` plus large padding leaves content floating in whitespace because cover blocks already vertically centre via flexbox.
- **`minHeight` accepts a number + unit only.** No `clamp()` — pass `60` + `vh`.
- **Centred badges inside covers.** Use `display: flex; justify-content: center` — `display: inline-flex` left-aligns because the cover's inner container has no `text-align: center`.

### Card layouts in rows

For equal-height, equal-width cards with optional bottom-aligned CTAs: wrap a `wp:columns` (`className: "equal-cards"`) around N columns with `verticalAlignment: "stretch"`, each containing a `wp:group` card wrapper. All column widths must be equal and sum to exactly 100% (3 cards = `33.33%`). Optionally add a buttons block with `className: "cta-bottom"` that pins to the bottom of each card.

```css
.equal-cards > .wp-block-column { display: flex; flex-direction: column; flex-grow: 0; }
.equal-cards > .wp-block-column > .wp-block-group { display: flex; flex-direction: column; flex-grow: 1; }
.equal-cards .cta-bottom { margin-top: auto; justify-content: center; }
/* Reset the site footer top margin so it sits flush against the last section. */
.wp-site-blocks > footer { margin-block-start: 0; }
```

### Creating Visual Richness Without Images

When the user has not supplied imagery, convey atmosphere through:

- **CSS gradients** — linear, radial, conic for depth and colour
- **Bold colour blocks** — surface/accent backgrounds for hierarchy
- **Typography as design** — large, distinctive headings; varied weights; creative pairings
- **CSS patterns** — repeating gradients (stripes, dots, grids)
- **Shadows and depth** — `box-shadow`, `text-shadow`, `drop-shadow`
- **Decorative pseudo-elements** — `::before` / `::after` for shapes and accents
- **Generous whitespace** or controlled density to set mood

## Animation & Motion

Animation brings life to block themes, but WordPress block markup requires a specific pattern to connect CSS animations to blocks.

### The className pattern

Add animation classes to blocks via the `className` JSON attribute. WordPress renders this as a class on the wrapper div:

```html
<!-- wp:group {"className":"fade-up","align":"full","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull fade-up">
  <!-- content -->
</div>
<!-- /wp:group -->
```

This works on any block — groups, columns, headings, paragraphs, buttons, images.

### Animation classes in style.css

Generate animation classes in `style.css`. The set below is a starter — adapt names and timings to the design.

**Entrance animations:**

```css
.fade-up      { opacity: 0; transform: translateY(30px); animation: fadeUp 0.6s ease forwards; }
.fade-in      { opacity: 0; animation: fadeIn 0.6s ease forwards; }
.slide-in-left  { opacity: 0; transform: translateX(-40px); animation: slideIn 0.7s ease forwards; }
.slide-in-right { opacity: 0; transform: translateX(40px);  animation: slideIn 0.7s ease forwards; }

@keyframes fadeUp  { to { opacity: 1; transform: translateY(0); } }
@keyframes fadeIn  { to { opacity: 1; } }
@keyframes slideIn { to { opacity: 1; transform: translateX(0); } }
```

**Staggered children** — apply the entrance animation to the container, delay each child via `nth-child`:

```css
.stagger-children > * { opacity: 0; transform: translateY(20px); animation: fadeUp 0.5s ease forwards; }
.stagger-children > *:nth-child(1) { animation-delay: 0.1s; }
.stagger-children > *:nth-child(2) { animation-delay: 0.2s; }
.stagger-children > *:nth-child(3) { animation-delay: 0.3s; }
.stagger-children > *:nth-child(4) { animation-delay: 0.4s; }
```

**Interactive transitions and ambient motion** — use sparingly (one or two ambient effects per page):

```css
.hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
.hover-lift:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.15); }

.float       { animation: float 3s ease-in-out infinite; }
@keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
.pulse-subtle{ animation: pulse 2s ease-in-out infinite; }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.7; } }
```

### Scroll-triggered reveals (IntersectionObserver)

The highest-impact pattern — sections revealing as the user scrolls. Add a small observer in `functions.php` and pair it with CSS that hides elements until the `.is-visible` class is added:

```php
function theme_slug_scroll_animations() { ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var els = document.querySelectorAll('.animate-on-scroll');
        if (!els.length) return;
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15 });
        els.forEach(function(el) { observer.observe(el); });
    });
    </script>
<?php }
add_action( 'wp_footer', 'theme_slug_scroll_animations' );
```

```css
.animate-on-scroll { opacity: 0; transform: translateY(30px); transition: opacity 0.6s ease, transform 0.6s ease; }
.animate-on-scroll.is-visible { opacity: 1; transform: translateY(0); }
```

Apply `className: "animate-on-scroll"` on section-level Group blocks.

### prefers-reduced-motion (required)

Every theme MUST include this in `style.css` to respect the user's motion preference:

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    transition-duration: 0.01ms !important;
  }
  .animate-on-scroll,
  .fade-up, .fade-in,
  .slide-in-left, .slide-in-right,
  .scale-up {
    opacity: 1 !important;
    transform: none !important;
  }
}
```

### Editor Visibility (required)

The theme's `style.css` loads in both the front-end and the WordPress block editor (via `enqueue_block_assets`). The IntersectionObserver script that triggers scroll reveals, however, runs only on `wp_footer` — not inside the editor iframe. Any block with an entrance animation class that sets `opacity: 0` would be **invisible in the block editor** without an override.

WordPress wraps all editor content inside `<div class="editor-styles-wrapper">`. That class does not exist on the front-end, so it is the correct selector for editor-only overrides:

```css
/* === Editor: keep animated content visible while editing === */
.editor-styles-wrapper .fade-up,
.editor-styles-wrapper .fade-in,
.editor-styles-wrapper .slide-in-left,
.editor-styles-wrapper .slide-in-right,
.editor-styles-wrapper .scale-up,
.editor-styles-wrapper .animate-on-scroll,
.editor-styles-wrapper .stagger-children > * {
  opacity: 1 !important;
  transform: none !important;
  animation: none !important;
  transition: none !important;
}
```

The rule is simple: **every custom class that sets `opacity: 0` or uses `transform` as an initial hidden state needs a matching `.editor-styles-wrapper` override that makes it visible while editing.** Skip this and the editor renders empty boxes where the animated sections should be — the most common bug in agent-generated themes.

### How much animation

Not every element needs animation. Prioritise:

- Hero section entrance — the first impression (fade-up, scale, or slide)
- Section reveals on scroll — major content blocks with `animate-on-scroll`
- Interactive elements — cards with `hover-lift`, buttons with transitions
- One or two decorative ambient animations — a floating shape, gradient shift, or pulsing accent

Avoid animating every heading, paragraph, and button individually — it creates visual noise rather than delight.

## functions.php essentials

Keep `functions.php` minimal. Primary uses are local asset enqueuing and pattern registration. **Always** use the `enqueue_block_assets` hook (not `wp_enqueue_scripts`) so assets load in both the front-end AND the block editor. Do not enqueue external font CDNs; declare bundled fonts in `theme.json` with `fontFace` entries pointing at files under `assets/fonts/`.

```php
function theme_slug_enqueue_assets() {
	wp_enqueue_style(
		'theme-slug-style',
		get_stylesheet_uri(),
		array(),
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'enqueue_block_assets', 'theme_slug_enqueue_assets' );
```

Security notes:

- When `functions.php` outputs any user-derived value, use the appropriate WordPress escaping function: `esc_html()`, `esc_attr()`, `esc_url()`.
- Never use `eval()`, `create_function()`, `shell_exec()`, `exec()`, or `system()` in generated theme code.
- Static block themes with hard-coded markup (the default for our generator) do not need escaping at render time — WordPress core blocks handle output safely.

## Typical Workflows

### Inspect current theme templates

1. Use `sd-ai-agent/list-block-templates` to see all templates and overrides.
2. Use `sd-ai-agent/parse-block-content` on a template's content to analyse structure.

### Add a section site-wide

For elements that appear on every page (announcement bar, banner, footer CTA), edit the relevant **template part** (e.g. `parts/header.html`) rather than each template individually.

### Find patterns for page building

1. Use `sd-ai-agent/list-block-patterns` with a relevant category (`featured`, `header`, `footer`, etc.).
2. Review pattern content for suitable layouts.
3. Adapt the pattern's block markup using `sd-ai-agent/create-block-content`.

### Override a parent theme template (child theme)

Place a same-named `.html` file in the child theme's `templates/` or `parts/` directory. WordPress prefers the child version.

## Verification

After editing templates or `theme.json`:

1. Visit the front-end and confirm the change rendered.
2. Check the Site Editor (`/wp-admin/site-editor.php`) — it should reflect the saved state.
3. If `theme.json` settings appear ignored, clear the WordPress object cache (`wp cache flush`) and hard-refresh.
4. For child theme overrides, confirm the active stylesheet is the child (`wp option get stylesheet`).
5. Open the block editor on any page that uses entrance-animation classes — every animated section should render visibly, not as an empty box. If a section is invisible, an `.editor-styles-wrapper` override is missing.
6. Toggle the OS-level "Reduce motion" preference and reload the front-end — animations should collapse to near-zero duration without leaving content stuck at `opacity: 0`.
7. For generated themes or site scaffolds, perform and report a final quality review before saying the theme is complete: prefer browser/screenshot/front-end review of homepage plus an interior template; if unavailable, structurally review `theme.json`, header/footer, templates, hierarchy, spacing, typography, color contrast, navigation/footer, responsive risks, stock-image avoidance, and state that visual QA was limited.

## See also

- `gutenberg-blocks` → **Block-theme layout cascade** — the three structural patterns (full-bleed/constrained, full-bleed/full-bleed, plain constrained) that cause ~80% of "looks broken" outputs. Required reading before generating any landing-page section.
- `wp-block-development` → Block.json, `apiVersion`, dynamic rendering, and deprecations when the theme ships custom blocks.
