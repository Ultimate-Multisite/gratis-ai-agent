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
created. The widget calls public-chat routes with `credentials: 'omit'`, so it
does not send WordPress cookies or REST nonces from the docs domain. Session and
job tokens are opaque, origin- and embed-bound, kept in memory, and sent in
request bodies or the standard `Authorization: Bearer` header rather than query
strings.

## Optional public speech

Public speech is separately opt-in. Existing embeds remain text-only until an
administrator enables `public_chat_speech_enabled`. The operator can also set a
service voice (`auto` by default), a recording-duration ceiling, a synthesized
reply character ceiling, a localizable microphone disclosure, and whether the
explicit voice-conversation toggle is available. Server hard ceilings still
apply when configured values are higher. Disabling public speech takes effect
on the next request, including for already-created public chat sessions.

When enabled, the public configuration response exposes only fixed capture and
output MIME types, bounded numeric limits, labels, disclosure text, and feature
availability. It does not expose the site credential, service account state,
model routes, raw capability responses, or unrestricted synthesis options. The
widget may then call these additional routes:

- `POST /public-chat/speech/transcriptions` with exactly one temporary
  `recording.wav` part and the active session/embed context.
- `POST /public-chat/speech/synthesis` with a short-lived, one-use grant issued
  for the completed assistant reply. The route accepts no caller-provided text,
  model, voice, URL, file, or custom service options.

The widget shows the configured disclosure before the first microphone prompt
and never requests capture during script loading, configuration, session
creation, or preference restoration. Push-to-talk is the default. Optional
voice-conversation mode auto-submits the completed transcript and reads its
associated reply, then returns to idle; each new recording still requires a
fresh visitor gesture. Typed chat remains available if speech is unsupported,
denied, disabled, rate-limited, exhausted, or temporarily unavailable.

Browser capture requires `navigator.mediaDevices.getUserMedia`, `MediaRecorder`,
and `AudioContext` support for one advertised capture format. The configured
embed locale is tried first, then `navigator.language`, then managed automatic
detection. A normalized detected language becomes the ephemeral speech hint for
later turns in that session.

Public speech uses stricter request frequency, per-session concurrency,
site-wide concurrency, byte, duration, character, and daily/session usage
budgets than authenticated speech. Source and synthesized audio are temporary:
they are not added to WordPress uploads or the Media Library and are not stored
in chat rows, options, analytics, feedback, browser storage, or service-worker
caches. Only text that is subsequently submitted through normal public chat can
follow the existing optional conversation-review retention policy. Closing the
widget or leaving/hiding the page aborts active requests, stops media tracks and
playback, clears timers, and revokes object URLs.

Operators can attach a metrics sink to the
`sd_ai_agent_public_speech_metric` action. Its fixed envelope contains only the
operation, outcome/status category, and latency/byte/duration buckets—never
audio, transcripts, tokens, origins, addresses, user agents, or session IDs.
