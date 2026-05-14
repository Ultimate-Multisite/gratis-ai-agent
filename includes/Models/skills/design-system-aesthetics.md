# Design System Aesthetics

## When to Use

Load this skill at the **start of Phase 2** of the theme-builder flow — the step where the agent proposes 3 distinct design directions as HTML preview files.

Do **not** load this skill during Phase 1 (site specification interview) or Phase 4 (block theme build). Those phases have their own skill references. This skill's sole purpose is to inform the Phase 2 design-direction step.

```text
ability: sd-ai-agent/skill-load
input:
  skill_name: design-system-aesthetics
```

Call `site-specification` and `block-themes` first (Phase 1); call this skill as the first action in Phase 2.

## The Topic-Grounded Framework

Generic style categories — "modern dark," "minimalist light," "colorful playful" — are not design directions. They are fallbacks used when the designer has not thought hard enough about the subject matter. Every site's topic can authentically inhabit multiple distinct aesthetic worlds, and those worlds are grounded in the culture, materiality, and associations of the topic itself.

**The principle:** Instead of choosing a style archetype and applying it to the topic, start from the topic and ask: *What physical, cultural, or conceptual worlds could this topic authentically live in?*

**Example — Craft Brewery:**
- Generic approach: "dark theme / light theme / colorful theme"
- Topic-grounded approach: "taproom warmth / label-art maximalism / industrial grain and steel"

Each topic-grounded direction implies its own typography, color language, density, and mood — not because they were chosen from a palette list, but because they arise from a genuine aesthetic world the topic belongs to.

**Three-direction rule:** Always propose exactly three directions. Each direction must be:

1. Genuinely distinct from the others — not a palette swap.
2. Plausibly authentic to the topic — grounded in the site brief's keywords and audience.
3. Varied in density, warmth, and register — not all premium, not all casual, not all minimal.

**What to do with the site brief:** Read `siteBrief.siteType`, `brandKeywords`, and `tone` from the confirmed site specification before selecting worlds. The taxonomy below is a starting-point map; override freely when the brief has specific cultural, geographic, or stylistic anchors.

## Visual World Taxonomy by Site Type

For each site type, three named aesthetic worlds are listed. Each entry provides a mood descriptor, color direction guidance (no hex codes — the agent provides final values in theme.json), typography category, density preference, and short example applications.

Use these as starting points. When the user's site brief contains specific cultural, geographic, or stylistic anchors that fall outside the entries below, invent a topic-grounded world name that fits — the taxonomy is a map, not an exhaustive list. The naming convention (noun + descriptor or compound noun) should communicate the cultural world evoked, not the visual style applied.

If the site type is ambiguous or spans multiple categories (a SaaS product for restaurants, a consulting firm with a creative brand), choose the taxonomy entry closest to the user's stated tone and audience, then adjust the world names and mood descriptors to fit the hybrid context.

### SaaS / Technology

**World 1: Precision-Dark**

- *Mood:* Authoritative, focused, high-performance. The visual language of developer tools, dashboards, and mission-critical software.
- *Color direction:* Near-black backgrounds, cool-blue or electric-cyan accents, white type, graphite card surfaces.
- *Typography:* Monospace or technical sans-serif for code and data callouts; clean geometric sans for UI prose.
- *Density:* High — tight grids, compact feature cards, information-dense layouts that signal seriousness.
- *Example applications:* CI/CD pipelines, security monitoring, API infrastructure, developer toolchains.

**World 2: Optimist-Light**

- *Mood:* Approachable, fast-moving, growth-oriented. The visual vocabulary of well-funded B2B startups selling confidence and momentum.
- *Color direction:* Bright white base, one vivid accent (lime-green, electric-indigo, or coral), generous negative space.
- *Typography:* Bold geometric sans-serif headlines with variable-weight body for energy; strong size contrast.
- *Density:* Medium — scannable feature grids, large stat callouts, breathing room between bands.
- *Example applications:* HR platforms, project management tools, sales enablement SaaS, onboarding software.

**World 3: Enterprise-Gravitas**

- *Mood:* Trustworthy, established, risk-averse. The language of software sold to procurement committees.
- *Color direction:* Navy or deep slate primary, restrained gold or forest-green accent, white and light-grey surfaces.
- *Typography:* Refined serif or humanist sans for credibility; clear hierarchy for scanning complex feature sets.
- *Density:* Medium-low — clear section breaks, prominent social proof (logos, certifications), minimal decoration.
- *Example applications:* ERP systems, compliance platforms, healthcare IT, financial services software.

### E-Commerce / Retail

**World 1: Editorial Luxury**

- *Mood:* Exclusive, aspirational, unhurried. The world of art-directed lifestyle brands — even when no photography is available.
- *Color direction:* Ivory or warm-white base, single deep accent (midnight, forest, or burgundy), minimal color interruption.
- *Typography:* Refined display serif for product names and headlines; clean light-weight sans for body and pricing.
- *Density:* Low — generous padding, products given room to breathe, deliberate use of white space.
- *Example applications:* Premium skincare, fine jewellery, boutique fashion labels, artisan home goods.

**World 2: Marketplace Energy**

- *Mood:* Energetic, value-forward, democratic. The visual register of curated discovery — browse-first, conversion-optimized.
- *Color direction:* Clean white base, bold accent for CTAs and badges, optional category color-coding for navigation.
- *Typography:* High-legibility sans-serif throughout; tight line-heights for dense product cards.
- *Density:* High — compact product grids, persistent cart signals, promotional banners, sale badges.
- *Example applications:* Multi-category stores, marketplaces, subscription boxes, food and grocery delivery.

**World 3: Craft Story**

- *Mood:* Warm, handmade, narrative-first. The aesthetic of independent makers who lead with the story of how and why a product was created.
- *Color direction:* Warm off-whites, earthy terracottas or sage greens, muted accent palette that references raw materials.
- *Typography:* Artisanal slab or old-style serif for headlines; approachable rounded sans for body copy.
- *Density:* Medium-low — story sections interspersed with products, materials and process callouts, founder voice prominent.
- *Example applications:* Artisan food and drink, handmade goods, independent clothing brands, small-batch ceramics.

### Professional Services (Law, Finance, Consulting)

**World 1: Corporate Classic**

- *Mood:* Established, authoritative, risk-minimal. The visual register that signals "we have been trusted for decades."
- *Color direction:* Deep navy or charcoal primary, gold or warm-white accent, marble-texture allusion in surface treatments.
- *Typography:* Classic serif for headlines (credibility by association); clean humanist sans for body text.
- *Density:* Medium — ample white space, deliberate section hierarchy, no visual noise or decoration.
- *Example applications:* Corporate law firms, private equity, M&A advisory, white-shoe accounting.

**World 2: Modern Counsel**

- *Mood:* Precise, forward-looking, human. The visual register of firms that signal expertise without intimidation.
- *Color direction:* Slate or cool grey primary, restrained teal or blue-violet accent, off-white surfaces.
- *Typography:* Contemporary humanist sans throughout; strong typographic hierarchy substitutes for decorative elements.
- *Density:* Medium — clean information architecture, team-forward layouts, case-study emphasis.
- *Example applications:* Employment law, family law, mediation practices, mid-market management consulting.

**World 3: Boutique Authority**

- *Mood:* Bespoke, personal, high-conviction. The visual language of the specialist who does one thing exceptionally.
- *Color direction:* Warm cream or linen base, deep plum or forest-green accent, paper-and-leather tactility in surface treatments.
- *Typography:* Display serif for firm name and section titles; refined body type with generous leading.
- *Density:* Low — full-page biography sections, long-form content blocks, prominent single contact CTA.
- *Example applications:* Tax specialists, IP attorneys, boutique strategy consultancies, bespoke financial advisors.

### Restaurant / Food Service

**World 1: Taproom Warmth**

- *Mood:* Welcoming, convivial, deeply local. The atmosphere of a place where regulars know the staff by name.
- *Color direction:* Warm amber, aged-wood browns, cream highlights, forest-green accent. Colors that feel worn-in, not designed.
- *Typography:* Weathered slab serifs or display scripts for names and signage; approachable sans for menus and hours.
- *Density:* Medium — menu sections with personality, about/story section prominent, location and hours easy to find.
- *Example applications:* Neighborhood pubs, craft breweries, gastropubs, long-running local diners.

**World 2: Chef's Table**

- *Mood:* Refined, minimal, ingredient-focused. Every visual choice signals that the food is the point — nothing competes with it.
- *Color direction:* Near-white or pale linen backgrounds, muted earth tones (stone, umber, sage), a single editorial accent used once.
- *Typography:* Elegant editorial serif for the restaurant name and section titles; refined sans for menu items.
- *Density:* Low — menu items given space, story-first about sections, no visual clutter competing with the narrative.
- *Example applications:* Fine dining, tasting-menu restaurants, farm-to-table concepts, private dining clubs.

**World 3: Street and Flavor**

- *Mood:* Energetic, bold, unapologetically direct. The visual register of places that lead with flavor intensity and cultural pride.
- *Color direction:* Saturated primaries or vivid tropical palette; bold contrasts; graphic label-art aesthetic.
- *Typography:* Expressive display fonts — block lettering, condensed grotesques, or hand-drawn scripts; high contrast.
- *Density:* High — bold typographic sections, bright CTAs, personality-forward layouts with minimal white space.
- *Example applications:* Taco trucks, ramen shops, Caribbean and West African restaurants, late-night takeout.

### Creative / Portfolio

**World 1: Studio Minimal**

- *Mood:* Quiet confidence. The work speaks; the container disappears.
- *Color direction:* True white or near-black background, single muted accent, typography as the only decorative element.
- *Typography:* Distinctive display sans or grotesque for the creator's name; tight tracking, confident weight, sparingly applied.
- *Density:* Very low — large project image blocks, minimal label text, generous negative space, nothing competes.
- *Example applications:* Photographers, product designers, illustrators with strong and cohesive visual portfolios.

**World 2: Process-Forward**

- *Mood:* Intellectual, curious, collaborative. The visual register of a creator who wants clients to understand how they think.
- *Color direction:* Warm off-white base, notebook-inspired accents (ink blue, pencil grey), occasional color for emphasis only.
- *Typography:* Humanist serif or editorial sans; comfortable leading for long-form case study text.
- *Density:* Medium — project deep-dives, process steps, behind-the-scenes material alongside polished final work.
- *Example applications:* UX designers, brand strategists, architects, design researchers.

**World 3: Maximalist Signal**

- *Mood:* Bold, memorable, category-defying. The visual register of creatives who want to be impossible to forget.
- *Color direction:* Unexpected palette — hot pink, electric green, or deep violet; deliberate color tension and unexpected pairings.
- *Typography:* Expressive or experimental display fonts; collage-inspired hierarchy; scale contrasts that break the grid.
- *Density:* Variable — some sections overwhelming, others starkly empty; controlled unpredictability.
- *Example applications:* Graphic designers, motion designers, musicians, photographers with a defined stylistic niche.

### Blog / Media

**World 1: Editorial Broadsheet**

- *Mood:* Credible, authoritative, long-form. The visual register of journalism and serious opinion writing.
- *Color direction:* Black or near-black type on white; classic red or navy accent for section markers and link treatments.
- *Typography:* Refined serif for article body text; condensed sans for headlines; editorial paragraph spacing.
- *Density:* Medium-high — dense content listing, section navigation, featured story above the fold.
- *Example applications:* Independent news sites, opinion columns, policy publications, investigative journalism.

**World 2: Curator's Feed**

- *Mood:* Warm, personal, community-first. The visual register of a knowledgeable individual who curates for a trusting audience.
- *Color direction:* Warm white or cream base, conversational accent (burnt orange, warm teal, dusty rose), hand-crafted feel.
- *Typography:* Friendly humanist sans for navigation and UI; comfortable serif for long-form reading.
- *Density:* Medium — post cards with excerpt previews, author voice prominent, newsletter signup integrated.
- *Example applications:* Personal blogs, niche newsletters, hobbyist publications, book review sites.

**World 3: Native Digital**

- *Mood:* Fast, scannable, platform-native. The visual register of content built for rapid consumption and social sharing.
- *Color direction:* Clean white base, vivid accent for tags and categories, bold thumbnail frame treatments.
- *Typography:* High-impact condensed headline fonts; accessible body sans; short paragraphs optimized for scanning.
- *Density:* High — trending and latest sections, category navigation prominent, social share signals visible.
- *Example applications:* Tech news, entertainment blogs, sports commentary, pop culture coverage.

### Non-Profit / Organisation

**World 1: Mission Urgency**

- *Mood:* Immediate, emotionally direct, action-oriented. The visual register of organisations where every visit is a potential conversion to support.
- *Color direction:* Warm primary (amber, red-orange, or deep teal), white contrast, minimal secondary palette to focus attention.
- *Typography:* Strong, accessible sans-serif throughout; impact-weight headlines for callout statistics.
- *Density:* Medium — impact statistics prominent, clear donate and volunteer CTAs above the fold, beneficiary stories.
- *Example applications:* Crisis relief organisations, advocacy campaigns, humanitarian NGOs, medical charities.

**World 2: Community Trust**

- *Mood:* Warm, grassroots, accountable. The visual language of an organisation embedded in a specific community.
- *Color direction:* Approachable mid-tones (warm blues, leafy greens, terracotta); references local colour associations when appropriate.
- *Typography:* Approachable humanist sans; conversational paragraph lengths; no corporate coldness.
- *Density:* Medium — program sections, community stories, volunteer and donor pathways weighted equally.
- *Example applications:* Local food banks, neighbourhood associations, community health centres, arts cooperatives.

**World 3: Institutional Authority**

- *Mood:* Established, rigorous, accountable at scale. The visual register of large organisations that must signal governance and transparency.
- *Color direction:* Navy or forest-green primary with white; measured gold or slate accent; data-report aesthetic throughout.
- *Typography:* Readable body serif or humanist sans; strong section hierarchy for navigating long annual reports and programs.
- *Density:* Medium-low — report downloads prominent, governance and team sections, annual impact data clearly surfaced.
- *Example applications:* Foundations, international NGOs, hospital systems, universities, government agencies.

## Worked Examples

Three fully-rendered examples, each with three distinct topic-grounded directions.

### Craft Brewery

**Site brief:** Regional craft brewery with 8 house beers, a taproom, and online merchandise. Brand keywords: authentic, handcrafted, local heritage, bold flavors.

**Direction 1: Taproom Warmth**

- *Atmosphere:* The brewery at closing time — amber light, sticky wooden bar tops, regulars swapping stories. Lived-in and specific to this place.
- *Color direction:* Amber and warm ochre primary, deep espresso-brown secondary, cream type, forest-green CTA accent.
- *Typography:* Weathered slab serif for beer names and section headings; readable sans for descriptions and hours.
- *Density:* Medium — beer lineup as hero cards, taproom hours prominent, Our Story section woven between the beer grid.

**Direction 2: Label-Art Maximalism**

- *Atmosphere:* A brewery that treats label illustration as fine art. Every section feels like a hand-printed collectible worth framing.
- *Color direction:* Deep navy base, cream and aged-gold type, bold per-beer accent colours for individual product sections.
- *Typography:* Display script or ornate block letters for beer names and feature headlines; clean legible sans for body copy.
- *Density:* High — rich visual bands per beer, illustrated CSS-gradient dividers, full-bleed colour sections between products.

**Direction 3: Industrial Grain and Steel**

- *Atmosphere:* The working brewery floor — fermenters, corrugated steel, raw concrete, the honest machinery of making.
- *Color direction:* Charcoal and raw-steel greys, white type, single bold accent (electric copper or safety orange).
- *Typography:* Heavy grotesque or condensed industrial sans for headlines; clean monospace hints for technical beer specifications.
- *Density:* Medium-high — CSS gradient textures standing in for process photography, fermentation stats, stark geometric section dividers.

### SaaS Startup

**Site brief:** B2B productivity platform targeting operations teams at mid-market companies. Brand keywords: clarity, speed, no-nonsense, data-forward.

**Direction 1: Command-Line Dark**

- *Atmosphere:* The terminal at 2am — focused, powerful, nothing superfluous. Speaks to people who ship things.
- *Color direction:* Near-black background, electric green or cyan accent, white body type, deep graphite surface cards.
- *Typography:* Monospaced font for code callouts and stat labels; clean geometric sans for all other copy.
- *Density:* High — feature flags, integration logos, technical specification tables, compact three-tier pricing.

**Direction 2: Growth-Stage Light**

- *Atmosphere:* Fast, ambitious, approachable. The visual register of a product that wants to feel inevitable.
- *Color direction:* Bright white base, vivid electric-indigo or lime-green accent, subtle gradient hero from white to pale tint.
- *Typography:* Bold variable-weight geometric sans for display; lighter weight body; strong headline-to-body size contrast.
- *Density:* Medium — feature callout cards in a 3-column grid, one large stat band, customer logo strip, clean pricing table.

**Direction 3: Workflow Clarity**

- *Atmosphere:* Enterprise-leaning but not stuffy. A product that looks safe to the VP who approves the budget.
- *Color direction:* Cool light-grey background, deep navy type, restrained teal or cobalt accent, white surface cards.
- *Typography:* Humanist sans throughout; comfortable reading weight for feature descriptions; clear section hierarchy.
- *Density:* Medium-low — ROI-focused messaging, customer case study cards, compliance and security badges surface-level visible.

### Family Law Firm

**Site brief:** Boutique family law practice specialising in divorce, custody, and mediation. Client values: feeling heard, discretion, competence. Brand keywords: calm, compassionate, clear.

**Direction 1: Calm Authority**

- *Atmosphere:* A well-appointed waiting room where you feel immediately less anxious. Warm professionalism, not cold intimidation.
- *Color direction:* Warm cream or pale linen base, deep slate-blue primary, gold accent used sparingly for emphasis only.
- *Typography:* Transitional serif for firm name and section titles; generous leading for a calm, unhurried reading experience.
- *Density:* Low — deliberate pace, attorney bios prominent, services explained in plain language, single clear contact CTA.

**Direction 2: Human-First Modern**

- *Atmosphere:* Approachable, contemporary, empathetic. A firm that wants clients to know they understand what they are going through.
- *Color direction:* Soft warm white base, terracotta or dusty-rose accent (warmth without formality), charcoal body type.
- *Typography:* Friendly humanist sans with generous paragraph spacing; approachable headline weights, never aggressive.
- *Density:* Medium — FAQ sections prominent, what-to-expect process steps, testimonials from past clients, easy contact form.

**Direction 3: Discreet Sophistication**

- *Atmosphere:* High-stakes expertise with absolute discretion. For clients who need the best and do not want to be noticed seeking it.
- *Color direction:* Charcoal or deep forest-green primary, pale silver or champagne accent, restrained white space as primary design element.
- *Typography:* Refined display serif for headlines; clean sans-serif body; minimal decorative elements.
- *Density:* Very low — one clear CTA above the fold, credentials and accreditations prominent, minimal-field contact form.

## Anti-Patterns

These patterns produce designs that look templated, derivative, or tonally wrong. Avoid them regardless of site type.

**Generic style labels masquerading as directions.** "Option 1: Modern Minimal, Option 2: Bold and Colorful, Option 3: Dark Luxury" are not design directions — they are style adjective bundles that could apply to any topic. If the three directions contain no vocabulary specific to the client's topic, start over.

**Palette swaps as direction changes.** Three directions that share the same layout, typography, and density but swap the primary color are one direction, not three. Visual world differences go deeper than hue: they alter density, type category, section order, and mood register simultaneously.

**Stock-image aesthetic as a direction.** Directions that implicitly assume a particular style of commercial photography are fragile — when images are absent (as they almost always are for new sites), the direction collapses to generic padding. Ground every direction in CSS-achievable visual language: gradients, typography, color blocks, and texture patterns.

**Premium by default.** Not every direction needs to feel elevated or luxurious. Many strong brands are proudly accessible, energetic, functional, or bold. Forcing a premium register on a neighborhood taco truck or a youth sports association creates cognitive dissonance.

**Derivative brand references.** Directions that read as "Airbnb but for X" or "Apple but with a different accent color" are not topic-grounded; they are trend-following. Reference the topic's own cultural world, not adjacent design-world brands.

**Identical typography across directions.** Typography is a primary carrier of mood. If all three directions use the same font category (all geometric sans, all transitional serif), the directions feel like one identity with a color filter applied. At minimum, two of the three directions should occupy distinct type-category registers.

**Mismatched register and audience.** A family law firm's "bold maximalist" direction is valid only if the brief explicitly supports it. Check `tone` and `audience` from the site specification before proposing directions that deviate sharply from the inferred register.

## Output Contract

When Phase 2 HTML preview files are written, each file must include the elements below. This contract governs the design-preview HTML files only — it is not a theme.json or block markup contract.

**Required elements per preview file:**

1. **Direction title** — a short, distinctive name for the aesthetic world (not a generic style label).
2. **Atmosphere descriptor** — one sentence describing the mood and physical or cultural world evoked.
3. **Palette swatch row** — a horizontal strip of 4–6 solid color blocks built from inline CSS (no images). Each swatch is labelled: Primary, Secondary, Accent, Surface, Text, or similar.
4. **Typography specimen** — a display-size heading, a subheading, and one short body paragraph rendered in the direction's chosen fonts (loaded via Google Fonts or system-font stacks). The text must come from the confirmed site brief — no placeholder strings.
5. **Section mock** — at least one representative page section (hero band, feature row, or CTA block) built entirely from inline CSS and typography. No external image URLs, no CSS `url()` references to remote assets.

**Prohibited in preview files:**

- No external image URLs — Unsplash, Pexels, Pixabay, Placeholder.com, or any other image service.
- No JavaScript that fetches external resources.
- No `<iframe>` embeds.
- No placeholder text strings ("Lorem ipsum", "[Your tagline here]") — use real content drawn from the site brief.

**What "topic-grounded" means in the output:**

The direction title, atmosphere descriptor, and section mock must contain vocabulary specific to the site's topic. A preview file for a craft brewery should feel unmistakably like one of three coherent brewery identities — not a generic dark-theme, light-theme, or colorful-theme variant with a brewery name dropped in as an afterthought.

## Adjacent Skills

- **site-specification** — load before this skill (Phase 1); provides the site brief that grounds direction proposals in the topic.
- **block-themes** — load alongside this skill (Phase 1 / Phase 2); contains theme.json patterns, animation classes, and FSE markup guidance for Phase 4 build.
- **kadence-theme** / **kadence-blocks** — load if Kadence is the active theme framework; design directions remain the same, execution paths in Phase 4 differ.
