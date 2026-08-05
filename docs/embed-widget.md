# Static docs chat embed

Build `embed-widget` with `pnpm run build`, copy `build/embed-widget.js` and
`build/embed-widget.css` to the static docs site, then load it without WordPress
globals:

```html
<script
  src="/assets/superdav/embed-widget.js"
  data-api-base="https://example.com/wp-json/sd-ai-agent/v1"
  data-embed-id="docs"
  data-theme="light"
  data-locale="en"
  data-collection="docs"
  defer
></script>
```

The browser contract is configuration only. It does not grant permissions. The
WordPress site must enable `public_chat_enabled`, configure at least one
`public_chat_collection_ids` entry, and list each docs origin in
`public_chat_allowed_origins`; requests from other origins fail before a job is
created. The widget calls only `/public-chat/config`, `/public-chat/session`,
`/public-chat/run`, and `/public-chat/job/{id}` with `credentials: 'omit'`, so
it does not send WordPress cookies or REST nonces from the docs domain.
