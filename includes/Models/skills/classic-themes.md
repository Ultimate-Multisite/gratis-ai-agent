# Classic Themes

## When to Use

This skill is auto-loaded when the active WordPress theme is a **classic theme** (no Full Site Editing — Astra, GeneratePress, OceanWP, Divi, Avada, Kadence, Storefront, etc., when used without their FSE variants). Use it whenever the user asks about templates, the Customizer, widget areas, menus, or theme functions on a classic theme.

If the active theme is a block theme (`templates/` directory, `theme.json`), use the `wp-block-themes` skill instead.

## How to confirm the active theme is a classic theme

Programmatic check: `! wp_is_block_theme()`.

Manual check via wp-cli:

```bash
wp option get template       # Active theme
wp option get stylesheet     # Active child theme
```

A classic theme has **no `templates/` directory of `.html` files** and typically no `theme.json` (some hybrid themes ship a partial `theme.json` while still using PHP templates — see "Hybrid behaviour" below).

## Key Concepts

### Template Hierarchy (PHP)

Classic themes use the standard WordPress hierarchy with PHP files:

- `index.php` — Default template (always required)
- `single.php`, `single-{post_type}.php` — Single posts
- `page.php`, `page-{slug}.php` — Pages
- `archive.php`, `category.php`, `tag.php`, `taxonomy.php` — Archives
- `search.php` — Search results
- `404.php` — Not found
- `front-page.php`, `home.php` — Static front page / posts page
- `header.php`, `footer.php`, `sidebar.php` — Reusable parts (loaded via `get_header()`, `get_footer()`, `get_sidebar()`)
- `functions.php` — Theme setup, hooks, custom functions

The full hierarchy: <https://developer.wordpress.org/themes/basics/template-hierarchy/>

### Customizer

The Customizer (`/wp-admin/customize.php`) is the primary user-facing settings interface for classic themes. Settings are typically registered via `customize_register` and rendered in the live preview.

### Widgets and Sidebars

Classic themes register widget areas (sidebars) via `register_sidebar()` in `functions.php`. Widgets are managed at `/wp-admin/widgets.php` — most classic themes provide a sidebar, footer columns, and sometimes a header widget area.

### Menus

Menu locations are registered with `register_nav_menus()`. Users assign menus to locations at **Appearance → Menus**. Output via `wp_nav_menu()`.

### Theme Support

Features are opted into via `add_theme_support()` in `functions.php` — examples:

```php
add_theme_support( 'post-thumbnails' );
add_theme_support( 'title-tag' );
add_theme_support( 'custom-logo' );
add_theme_support( 'html5', [ 'search-form', 'comment-form', 'gallery' ] );
add_theme_support( 'wp-block-styles' );        // Enable core block styles on the front-end
add_theme_support( 'editor-styles' );          // Match editor and front-end styling
add_theme_support( 'responsive-embeds' );
```

### Editor Styling (block editor in classic themes)

Classic themes still use the block editor for post/page content. To make the editor preview match the front-end:

```php
add_theme_support( 'editor-styles' );
add_editor_style( 'editor-style.css' );
```

Color palettes, font sizes, and spacing presets can also be supplied via theme support args or — in some classic themes — a partial `theme.json`.

## Hybrid behaviour

Some classic themes ship a `theme.json` to feed the block editor with palettes, sizes, and layout values. They are still classic themes (PHP templates, Customizer-driven) but benefit from the editor configuration. Detection: `! wp_is_block_theme() && file_exists( get_stylesheet_directory() . '/theme.json' )`.

When working on these:
- Theme structure is classic (PHP templates, Customizer, widgets).
- Block editor presets and global styles come from `theme.json`.
- Modify Customizer settings for site-wide layout, `theme.json` for editor presets.

## Available Tools

- `sd-ai-agent/site-info` — Surface the active theme, version, and parent/child relationship
- `sd-ai-agent/list-themes` — List installed themes
- `sd-ai-agent/get-theme-mods` — Read Customizer values
- `sd-ai-agent/list-menus`, `sd-ai-agent/list-menu-locations` — Inspect menu setup
- `sd-ai-agent/list-block-patterns` — Block patterns still apply inside post/page content

## Typical Workflows

### Customise theme appearance

1. Identify the lever: Customizer setting, widget area, or theme file?
2. Prefer the Customizer (`get-theme-mods` / `update-theme-mods`) over editing PHP for user-facing tweaks.
3. For changes that must persist across theme switches, build them into a child theme.

### Override parent theme files (child theme)

Copy the parent's PHP file into the child theme directory. WordPress will use the child's version.

For non-`functions.php` files, override is straightforward. For `functions.php`, **never copy the parent's `functions.php`** — child theme `functions.php` runs *in addition to* the parent's, so duplicating breaks things. Use the child's `functions.php` to add new hooks/filters only.

### Add a sidebar / widget area

In the child theme's `functions.php`:

```php
add_action( 'widgets_init', function() {
    register_sidebar( [
        'name'          => __( 'Custom Sidebar', 'theme-textdomain' ),
        'id'            => 'custom-sidebar',
        'before_widget' => '<aside class="widget %2$s">',
        'after_widget'  => '</aside>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    ] );
} );
```

Then output it in a template via `dynamic_sidebar( 'custom-sidebar' )`.

### Build a one-off page layout

For a specific page that needs a custom layout, two options:

1. **Page builder approach** — Use Gutenberg blocks (see `gutenberg-blocks` skill). The classic theme provides the surrounding header/footer; the post content area renders the blocks.
2. **Custom page template** — Create `page-{slug}.php` (or a generic `page-landing.php` declared via `Template Name:` header) in the child theme. WordPress will use it for the matching page.

## Verification

1. After editing a PHP template, reload the relevant front-end URL.
2. After Customizer changes, the live preview shows the result before publishing.
3. After `functions.php` changes, watch for fatal errors — work in a staging environment or use a file-based debugger.
4. For child-theme overrides, confirm `wp option get stylesheet` returns the child theme directory.
