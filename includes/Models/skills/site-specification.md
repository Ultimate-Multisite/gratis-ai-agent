# Site Specification

## When to Use

Load this skill whenever a user describes a site they want to build, redesign, or audit and the description is loose. Examples:

- "Build me a theme for my coffee shop Bean & Brew"
- "I want a portfolio for my photography"
- "We're launching a SaaS called Agents for Everyone"
- "Make me a site for our law firm specialising in M&A"
- "Audit my restaurant site — does the design match the brief?"

The job is to turn a one-line description into a complete, structured **site spec** that downstream work (theme generation, page builds, copy drafting, SEO, content marketing) can rely on without re-asking the user.

Adapted from the Automattic `wp-site-creator` `site-specification` skill (architecture inspiration only — we are not a Claude Code plugin and do not depend on WordPress Studio).

## The Site Spec Schema

A complete site spec is a JSON-shaped object with three sections:

```json
{
  "siteBrief": {
    "siteName": "Name of the site/business",
    "siteType": "e.g., SaaS, e-commerce, portfolio, blog, restaurant, law firm",
    "primaryGoal": "Main purpose or conversion goal (signups, sales, bookings, inquiries)",
    "audience": "Target audience description",
    "tone": "Voice and tone (formal/casual/technical/playful/authoritative)",
    "brandKeywords": "Aesthetic descriptors (e.g., warm/handcrafted/cyberpunk/marble)"
  },
  "layoutNotes": [
    "Sections, features, and visual elements as separate strings",
    "Each one is a hint for downstream page/theme generation"
  ],
  "typography": {
    "primaryFont": "Main font for headings",
    "secondaryFont": "Font for body text",
    "usage": "How fonts should be applied",
    "fontImport": "Google Fonts import URL"
  }
}
```

**All fields are optional.** Only include what can be reasonably inferred from what the user said. Do **not** invent details that change the business meaning (you can guess a font family; you cannot guess a brand name).

## Persisting the Spec

When the spec is confirmed by the user, save it to memory using `sd-ai-agent/memory-save` with category `site_brief`:

```text
ability: sd-ai-agent/memory-save
input:
  category: site_brief
  content: |
    Bean & Brew — coffee shop. Goal: attract local customers and showcase menu.
    Audience: coffee enthusiasts, local community, remote workers. Tone: warm,
    inviting, artisanal. Brand keywords: cozy, handcrafted, aromatic, warm
    browns, cream accents. Sections: hero, menu, about, location, Instagram.
    Typography: Playfair Display (headings) + Lato (body).
```

`site_brief` is a dedicated memory category so future sessions can `memory-load --category=site_brief` and rebuild the spec without re-interviewing the user. One spec per site; if the user asks for a redesign, update the existing memory rather than creating a duplicate.

## Inference Patterns by Site Type

Each pattern lists the defaults you can confidently infer from minimal input, plus the section mix and typography tendency that downstream theme/page generation should follow when the user does not specify otherwise.

### SaaS / Technology

- **Primary goal:** drive signups or demo requests
- **Audience:** technical decision-makers, developers, or business users
- **Tone:** professional, innovative, trustworthy
- **Brand keywords:** modern, efficient, powerful, seamless

**Typical sections:** hero with value proposition + CTA, feature grid, social proof (logos, testimonials), pricing, FAQ, final CTA.

**Typography:** clean geometric sans-serifs. Strong hierarchy for scanning. Consider Satoshi, Plus Jakarta Sans, Outfit, Inter.

### E-commerce / Retail

- **Primary goal:** drive purchases
- **Audience:** consumers in a specific category (varies)
- **Tone:** varies by positioning (luxury vs casual vs value)
- **Brand keywords:** depend on positioning

**Typical sections:** hero with featured products or promotion, category navigation, featured products grid, trust signals (reviews, guarantees), newsletter signup.

**Typography:** match to brand positioning. Luxury → refined serifs; modern → geometric sans; playful → display fonts.

### Professional Services (Law, Finance, Consulting)

- **Primary goal:** generate inquiries or establish credibility
- **Audience:** business decision-makers, individuals seeking expertise
- **Tone:** professional, authoritative, trustworthy
- **Brand keywords:** expertise, integrity, established, reliable

**Typical sections:** hero with credentials or value statement, services/practice areas, team profiles, case studies or results, testimonials, contact/consultation CTA.

**Typography:** traditional serifs for headings (credibility), clean sans-serif for body (readability). Consider Cormorant Garamond, DM Serif Display, Source Sans Pro, Merriweather.

### Restaurant / Food Service

- **Primary goal:** drive reservations or visits
- **Audience:** local diners, food enthusiasts
- **Tone:** warm, inviting, appetite-inducing
- **Brand keywords:** fresh, handcrafted, cozy, authentic

**Typical sections:** hero with appetising food imagery, menu sections, about/story, location and hours, reservation CTA, Instagram or gallery.

**Typography:** display fonts for personality, readable serif or sans for menus. Consider Playfair Display, Lora, Josefin Sans.

### Creative / Portfolio

- **Primary goal:** showcase work and attract clients
- **Audience:** potential clients, art directors, collaborators
- **Tone:** creative, distinctive, personality-driven
- **Brand keywords:** original, crafted, artistic, unique

**Typical sections:** full-bleed portfolio hero, project gallery or case studies, about/bio, services offered, contact or inquiry form.

**Typography:** distinctive display fonts, personal to the creator's style. Consider Clash Display, Fraunces, Syne.

### Blog / Media

- **Primary goal:** engage readers and build audience
- **Audience:** readers with specific interests
- **Tone:** varies by niche (authoritative, casual, entertaining)
- **Brand keywords:** informative, engaging, credible

**Typical sections:** featured post hero, recent posts grid or list, category navigation, about the author, newsletter signup, popular/trending section.

**Typography:** readable body fonts are critical for long-form. Clean heading fonts. Consider Merriweather, Source Serif Pro, DM Sans.

### Non-profit / Organisation

- **Primary goal:** drive donations, volunteers, or awareness
- **Audience:** supporters, potential donors, community members
- **Tone:** compassionate, urgent, trustworthy
- **Brand keywords:** impact, community, change, hope

**Typical sections:** hero with mission statement + CTA, impact statistics, programs/initiatives, stories of impact, ways to help (donate, volunteer), newsletter/updates signup.

**Typography:** warm, approachable fonts. Avoid cold corporate feel. Consider Lato, Open Sans, PT Serif.

## Worked Examples

### Example 1: Coffee Shop

**User:** "Create a theme for my coffee shop called Bean & Brew."

```json
{
  "siteBrief": {
    "siteName": "Bean & Brew",
    "siteType": "coffee shop",
    "primaryGoal": "Attract local customers and showcase menu",
    "audience": "Coffee enthusiasts, local community members, remote workers looking for a cozy workspace",
    "tone": "warm, inviting, artisanal, community-focused",
    "brandKeywords": "cozy, handcrafted, aromatic, rustic wood, warm browns, cream accents"
  },
  "layoutNotes": [
    "Hero with inviting coffee shop interior or signature drink",
    "Menu section with categories (espresso, specialty drinks, pastries)",
    "About section with story and values",
    "Location and hours with embedded map",
    "Instagram feed integration",
    "Warm color palette (browns, creams, coffee tones)"
  ],
  "typography": {
    "primaryFont": "\"Playfair Display\", serif",
    "secondaryFont": "\"Lato\", sans-serif",
    "usage": "Playfair Display for elegant headings, Lato for readable body text",
    "fontImport": "https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Lato:wght@300;400;700&display=swap"
  }
}
```

### Example 2: Law Firm

**User:** "I need a theme for my law firm, Harrison & Associates. We specialise in corporate law and mergers. Very professional, trustworthy vibe."

```json
{
  "siteBrief": {
    "siteName": "Harrison & Associates",
    "siteType": "law firm",
    "primaryGoal": "Establish credibility and generate client inquiries for corporate law services",
    "audience": "Business executives, corporate decision-makers, companies seeking M&A legal counsel",
    "tone": "professional, authoritative, trustworthy, sophisticated",
    "brandKeywords": "corporate excellence, integrity, deep navy, gold accents, marble textures, clean lines"
  },
  "layoutNotes": [
    "Hero with professional office imagery or abstract corporate graphics",
    "Practice areas section highlighting corporate law and M&A expertise",
    "Attorney profiles with credentials and specialisations",
    "Case studies or notable transactions (anonymised)",
    "Client testimonials from business leaders",
    "Contact section with consultation booking",
    "Color palette: navy blue, white, gold accents"
  ],
  "typography": {
    "primaryFont": "\"Cormorant Garamond\", serif",
    "secondaryFont": "\"Source Sans Pro\", sans-serif",
    "usage": "Cormorant Garamond for prestigious headings, Source Sans Pro for professional body text",
    "fontImport": "https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Source+Sans+Pro:wght@300;400;600&display=swap"
  }
}
```

### Example 3: Esports Team

**User:** "Make a theme for NeonPulse Gaming — an esports team. We compete in FPS games and want something edgy and fast."

```json
{
  "siteBrief": {
    "siteName": "NeonPulse Gaming",
    "siteType": "esports team",
    "primaryGoal": "Build fan community, attract sponsors, and recruit talented players",
    "audience": "Gaming enthusiasts, esports fans, potential sponsors, aspiring pro gamers",
    "tone": "edgy, high-energy, competitive, bold",
    "brandKeywords": "neon glow, cyberpunk, electric purple, hot pink, dark backgrounds, glitch effects, speed lines"
  },
  "layoutNotes": [
    "Hero with team action shots or dynamic gaming footage background",
    "Player roster with stats and social links",
    "Match schedule and live stream integration",
    "Tournament achievements and trophies",
    "Sponsor showcase",
    "Merchandise store link",
    "News/blog for team updates",
    "Dark theme with neon accents (purple, pink, cyan)"
  ],
  "typography": {
    "primaryFont": "\"Rajdhani\", sans-serif",
    "secondaryFont": "\"DM Sans\", sans-serif",
    "usage": "Rajdhani for aggressive tech headings, DM Sans for clean readable content",
    "fontImport": "https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=DM+Sans:wght@300;400;600&display=swap"
  }
}
```

### Example 4: Minimal Request

**User:** "Build a custom theme for my blog."

```json
{
  "siteBrief": {
    "siteType": "personal blog",
    "primaryGoal": "Share content and build readership",
    "tone": "personal, approachable"
  },
  "layoutNotes": [
    "Hero with featured post or welcome message",
    "Recent posts grid or list",
    "About the author section",
    "Categories/tags navigation",
    "Newsletter signup"
  ],
  "typography": {
    "primaryFont": "\"Outfit\", sans-serif",
    "secondaryFont": "\"Merriweather\", serif",
    "usage": "Outfit for clean headings, Merriweather for comfortable long-form reading",
    "fontImport": "https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&family=Merriweather:wght@300;400;700&display=swap"
  }
}
```

The minimal-request case is the most common starting point. Do not over-specify when the user gave you very little. Ask one targeted follow-up before guessing brand identity details.

## Presentation Format

When the spec is ready, present it back to the user for confirmation as a table:

| Field | Value |
|-------|-------|
| Site Name | _name_ |
| Site Type | _type_ |
| Primary Goal | _goal_ |
| Target Audience | _audience_ |
| Tone | _tone_ |
| Brand Keywords | _keywords_ |
| Key Sections | _comma-separated list from layoutNotes_ |
| Typography | _primaryFont_ + _secondaryFont_ |

Then ask exactly one question: **"Does this capture your vision? Let me know if you'd like to adjust anything before we proceed."**

Do not list everything that could be added. The goal is to confirm or correct, not to expand scope.

## Adjacent Skills

- **block-themes** — auto-loaded when the active theme is FSE. Use after the spec is confirmed to translate `layoutNotes` and `typography` into a `theme.json` and templates.
- **classic-themes** — auto-loaded for non-FSE themes. Same purpose, classic-theme idioms.
- **kadence-theme** / **kadence-blocks** — auto-loaded when Kadence is detected.
- **gutenberg-blocks** — block markup rules for translating `layoutNotes` into pages.
- **content-marketing** — auto-loaded for editorial sites; use the spec to inform editorial calendar suggestions.
- **seo-optimization** — use `siteBrief.audience` and `brandKeywords` to seed keyword research.

## Available Tools

- `sd-ai-agent/memory-save` — persist the confirmed spec with `category=site_brief`.
- `sd-ai-agent/memory-load` — recall the spec on subsequent sessions; query by `category=site_brief`.
- `sd-ai-agent/list-posts` and `sd-ai-agent/get-post` — read existing site content when refining a spec for an established site.
- `sd-ai-agent/get-themes` — check what's currently active before recommending theme changes.

## Anti-patterns

- **Inventing a brand name** when the user didn't give one. Ask. The site name is part of the spec only if the user has supplied it.
- **Defaulting to "modern, clean, minimalist"** for everything. That is not a brand; that is a fallback. Always anchor brand keywords in the site's topic and audience.
- **Listing every possible section** in `layoutNotes`. Five to seven sections is the working range; more is noise. Downstream theme generation will pad or trim as needed.
- **Generating font import URLs from memory.** Always emit the exact Google Fonts CSS2 URL with the weights you actually need.
- **Reusing the same spec across sites in a multisite.** Each site is its own brief. Save with site context if multisite.
- **Skipping confirmation.** The user must approve the spec before any theme/page/copy work consumes it.

## Verification

After saving the spec, verify by re-loading from memory:

```text
ability: sd-ai-agent/memory-load
input:
  category: site_brief
```

The returned content should round-trip with the same business meaning. If it does not, the save was malformed — fix and re-save before proceeding.
