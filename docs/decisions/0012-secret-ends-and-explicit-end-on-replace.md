---
status: accepted
date: 2026-11-25
supersedes:
superseded_by:
revisit_when: a secret needs more than one end (a warning before it, a grace period after), or callers find stating the end on every replace too heavy and ask for a "keep the old end" option
---
# Secrets may carry an end; a replace must state it

## Context

A secret on a machine is often short-lived: a token for one deployment, a password for one job.
Without an end, a secret the app meant for an hour stays on the machine until somebody removes
it. Revision 3 of `machine_secrets` (ai-bridge's own decision on secret ends) lets the bridge end a
secret at a moment: `expire` removes the value and keeps the entry, listed with `expired_at`;
`delete` removes both.

The first draft of revision 3 made `ends_at` optional on every `secret_put`, and a replace
without it cleared the old end. Replacing is what a caller does when a key is rotated, and the
caller is often an AI acting for a person: one forgotten argument would turn a secret meant to
end into one that never does, and nothing would say so.

## Decision

- A secret may carry an end: `ends_at` (ISO 8601 UTC, whole seconds, `Z`; after now, at most 10
  years ahead) with `end_kind` (`expire` or `delete`), on `secret_put` and on the new frame
  `secret_set_end` (`ends_at: null` removes the end). A list answer carries each secret's end,
  `expired_at`, and `ended`, the ends the machine handled lately.
- **A replace states its end.** A `secret_put` with `replace: true` carries the `ends_at` key: a
  time with `end_kind`, or `null` for deliberately no end. Without the key the bridge refuses it
  with `end_required`. The end sent is the end the secret has afterwards; the old one is never
  kept implicitly. A first put keeps `ends_at` optional (no end).
- This package refuses the same before sending anything: `SecretRequest` answers `end_required`
  (400 on `POST /api/secrets`), and `AiBridge::secrets()->put(..., replace: true)` requires
  `endsAt:` or `noEnd: true` (`noEnd` travels as `ends_at: null`; both at once is `invalid_end`).
- An end never reaches a bridge that would drop it: a put with a time in `ends_at`, or any
  `secret_set_end`, to a bridge below revision 3 is answered `ends_unsupported` with nothing sent.
  A replace's `ends_at: null` may go to a revision 2 bridge, which keeps no ends anyway.
- An end sent from PHP is rounded up to the next whole second, never down, so an end is never
  earlier than asked and `+1 second` never becomes "now".

## Consequences

- Every caller that replaces a secret must say what its end is, even when it wants none. A caller
  that wants to keep the end it had sends it again.
- A caller that forgets cannot make a secret permanent: it gets `end_required` and nothing
  changes on the machine.
- `MIN_REVISION` stays 2: a revision 2 bridge still works for everything without an end.
- Additive wire fields; the protocol version stays 0.1 (0001).

## Options considered

- **A replace without `ends_at` clears the end** (the first draft): rejected, because a forgotten
  argument silently makes a short-lived secret permanent.
- **A replace without `ends_at` keeps the old end:** rejected, because a rotated key would
  silently inherit an end its caller may not know about, and a caller could not tell from its own
  request what end the secret now has.
- **A PHP sentinel value instead of `noEnd`** (for example a constant passed as `endsAt`):
  rejected, because `endsAt` is typed `?DateTimeInterface`; a separate boolean keeps the type and
  reads plainly at the call site.
