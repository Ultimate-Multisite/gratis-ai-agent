# Performance Baselines

Bundle size baselines measured from the production build as of 2026-03-19.
All sizes are **gzipped** (what the browser actually downloads).

## JS Bundle Sizes

| Bundle | Raw | Gzipped | Budget |
|--------|-----|---------|--------|
| `admin-page.js` | 1,139 KB | **370.6 KB** | 400 KB |
| `floating-widget.js` | 1,124 KB | **367.5 KB** | 400 KB |
| `screen-meta.js` | 1,111 KB | **363.9 KB** | 400 KB |
| `settings-page.js` | 138 KB | **29.6 KB** | 50 KB |
| `changes-page.js` | 11 KB | **3.4 KB** | 10 KB |
| `abilities-explorer.js` | 8 KB | **2.9 KB** | 10 KB |

## CSS Bundle Sizes

| Bundle | Raw | Gzipped |
|--------|-----|---------|
| `style-admin-page.css` | 37 KB | 6.2 KB |
| `style-floating-widget.css` | 19 KB | 3.8 KB |
| `style-settings-page.css` | 10 KB | 2.2 KB |
| `style-changes-page.css` | 2 KB | 0.6 KB |
| `style-abilities-explorer.css` | 2 KB | 0.6 KB |
| `style-screen-meta.css` | < 1 KB | 0.2 KB |

## Why the Large Bundles

The three large bundles (`admin-page`, `floating-widget`, `screen-meta`) share
the same heavy dependencies because each is a separate webpack entry point that
bundles its own copy of:

- **CodeMirror 6** — syntax-highlighted code editor (~200 KB gzipped with all language packs)
- **Chart.js** — analytics charts (~40 KB gzipped)
- **highlight.js** — syntax highlighting for message rendering (~30 KB gzipped)
- **@wordpress/components** — WordPress UI component library (~60 KB gzipped)

## Budget Enforcement

Bundle size budgets are enforced in CI via [size-limit](https://github.com/ai-tools/size-limit).

```bash
# Check locally after building
pnpm run build
pnpm run size
```

The `bundle-size` CI job runs on every PR and fails if any budget is breached.
See `.github/workflows/code-quality.yml` and the `size-limit` config in `package.json`.

## Reducing Bundle Size

If a budget is breached, consider:

1. **Shared chunks** — configure webpack `splitChunks` to extract common deps into a shared chunk loaded once
2. **Dynamic imports** — lazy-load CodeMirror language packs only when the editor is opened
3. **Tree-shaking** — audit which highlight.js languages are registered; import only those used
4. **Chart.js tree-shaking** — import individual chart types instead of the full bundle
5. **WordPress externals** — `@wordpress/*` packages are already externalised via `wp-scripts`; verify no accidental bundling
