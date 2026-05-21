# Superdav AI Agent — 60-second promo reel

**Format:** 9:16 vertical reel, 1080×1920, 30 fps, 60 seconds total.
**Voice:** confident, dry, no marketing fluff. Each beat is a real prompt run live against the plugin — no mockups.
**Cuts:** quick, beat-driven. Each prompt clip is captured natively (real AI call) then speed-ramped to fit its slot.

---

## Storyboard

| # | Time     | On-screen                                                                                 | Voice-over (optional)                                       |
|---|----------|-------------------------------------------------------------------------------------------|-------------------------------------------------------------|
| 0 | 0:00–0:03| **Title card.** Big text: *"Build WordPress, just by talking to it."* Plugin mark, fade in.| _(silence — let the title land)_                            |
| 1 | 0:03–0:13| Mobile WP admin → chat. Prompt typed: *"Build a minimal photography portfolio theme…"* Watch it scaffold and activate. Front-end flashes. | "Design a theme."                                           |
| 2 | 0:13–0:22| Prompt: *"Write a 600-word SEO post with a featured image…"* Editor opens with the published post + AI-generated hero image. | "Write content that ranks."                                 |
| 3 | 0:22–0:31| Prompt: *"Generate a minimalist SVG logo and set it as the site logo."* SVG renders inline, then site header updates. | "Generate brand assets."                                    |
| 4 | 0:31–0:42| Prompt: *"Build a custom plugin called Auto Watermark…"* File tree streams in, plugin appears in the Plugins list, marked active. | "Build entire plugins."                                     |
| 5 | 0:42–0:52| Prompt: *"Audit my homepage SEO. Top three fixes."* Numbered checklist renders with concrete file/setting changes. | "Audit your SEO."                                           |
| 6 | 0:52–0:60| **Outro card.** *"Bring your own key. Any AI provider. Open source."* URL: wordpress.org/plugins/superdav-ai-agent | "Your site. Your key. Your model."                          |

---

## Why these prompts

Each prompt exercises a distinct ability class so the reel showcases breadth:

- **Beat 1** — `scaffold-block-theme` + `activate-theme` (ThemeBuilderAbilities, ActivateThemeAbility)
- **Beat 2** — `create-post` + `ai-image/generate-image` (ContentAbilities, AiImageAbilities)
- **Beat 3** — `generate-logo-svg` + `update-option` (GenerateLogoSvgAbility, OptionsAbilities)
- **Beat 4** — `generate-plugin` + `sandbox-activate-plugin` (PluginBuilderAbilities, SandboxActivatePluginAbility)
- **Beat 5** — `seo-audit` (SeoAbilities)

Five built-in ability classes touched in 50 seconds. The pitch is: *every prompt is a real tool call against your real site.*

---

## Production notes

### Recording
- `record.js` opens a Playwright Chromium at a mobile viewport (540×960, DPR 2 → 1080×1920 native).
- Logs in once, opens the agent admin page, sends one prompt per beat, waits for the agent loop to reach `complete`, saves `clips/<beat-id>.webm`.
- Real AI responses take 30–120 s depending on provider/model. The recorder waits up to 240 s per beat. Speed-ramping happens in `assemble.sh`.

### Assembly
- `assemble.sh` reads `prompts.json`, takes each recorded clip, trims it to the beat's `duration_seconds`, and concatenates with title cards generated via `ffmpeg`'s `drawtext` + `color` lavfi source.
- Output: `output/superdav-ai-agent-reel.mp4` (H.264, AAC silent track, ready to upload to Reels/TikTok/Shorts).

### Music
- Output ships silent so you can drop in licensed music in your editor of choice (CapCut, DaVinci Resolve, Premiere). To bake a placeholder beat in, pass `--music path/to/track.mp3` to `assemble.sh`.

### Provider
- The recorder calls **real** AI through whichever connector the target WP instance has configured. Cost-conscious tip: use the WebLLM connector (in-browser, free) for the first dry run, then re-record with a real provider once selectors and timing are dialled in.

---

## Variations

The script is a starting point. Edit `prompts.json` to:

- **Swap niche:** change the photography theme to "vegan bakery", "law firm", "indie game studio" — show the agent works for any vertical.
- **Add a beat:** drop a 60-second cap by adjusting other beats; add WooCommerce ("Add five products and set up Stripe") or Knowledge Base ("Index this PDF and answer questions about it").
- **Show provider switch:** add a 2-second beat where the model chip is changed from Claude to GPT to Ollama to drive home "any provider".
- **Show floating widget:** the agent works on _every_ admin page via the floating widget; one beat can record `goToAdminDashboard()` and show the FAB → panel → prompt flow instead of the full-page chat.
