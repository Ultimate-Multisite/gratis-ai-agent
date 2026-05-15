# Gutenberg Blocks

## When to Use

Triggers: creating, editing, or debugging WordPress block markup; mentions of "page builder," "Gutenberg," "blocks," "columns," "hero," "landing page," "layout," "wp:paragraph," "wp:cover," "wp:group," `block validation` errors, or any `wp:*` block name. Also use whenever generating page content for a non-Kadence theme. For Kadence-specific blocks (`kadence/*`), use the `kadence-blocks` skill instead.

## Critical Rule — never mix formats

Content passed to `sd-ai-agent/create-post` or `sd-ai-agent/update-post` must be EITHER:
- **All markdown** — for blog posts and articles (auto-converted to blocks)
- **All serialized block markup** — for pages with visual layouts

**NEVER mix raw markdown with block markup.** Mixed content renders incorrectly.

## Quick diagnostic — symptom → fix

| Symptom or error | Fix |
|---|---|
| `Block validation: Expected tag name 'h2', instead saw 'hN'` | Add the matching `level` to the heading attribute, or fix the rendered tag. |
| `This block contains unexpected or invalid content` | Open the editor, click "Attempt Block Recovery" — the diff points at the bad attribute. |
| Mixed markdown + blocks rendering as escaped text | Convert the whole post to one format. Run `validate-block-content` first. |
| Columns rendering vertically | Wrap in `wp:columns` (not raw flex CSS), and confirm each `wp:column` is a direct child. |
| Buttons inheriting wrong style | Use `is-style-fill` (default) or `is-style-outline` via `className`. Don't override with raw CSS. |
| Cover block image not showing | Set both `url` and the `<img class="wp-block-cover__image-background">` element inside the saved markup. |
| Spacing presets ignored | Use `var:preset|spacing|XX` syntax in attributes AND matching CSS variable in inline `style`. |
| Custom theme colors not applying | Reference theme palette slugs via `backgroundColor`/`textColor`, not hex codes via `style`. |

## Core rules (always apply)

1. **Block comments wrap standard HTML; attributes are JSON in the opening comment.** Closing comment matches the opener: `<!-- /wp:name -->`.
2. **Self-closing block markers** (`<!-- wp:foo /-->`) are valid only for blocks with no inner HTML (e.g. `wp:nextpage`, `wp:more`).
3. **Container blocks must own their wrapper element.** A `wp:columns` block emits `<div class="wp-block-columns">…</div>`; never split the wrapper across multiple block comments.
4. **Inner blocks must be direct children of the right container.** `wp:column` only inside `wp:columns`; `wp:list-item` only inside `wp:list`; `wp:button` only inside `wp:buttons`.
5. **Class names must match the block's `save()` output.** Headings use `wp-block-heading`, lists use `wp-block-list`, buttons use `wp-block-button` + `wp-block-button__link wp-element-button` on the `<a>`. Missing or extra classes trigger validation errors.
6. **Use theme presets before raw values.** `{"backgroundColor":"primary"}` over `{"style":{"color":{"background":"#…"}}}`; `{"fontSize":"large"}` over `{"style":{"typography":{"fontSize":"24px"}}}`.
7. **Run `sd-ai-agent/validate-block-content` before insertion** for any non-trivial layout. It catches mixed content and malformed markup before the editor does.
8. **Don't generate `wp:html` for layout.** Use `wp:columns`, `wp:group`, `wp:cover` instead — `wp:html` blocks aren't editable in the visual editor and skip theme styling.

## Available Tools

- `sd-ai-agent/create-block-content` — Build block HTML from a structured block array (best for complex nested layouts)
- `sd-ai-agent/parse-block-content` — Parse existing block content into a structured tree
- `sd-ai-agent/validate-block-content` — Validate block content before insertion (catches mixed content, malformed markup)
- `sd-ai-agent/list-block-types` — Browse and search registered block types
- `sd-ai-agent/get-block-type` — Get full metadata for a specific block type
- `sd-ai-agent/list-block-patterns` — Browse and search registered block patterns

## Block Markup Reference

### Paragraph

```html
<!-- wp:paragraph -->
<p>This is a paragraph of text with <strong>bold</strong> and <em>italic</em> formatting.</p>
<!-- /wp:paragraph -->
```

With custom text color:

```html
<!-- wp:paragraph {"style":{"color":{"text":"#555555"}}} -->
<p class="has-text-color" style="color:#555555">Muted paragraph text.</p>
<!-- /wp:paragraph -->
```

### Heading

Default (h2):

```html
<!-- wp:heading -->
<h2 class="wp-block-heading">Section Title</h2>
<!-- /wp:heading -->
```

H3 with custom size:

```html
<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">Subsection Title</h3>
<!-- /wp:heading -->
```

### Image

```html
<!-- wp:image {"id":42,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="https://example.com/photo.jpg" alt="Description" class="wp-image-42"/></figure>
<!-- /wp:image -->
```

With caption and alignment:

```html
<!-- wp:image {"align":"wide","id":42,"sizeSlug":"full"} -->
<figure class="wp-block-image alignwide size-full"><img src="https://example.com/photo.jpg" alt="Description" class="wp-image-42"/><figcaption class="wp-element-caption">Photo caption here</figcaption></figure>
<!-- /wp:image -->
```

### List (unordered)

```html
<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>First item</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Second item</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Third item</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->
```

### List (ordered)

```html
<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><!-- wp:list-item -->
<li>Step one</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Step two</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list -->
```

### Buttons

Single button:

```html
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact">Get in Touch</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
```

Two buttons (primary + outline):

```html
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/start">Get Started</a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/learn-more">Learn More</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
```

### Separator

```html
<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->
```

Wide line:

```html
<!-- wp:separator {"className":"is-style-wide"} -->
<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/>
<!-- /wp:separator -->
```

### Spacer

```html
<!-- wp:spacer {"height":"40px"} -->
<div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->
```

### Quote

```html
<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>The best way to predict the future is to create it.</p>
<!-- /wp:paragraph --><cite>Peter Drucker</cite></blockquote>
<!-- /wp:quote -->
```

## Layout Blocks

### Columns (two column)

```html
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Left Column</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Content for the left side.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Right Column</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Content for the right side.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
```

Custom column widths (2/3 + 1/3):

```html
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"66.66%"} -->
<div class="wp-block-column" style="flex-basis:66.66%"><!-- wp:paragraph -->
<p>Wider column content.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"33.33%"} -->
<div class="wp-block-column" style="flex-basis:33.33%"><!-- wp:paragraph -->
<p>Narrower column.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
```

### Three columns

```html
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Feature One</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Description of the first feature.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Feature Two</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Description of the second feature.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Feature Three</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Description of the third feature.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
```

### Group (container with background)

```html
<!-- wp:group {"style":{"color":{"background":"#f0f0f1"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background" style="background-color:#f0f0f1"><!-- wp:heading -->
<h2 class="wp-block-heading">Section in a colored container</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>This content has a light grey background.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
```

Full-width group with padding:

```html
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50","right":"var:preset|spacing|50"}},"color":{"background":"#1e1e1e","text":"#ffffff"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-text-color has-background" style="color:#ffffff;background-color:#1e1e1e;padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)"><!-- wp:heading {"textColor":"white"} -->
<h2 class="wp-block-heading has-white-color has-text-color">Dark Section</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>White text on dark background for contrast sections.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
```

### Cover (image/color overlay with text)

```html
<!-- wp:cover {"url":"https://example.com/hero.jpg","dimRatio":50,"align":"full"} -->
<div class="wp-block-cover alignfull"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><img class="wp-block-cover__image-background" alt="" src="https://example.com/hero.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:heading {"textAlign":"center","level":1,"textColor":"white"} -->
<h1 class="wp-block-heading has-text-align-center has-white-color has-text-color">Welcome to Our Site</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","textColor":"white"} -->
<p class="has-text-align-center has-white-color has-text-color">Your tagline or value proposition goes here.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact">Get Started</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div></div>
<!-- /wp:cover -->
```

Color-only cover (no image):

```html
<!-- wp:cover {"overlayColor":"primary","align":"wide"} -->
<div class="wp-block-cover alignwide"><span aria-hidden="true" class="wp-block-cover__background has-primary-background-color has-background-dim-100 has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph {"align":"center","fontSize":"large","textColor":"white"} -->
<p class="has-text-align-center has-white-color has-text-color has-large-font-size">Call to action text in a colored banner.</p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->
```

## Page Layout Patterns

### Landing Page Structure

A typical landing page uses this block sequence:

1. **Hero** — Cover block with heading, tagline, and CTA button
2. **Features** — Three-column layout with heading + paragraph in each
3. **About section** — Two-column (text + image) in a Group
4. **Testimonial** — Quote block in a Group with background
5. **CTA** — Group with background, heading, paragraph, and buttons

### About Page Structure

1. **Page title** — Heading (h1 or h2)
2. **Introduction** — Paragraph
3. **Story/Mission** — Two-column (image + text)
4. **Values/Team** — Three-column feature grid
5. **CTA** — Group with contact button

### Services Page Structure

1. **Introduction** — Heading + paragraph
2. **Service cards** — Three-column layout, each with heading + paragraph + button
3. **Process** — Ordered list or numbered steps
4. **CTA** — Cover or Group with contact button

## Common Attributes Reference

### Alignment

```json
{"align": "left"}     // Float left
{"align": "center"}   // Center
{"align": "right"}    // Float right
{"align": "wide"}     // Wide width
{"align": "full"}     // Full width (edge to edge)
```

### Colors

Using theme presets:

```json
{"backgroundColor": "primary"}
{"textColor": "secondary"}
```

Using custom hex:

```json
{"style": {"color": {"background": "#f0f0f1", "text": "#333333"}}}
```

### Typography

```json
{"fontSize": "small"}           // Theme preset
{"fontSize": "medium"}
{"fontSize": "large"}
{"fontSize": "x-large"}
{"style": {"typography": {"fontSize": "18px"}}}  // Custom
```

### Spacing

Using theme presets:

```json
{"style": {"spacing": {"padding": {"top": "var:preset|spacing|50", "bottom": "var:preset|spacing|50"}}}}
```

Using custom values:

```json
{"style": {"spacing": {"padding": {"top": "2rem", "bottom": "2rem", "left": "2rem", "right": "2rem"}}}}
```

### Text alignment

```json
{"textAlign": "center"}
{"textAlign": "left"}
{"textAlign": "right"}
```

## Workflows

### Create a blog post (use markdown)

Write content in markdown format and pass to `sd-ai-agent/create-post`. The markdown is automatically converted to blocks.

### Build a short page (single tool call)

For a page that fits comfortably in one response (under ~6KB of block markup, roughly a hero + 2 short sections):

1. Write the full page content as serialized block markup following the examples above
2. Pass the block markup string to `sd-ai-agent/create-post` with `post_type: page`
3. Use `sd-ai-agent/validate-block-content` to check for errors before creating

### Build a LONG landing page

Modern frontier models (Claude Sonnet 4+, GPT-5, Gemini 2.5 Pro) can emit 32–128K output tokens in a single response, which is more than enough for a full hero + features + how-it-works + use-cases + pricing + FAQ + CTA landing page in one `create-post` call. Prefer that when you can — one tool call, one verified result, no concatenation logic.

Reach for incremental building only when:

- You are using a weaker / older model with a low per-response cap (Claude 3.x, GPT-4o, Gemini 1.5/2.0) and the page genuinely will not fit.
- You are extending or editing an existing page rather than authoring a new one.
- You want to stream visible progress to the user section by section.

Incremental workflow when needed:

1. Call `sd-ai-agent/create-post` with the hero + intro. Capture the returned `post_id`.
2. For each remaining section, call `sd-ai-agent/append-post-content` with `{post_id, content: "<one section of block markup>"}`. Server-side concatenation means you never re-send existing content.
3. Optionally call `sd-ai-agent/get-post` at the end to verify total length and structure.

Never use `update-post` to "append" by re-sending the full accumulated content — it wastes tokens and reintroduces truncation risk. Use `append-post-content` instead.

### Analyze and improve existing content

1. Use `sd-ai-agent/parse-block-content` with a post_id to see the current structure
2. Identify issues (missing layout blocks, unstyled sections)
3. Build improved content using block markup
4. Use `sd-ai-agent/update-post` to replace the content (only if the new content fits in one call — otherwise rebuild incrementally as above)

## Verifying generated markup

After generating block markup, verify before committing it to a published post:

1. Run `sd-ai-agent/validate-block-content` — catches mixed-content and malformed markup.
2. If creating a draft via the editor: open it, look for "This block contains unexpected or invalid content." Click "Attempt Block Recovery" — the diff points at the offending attribute.
3. View the rendered page in a new tab; check the browser console for validation warnings.
4. If a rule here conflicts with what your installed WordPress version produces, trust the editor's saved output — copy it and use it as your reference.
