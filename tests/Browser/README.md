# Browser tests

Manual, not run by `composer test`. They drive `resources/dist/ai-bridge-chat.js`
in a real Chromium, because that file is a hand-maintained bundle with no build
step and no other test coverage — a mistake in it ships straight to users.

Run from the repository root — the wrapper mounts the current directory into
the container, and the spec loads the component from `resources/dist/`:

```bash
~/.local/bin/pw test tests/Browser/streaming-scroll.spec.js
```

`harness.html` boots the component against a stubbed `fetch`, so no server or
database is needed. The spec then drives `handleEvent` directly with the same
stream events the bridge sends.

## What they cover

Partial-message streaming means roughly 35 `block_delta` events per answer where
there used to be 3, and every one re-renders the entire transcript. That makes
two things load-bearing that were previously too rare to matter:

- **Renders are coalesced** behind `requestAnimationFrame`, so a burst of deltas
  costs one render rather than one each.
- **Scroll position is preserved** for a reader who has scrolled up, and pinned
  to the bottom for one who has not. `innerHTML` re-creates the scroller on every
  render, so "leave it alone" is not an option: not writing `scrollTop` puts the
  reader at the top of the conversation, which is worse than the pinning it
  replaced.

Sending a message and opening a conversation deliberately override that and
force the bottom, since the new content is the whole point of those renders.

## Conformance: one corpus, two readers

`tests/Conformance/tool-call-scenarios.json` is replayed through **both**
implementations of the tool-call rules:

- `tests/Unit/ToolCallConformanceTest.php` → `ConversationRecorder` (what a
  reload shows)
- `tests/Browser/conformance.spec.js` → the chat component (what the reader
  sees live)

They are independent readers of the same protocol, and every parity bug in this
area came from asserting by hand that they agree — which they repeatedly did
not, in ways only a reviewer replaying the same input through both would notice.
The corpus makes that a test.

```bash
~/.local/bin/pw test tests/Browser/conformance.spec.js        # the live half
composer test -- --filter=Conformance                         # the recorded half
```

**When a divergence is found, add a scenario.** Both suites pick it up with no
further wiring. Only `tool_call` blocks are compared, and only the fields both
sides model — `tool_name`, `parameters`, `tool_call_id`, `parameters_raw` —
because those are what a consumer of either can rely on.
