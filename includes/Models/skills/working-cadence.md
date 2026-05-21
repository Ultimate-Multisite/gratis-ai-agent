# Working Cadence for Content and Theme Generation

## When to Load

This skill is automatically injected into the system prompt whenever content-generation
or theme-modification abilities are active (e.g. `sd-ai-agent/create-post`,
`sd-ai-agent/scaffold-block-theme`, `sd-ai-agent/file-write`). Load it manually
when you need to reference the skeleton-first pattern or anchor-comment convention.

## Working Cadence Rules

**One Write or Edit per turn for content >50 lines.** Read-only inspection tools
(`get-post`, `list-posts`, `get-block-type`) may be combined within a turn.
Short prose between tools — no long design-plan essays.

### Long Files: Skeleton First, Then Fill Across Edits

**style.css (>200 lines):**

1. Emit a skeleton: `:root { ... }` custom properties + 6–10 anchor comments
   `/* === <concern> === */` (e.g. `reset`, `typography`, `hero`, `features`, `cta`,
   `footer`, `responsive`), <2KB total.
2. Fill one anchor per Edit (300–2000B each).
   - `oldString`: the anchor line (`/* === typography === */`)
   - `newString`: `/* === typography === */\n\n<styles>`
3. **Never overwrite a freshly scaffolded `style.css`** — it contains the required
   theme header. Always Edit to append; never Write to replace.

**Page content (>300 lines):**

1. Create the post empty: `wp_insert_post` with empty content (or `sd-ai-agent/create-post`
   with `status: "draft"` and no content).
2. Write block markup with anchor comments `<!-- section:hero -->`, one anchor per section.
3. Fill one anchor per Edit — `oldString` is the anchor comment, `newString` is the
   anchor followed by the full block markup for that section.
4. When all anchors are filled, publish via `sd-ai-agent/update-post` with `status: "publish"`.

## Why This Matters

1. **Gateway timeouts.** Asking a model to produce a complete 600-line `style.css` in
   one turn frequently times out, returns partial output, or burns the entire turn budget
   on a single file. The skeleton/anchor cadence produces deliverable increments.
2. **Lost screenshot-fix loop.** The validate → screenshot → fix loop only works if
   visual increments are small enough to inspect. A monolithic write blocks feedback
   until the whole file is done.

## Reference Pattern: style.css Skeleton

```css
/*
Theme Name: My Theme
Theme URI:
Author: My Name
Author URI:
Description: A custom block theme.
Version: 1.0.0
Requires at least: 7.0
Requires PHP: 8.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: my-theme
Tags: block-theme, full-site-editing
*/

/* === reset === */

/* === custom-properties === */

/* === typography === */

/* === layout === */

/* === header === */

/* === hero === */

/* === features === */

/* === footer === */

/* === responsive === */
```

Then fill each anchor in subsequent Edits:

```
oldString: "/* === typography === */"
newString: "/* === typography === */\n\nbody {\n  font-family: var(--wp--preset--font-family--body);\n  font-size: var(--wp--preset--font-size--medium);\n  line-height: 1.6;\n}"
```

## Reference Pattern: Page Content Anchors

```html
<!-- section:hero -->

<!-- section:features -->

<!-- section:cta -->

<!-- section:testimonials -->

<!-- section:footer-cta -->
```

Fill one anchor per Edit, then publish the assembled content.

## Verification

After a `sd-ai-agent/scaffold-block-theme` call, the scaffolded `style.css` must
contain only the theme header comment (<500 bytes). If it is larger, the scaffold
has regressed — file an issue immediately and do not proceed with the skeleton step
(there is no clean anchor baseline).
