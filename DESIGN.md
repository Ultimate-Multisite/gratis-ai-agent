# Design System: sd-ai-agent

The design language for this plugin is **modern WordPress Admin** — clean, minimal, and immediately familiar to anyone who uses wp-admin daily. Every decision should reduce visual noise, guide the user's eye to what matters, and feel like a natural extension of WordPress rather than a foreign product dropped inside it.

---

## 1. Visual Theme & Atmosphere

The interface inherits the WordPress admin aesthetic: neutral greys, a single blue accent pulled from the site's admin colour scheme, and consistent 13px body text. No gradients, no drop-shadow stacks, no decorative elements. The goal is calm utility.

**Key characteristics:**

- Neutral, low-saturation surface colours — white and `#f6f7f7` only
- One accent colour: `var(--wp-admin-theme-color)` — never hardcoded, always inherited
- Borders over shadows for containment; shadows reserved for floating layers only
- Density matches wp-admin: compact but not cramped (13px text, 8–12px padding)
- Icons replace labels wherever the action is unambiguous — fewer words, same clarity
- Interactive states are subtle: hover darkens by one step, focus adds a 1px ring, nothing jumps or scales

---

## 2. Colour Palette & Roles

All colours are drawn from the WordPress admin palette. Custom colours are not introduced; the plugin adapts to the site owner's chosen admin colour scheme via CSS custom properties.

### Primary Brand
- **Primary** (`var(--wp-admin-theme-color, #2271b1)`): buttons, active states, focus rings, links, icon highlights, active sidebar item accent
- **Primary Dark** (`var(--wp-admin-theme-color-darker-10, #135e96)`): primary button hover

### Text Colours
- **Text Primary** (`#1d2327`): headings, body copy, input text, button labels
- **Text Secondary** (`#50575e`): descriptions, secondary labels, metadata
- **Text Muted** (`#787c82`): placeholder text, inactive icons, supporting copy
- **Text Disabled** (`#a7aaad`): disabled inputs, unavailable actions

### Surface & Background
- **Page Background** (`#fff`): main chat panel, input area, modal dialogs
- **Surface** (`#f6f7f7`): sidebar, cards, code block headers, empty-state backgrounds
- **Surface Hover** (`#f0f0f1`): hover state on list items, cards, secondary buttons
- **Surface Active** (`#f0f6fc`): selected/active states, icon badge backgrounds, user message bubbles

### Borders
- **Border Strong** (`#c3c4c7`): input fields, panel containers, prominent dividers
- **Border Subtle** (`#dcdcde`): internal dividers, list item separators, card edges
- **Border Accent** (`var(--wp-admin-theme-color, #2271b1)`): focused inputs, active tabs, hover on utility buttons

### Semantic
- **Success** (`#00a32a`): confirmation badges, completed states
- **Warning** (`#dba617`): debug mode badge, caution indicators
- **Error** (`#d63638`): error messages, destructive actions, stop-button hover, required field markers
- **Error Surface** (`#fcf0f1`): error message backgrounds

### Shadows (floating layers only)
- **Raised** (`0 1px 4px rgba(0,0,0,.12)`): FAB button, floating widget
- **Elevated** (`0 4px 16px rgba(0,0,0,.14)`): template menu, dropdowns
- **Overlay** (`0 8px 32px rgba(0,0,0,.20)`): modal dialogs, shortcuts panel

---

## 3. Typography Rules

### Managed AI naming

- Display the bundled provider as **SD AI** in selectors, connection notices, and account screens.
- Display its chat models as **Speedy**, **Standard**, and **Strong**. The provider grouping supplies the product context, so do not prefix these labels with the provider name.
- Keep the stable internal provider and model IDs unchanged (`sd-ai-agent-cloud`, `superdav-chat-fast`, `superdav-chat-pro`, and `superdav-chat-strong`). These identifiers are implementation contracts, not customer-facing copy.

### Font Families
- **UI / Body**: inherits wp-admin system font stack — `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif`
- **Monospace**: `ui-monospace, SFMono-Regular, SF Mono, Menlo, Consolas, Liberation Mono, monospace` — code blocks, debug panels, tool arguments

Never set a custom font family. The plugin must feel native to the admin environment the user is already in.

### Hierarchy

| Role | Size | Weight | Line Height | Notes |
|------|------|--------|-------------|-------|
| Page heading | 20px | 600 | 1.3 | Wizard/interview headers only |
| Section heading | 15px | 600 | 1.4 | Welcome greeting, dialog titles |
| Body | 13px | 400 | 1.5 | All standard UI text — matches wp-admin base |
| Body emphasis | 13px | 600 | 1.5 | Card titles, session titles when active |
| Small / caption | 12px | 400 | 1.4 | Metadata, timestamps, tab labels |
| Micro | 11px | 400–600 | 1.33 | Badges, status labels, attachment names |
| Code | 13px | 400 | 1.5 | Inline code; 0.85em relative in prose |
| Code block | 11–12px | 400 | 1.5 | Syntax-highlighted blocks |
| Button label | 13px | 400 | 1.14 | Inherits from `@wordpress/components` |

### Principles
- 13px is the baseline. Do not use 14px or 16px for body text — it looks out of place in wp-admin.
- Emphasise with weight (`600`), not size. Size jumps should be reserved for genuine hierarchy changes.
- Line lengths in the chat panel should be bounded (`max-width: 85%` on bubbles, capped at `900px` on wide screens) to maintain readability.

---

## 4. Component Guidelines

### General rule
Use `@wordpress/components` for every interactive element. Do not reach for raw `<button>` or `<input>` elements unless unavoidable (e.g. hidden file inputs). Using WordPress components ensures keyboard accessibility, focus management, and visual consistency come for free.

### Privacy review screens
- Use a compact filterable summary list with an explicit detail selection; never place transcript text, profile identifiers, tokens, hashes, tool data, or raw provider payloads in list rows.
- Keep transcript detail text-only, bounded, and visibly separate from its summary. Use pagination to load earlier retained messages instead of rendering an unbounded conversation at once.
- Deletion and purge controls are destructive secondary buttons. Require a native confirmation dialog or WordPress modal before calling the API, and clearly state that retained content cannot be restored.

### Buttons

**Primary action** (send, save, confirm):
- Use `<Button variant="primary">` from `@wordpress/components`
- Only one primary button per view
- Icon-only primary buttons (send): 32×32px, filled `--wp-admin-theme-color`, white icon, 6px radius
- Disabled: `#c3c4c7` background, white icon, `opacity: 1` (don't layer opacity on grey)

**Secondary / utility** (templates, upload, mic, stop):
- Transparent background, `1px solid #c3c4c7` border, 6px radius
- 32×32px icon-only, `color: #787c82`
- Hover: border shifts to `--wp-admin-theme-color`, icon colour shifts to match
- Active/toggled: `background: #f0f6fc`, border `--wp-admin-theme-color`

**Tertiary / ghost** (cancel, skip):
- Use `<Button variant="tertiary">` — no border, no background
- Reserve for secondary actions inside dialogs where a full button would compete

**Destructive**:
- Use `<Button variant="secondary">` with explicit `isDestructive` — red text, red border
- For icon-only stop buttons: neutral grey → red background on hover

**Icon buttons (message actions)**:
- 26×26px, `background: #fff`, `border: 1px solid #e0e0e0`, `border-radius: 5px`
- Subtle `box-shadow: 0 1px 2px rgba(0,0,0,.06)`
- Hidden by default (`opacity: 0`), revealed on parent row hover
- Hover: `background: #f0f0f1`, `border-color: #c3c4c7`
- Always pair with `showTooltip` and a descriptive `label` prop

**Never use:**
- Raw `<button>` elements for visible actions
- Text labels where an icon with tooltip is sufficient
- More than one primary button per panel

### Icons

Use `@wordpress/icons` exclusively. Do not use emoji, Unicode symbols (✕, ⋯), or custom SVGs unless a concept has no equivalent in the icon library.

Standard sizes:
- `18px` — input row buttons (send, mic, upload)
- `20px` — suggestion card badges
- `16px` — sidebar "more" menu trigger
- `14px` — message action buttons (copy, edit, regenerate)
- `12px` — inline status indicators (pin, shared)

Icon-to-concept mapping used in this project:

| Concept | Icon |
|---------|------|
| Send message | `arrowUp` |
| Stop generation | `closeSmall` |
| Templates | `pages` |
| Copy | `copy` |
| Edit message | `pencil` |
| Regenerate | `redo` |
| Copied confirmation | `check` |
| Pin | `pin` |
| Shared | `people` |
| More options | `moreVertical` |
| Close / dismiss | `closeSmall` |
| New conversation | `plus` |
| Import | `upload` |
| Site health | `heartFilled` (or inline SVG fallback) |
| Plugins | `plugins` |
| Blog post | `post` |
| Security | `shield` |
| SEO / trends | `trendingUp` |
| Updates | `update` |

### Inputs

- `border: 1px solid #c3c4c7`, `border-radius: 6px`, `padding: 7px 10px`
- Focus: `border-color: --wp-admin-theme-color`, `box-shadow: 0 0 0 1px --wp-admin-theme-color`
- Disabled: `background: #f6f7f7`, `color: #a7aaad`
- Textarea (chat input): `resize: none`, `font-family: inherit`, `font-size: 13px`
- Use `<SelectControl>` from `@wordpress/components` for dropdowns — never raw `<select>`

### Message Bubbles

User messages:
- `background: #f0f6fc`, `border: 1px solid #c5d9ed`
- `border-radius: 8px 8px 2px 8px` — tail at bottom-right
- `align-self: flex-end` — right-aligned

Assistant messages:
- `background: #fff`, `border: 1px solid #dcdcde`
- `border-radius: 8px 8px 8px 2px` — tail at bottom-left
- `align-self: flex-start` — left-aligned

Both: `max-width: 85%`, `font-size: 13px`, `line-height: 1.55`, `padding: 9px 13px`

Action buttons appear below the bubble on row hover, aligned to match the bubble side.

System notices:
- Reserve the red error surface for true failures that require troubleshooting.
- Account-action notices (billing, connection, setup) should use `Surface Active` with `Border Accent`, `role="status"`, friendly action-oriented copy, and a single primary button-style link CTA when an action URL is available.
- Avoid framing customer account actions as errors; do not surface provider phrases such as “rejected” or “insufficient” in the chat UI when a clearer next step is available.
- Background-job failure cards use fixed, customer-safe copy with one next step, an optional safe last-completed phase, and a correlation ID for support. Never show provider errors, prompts, tool payloads, paths, or stack traces; only show a retry button when retry is the prescribed action.

### Cards (suggestion / template)

- `background: #fff`, `border: 1px solid #dcdcde`, `border-radius: 8px`
- `padding: 12px 14px`, `display: flex; flex-direction: column; gap: 4px`
- Icon badge: 32×32px, `background: #f0f6fc`, `color: --wp-admin-theme-color`, `border-radius: 6px`
- Hover: `border-color: --wp-admin-theme-color`, `box-shadow: 0 2px 8px rgba(0,0,0,.06)`
- No transform or scale on hover — keep transitions to colour/border only

### Monitor/Pulse settings

- Keep **Scheduled Tasks** and **Monitor/Pulse** as separate settings surfaces. Tasks perform work on every schedule; a Monitor assesses a checklist and only notifies when attention is needed.
- A newly saved Monitor, template-derived Monitor, or edited disabled Monitor is visibly a **Disabled draft**. Show the consent explanation before its configuration metadata and never imply that saving, editing, revisiting the page, or checking a draft schedules it.
- Event wakes are separate consent from recurring monitoring: list only server-provided approved sources, keep the opt-in disabled until at least one source is selected, explain that wakes are coalesced and processed by WP-Cron, and show only compact queue counts rather than event payloads.
- A disabled card has one primary opt-in action: **Enable monitoring**. **Check now** is a secondary action available only on a disabled draft and must be labelled as a one-off check, not a scheduling shortcut.
- Show outcome, cadence, owner, tool profile, notification policy, last check, and next expected time in a compact definition list. Explain that WP-Cron timing is traffic-dependent; do not use an expected time as a health or on-time promise.
- Outcome badges use semantic WordPress admin colours: quiet/success, attention-needed/warning, and blocked/error. Keep the latest summary and error bounded, text-only, and visually distinct from configuration controls.
- Keep durable log inspection behind an explicit **Inspect logs** action. Delete remains a destructive secondary action with a native confirmation dialog.
- On compact screens, stack Monitor headers, action rows, form fields, notification-channel controls, and detail metadata into one column without hiding consent or outcome context.

### SD AI Account Card

- Show the verified linked user's display name, masked email, and a concise “Email verified” status above wallet details.
- When no verified user is linked, label the primary linking intent **Connect account to site**; reserve **Link a different user** for an existing linked identity.
- Use **Link a different user** as a secondary action. Relinking must open the managed confirmation flow rather than accepting identity details in WordPress.
- **Open account portal**, **Manage payment methods**, and **Add credits** are buttons that request a fresh one-time URL when clicked; do not render, inspect, or persist action tickets in the settings page.
- Open account actions in a separate tab when the browser permits, clear `window.opener`, and show one neutral retry notice if URL minting fails.
- Keep the account tab and each account card padded on every viewport: use the 2xl spacing token on desktop, the xl token on compact screens, and never let card content touch the settings panel edge.

### Tooltips

Use `<Tooltip>` from `@wordpress/components` (or `showTooltip + label` on `<Button>`) for every icon-only button. Do not rely on the native `title` attribute — it has poor accessibility and inconsistent browser styling.

---

## 5. Layout Principles

### Spacing Scale

Base unit: **4px**

| Token | Value | Common use |
|-------|-------|------------|
| xs | 4px | Icon padding, micro gaps |
| sm | 6px | Gap between icon buttons in a row |
| md | 8px | Grid gaps, list item internal spacing |
| lg | 12px | Panel padding, header padding |
| xl | 16px | Section padding, message list gap |
| 2xl | 24px | Wizard section padding |
| 3xl | 32px | Wizard/dialog body padding |

### Two-Panel Chat Layout

- Sidebar: `280px` fixed width (collapses to off-canvas overlay on mobile `< 700px`)
- Main panel: `flex: 1`, contains header + message list + input area
- Layout height: `calc(100vh - 140px)` in the wp-admin context (accounts for 32px admin bar + page title area)
- The input area must always be visible without scrolling — it is pinned to the bottom of the flex column

### Header

- `min-height: 44px`, `padding: 6px 12px`
- `background: #fafafa`, `border-bottom: 1px solid #dcdcde`
- Selectors (provider/model/agent) live here in compact form — no visible labels, just the dropdowns
- Status badges (DEBUG, YOLO) sit inline with a `<Tooltip>` wrapper
- One utility icon (TTS toggle) at the far right, no border by default

### Input Area

- `padding: 10px 12px 12px`, `border-top: 1px solid #dcdcde`, `background: #fff`
- Row order (left to right): templates icon → upload icon → textarea → mic icon → send/stop icon
- All icon buttons: 32×32px, consistent spacing (`gap: 5px`)
- Textarea grows with content; `resize: none`

**Block-editor context:**
- In the Gutenberg editor, place a compact selected-block chip and editor-mutation status immediately above the composer frame.
- Keep selection state presentation-only: store block IDs-derived labels and counts, never selected markup or block attributes, and never attach selection content to a chat request automatically.
- The chip may show two block labels before collapsing the remainder into a count. Its icon-only clear action must clear selection without changing block content and must retain a tooltip, accessible label, and visible keyboard focus.
- Announce mutation progress and outcomes with one polite atomic live region. Confirm success only when the tool result explicitly reports `applied: true`; stale, rejected, unavailable, and uncertain results require truthful next-step copy.
- On compact screens, let the chip and mutation copy occupy full rows. In forced-colour mode preserve explicit borders and focus outlines; under reduced-motion preferences stop spinner animation while retaining the textual progress label.

### Border Radius Scale

| Size | Value | Use |
|------|-------|-----|
| xs | 4px | Micro badges, list item focus rings |
| sm | 5–6px | Buttons, inputs, icon buttons |
| md | 8px | Message bubbles, suggestion cards, modals |
| lg | 12px | Floating panel, large dialogs |
| pill | 9999px | Filter chip tabs, category pills |

### Whitespace philosophy

Err toward more whitespace. Padding should feel generous inside panels. List items can be compact (7px vertical padding) because the sidebar is a dense navigation element — the chat area should breathe more.

### Account settings surfaces

- Separate account overview, usage, and activity surfaces with a `24px` vertical gap.
- Give each account surface `24px` desktop padding, reducing to `16px` on mobile, with an `8px` radius and the standard subtle border.
- Keep the account header focused on one primary action. Coupon redemption opens in a modal rather than placing a persistent form between account sections.
- Refresh managed account data automatically when the account screen mounts; do not require a manual refresh control for current balances.

---

## 6. Depth & Elevation

| Level | Treatment | Use |
|-------|-----------|-----|
| Flat (0) | No shadow, border only | All standard surfaces: bubbles, cards, inputs |
| Raised (1) | `0 1px 4px rgba(0,0,0,.12)` | FAB button, icon action buttons (subtle) |
| Elevated (2) | `0 4px 16px rgba(0,0,0,.14)` | Floating menus (template picker, slash menu) |
| Overlay (3) | `0 8px 32px rgba(0,0,0,.20)` | Modal dialogs, shortcuts panel |

Avoid stacking shadows. A surface is either flat (contained by border) or floating (elevated by shadow). Pick one.

---

## 7. Do's and Don'ts

**Do:**
- Inherit typography and colour from WordPress — use `font-family: inherit` and `var(--wp-admin-theme-color)`
- Use icon-only buttons with tooltips for secondary/repeated actions (copy, edit, regenerate, close)
- Use `@wordpress/components` — Button, Tooltip, SelectControl, Spinner, Icon
- Use `@wordpress/icons` for all iconography
- Keep the chat input area always visible in the viewport; never let it scroll out of view
- Show action buttons on hover only — keep the message list scannable at rest
- Use borders (not shadows) to contain flat surfaces
- Prefer `6px` or `8px` border-radius — avoid sharp `0` or `2px` corners on interactive elements
- Use `13px` as the base font size throughout
- Test new components against multiple WP admin colour schemes (blue, fresh, midnight, etc.)

**Don't:**
- Introduce custom brand colours — the palette is the WordPress admin palette
- Use raw `<button>` or `<select>` elements for visible UI
- Use emoji or Unicode symbols as icons
- Add gradients, background patterns, or decorative imagery
- Use font sizes below 11px
- Put labels on icon buttons that already have tooltips
- Animate layout properties (width, height, padding) — animate opacity and colour only
- Use `!important` except to override wp-admin admin notices (already established pattern)
- Add more than one prominent call-to-action per panel/screen
- Make the interface wider than it needs to be — max bubble width `85%` (capped at `900px` on large screens)

---

## 8. Responsive Behaviour

### Breakpoints

| Name | Width | Key Changes |
|------|-------|-------------|
| Mobile | < 700px | Sidebar becomes off-canvas drawer; hamburger toggle shown; layout fills full width |
| Tablet | 700–900px | Sidebar narrows to `200px` |
| Desktop | 900–1440px | Full two-panel layout at `280px` sidebar |
| Wide | > 1440px | Bubble `max-width` capped at `900px`; no other layout changes |

### Touch Targets
- Minimum interactive size: `44×44px` for primary actions on mobile
- Icon buttons (`32×32px`) are below this threshold — acceptable on desktop only; on mobile, add padding or increase hit area via `::after` pseudo-element if needed

### Mobile Rules
- Sidebar renders as a fixed overlay (`z-index: 9999`) triggered by a hamburger button
- A semi-transparent backdrop (`rgba(0,0,0,.4)`) covers the chat area when the sidebar is open
- The sidebar close button is visible only on mobile (hidden on desktop via `display: none`)
- The suggestion card grid collapses from 3 columns → 2 columns → 1 column at `780px` and `480px`

---

## 9. Agent Prompt Guide

When instructing an AI agent to build or modify UI in this plugin, include this reference block:

### Colour tokens

| Token | Value | Use |
|-------|-------|-----|
| `--wp-admin-theme-color` | `#2271b1` (fallback) | Primary accent — buttons, links, focus |
| `--wp-admin-theme-color-darker-10` | `#135e96` (fallback) | Primary button hover |
| Text primary | `#1d2327` | All body text |
| Text secondary | `#50575e` | Descriptions, metadata |
| Text muted | `#a7aaad` | Placeholders, disabled |
| Border strong | `#c3c4c7` | Inputs, panel edges |
| Border subtle | `#dcdcde` | Dividers, list items |
| Surface | `#f6f7f7` | Sidebar, card backgrounds |
| Surface active | `#f0f6fc` | Selected states, user bubbles |
| Error | `#d63638` | Errors, destructive |
| Success | `#00a32a` | Confirmations |

### Boilerplate prompts

**"Add a new icon button to the input row"**
> Use `<Button>` from `@wordpress/components` with a `<Icon icon={X} size={18} />` from `@wordpress/icons`. Size: `32×32px`, `border-radius: 6px`, transparent background, `border: 1px solid #c3c4c7`, `color: #787c82`. Add `showTooltip` and a descriptive `label`. Add the `.components-button` CSS specificity pattern for overrides. Follow the existing stop/mic/templates button pattern in `src/components/message-input.js`.

**"Add a new suggestion card"**
> Add an entry to `SUGGESTION_CARDS` in `src/components/message-list.js` with `title`, `description`, `prompt`, and `icon` (a `<Icon icon={X} size={20} />` from `@wordpress/icons`). The card renders with a 32×32px icon badge, 13px bold title, and 11px description. Hover adds a theme-colour border and `box-shadow: 0 2px 8px rgba(0,0,0,.06)`.

**"Add a message action button"**
> Use `<Button className="sd-ai-agent-action-btn">` with `icon`, `label`, `showTooltip`, and `size="small"`. It will render at `26×26px` with a white background and subtle border, appearing only on message row hover via CSS opacity transition.

**"Add a new panel / modal dialog"**
> Use `border-radius: 8px`, `box-shadow: 0 8px 32px rgba(0,0,0,.20)`. Header: `border-bottom: 1px solid #dcdcde`, `padding: 16px 20px`. Body: `padding: 16px 20px`. Footer: `border-top: 1px solid #dcdcde`, `display: flex; justify-content: flex-end; gap: 8px`. Use `<Button variant="primary">` for confirm and `<Button variant="tertiary">` for cancel.

<!--
Design system for sd-ai-agent WordPress plugin.
Reflects the interface decisions made during the chat UI redesign (April 2026).
-->

---

## 10. Public Brand Assets

WordPress.org and promotional artwork extend the restrained WordPress admin
visual language with fixed brand colours: WordPress blue `#2271b1`, dark blue
`#135e96`, light blue `#b5d9f3`, charcoal `#1d2327`, and white.

- Use the full **SD AI Agent** wordmark with the blue `AI` sparkle treatment.
- Keep typography bold, system-native, and immediately legible at half size.
- Prefer flat colour, generous clear space, and restrained sparkle details;
  avoid gradients, neon effects, robot imagery, and unsupported exclusivity
  claims such as “the first”.
- Use **A native AI agent for WordPress.** as the public positioning line.
- Keep `.wordpress-org/assets/banner-source.svg` as the editable banner master
  and export both required `1544x500` and `772x250` PNG files from it.
