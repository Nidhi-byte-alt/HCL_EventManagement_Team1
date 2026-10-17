# Security

This document describes the threat model, the hardening decisions, and
the anti-enumeration policy of **JWT Token Refresh**. It is part of the
checklist for the Drupal Security Team review.

## Reporting a vulnerability

Please do **not** open a public issue for security reports. Follow the
[Drupal security process](https://www.drupal.org/security-team/report-issue)
once the project is covered by the Security Advisory policy. Until then,
contact the maintainer privately.

## Token storage

- **Refresh tokens** are generated with a CSPRNG (`random_bytes`) and
  stored as a **SHA256 hash only** — the raw token is returned to the
  client exactly once, at creation, and never persisted in clear.
- An unsalted SHA256 is safe **only** because the input entropy is
  high. The module therefore enforces a **hard floor of 32 bytes**
  (`refresh_token_bytes`), clamped in code even if configuration is
  tampered with, and validated in the settings form.
- **jti** values (server-side revocation) are likewise CSPRNG-generated
  and stored as SHA256, with a **16-byte minimum**.

## Refresh token rotation & reuse detection (OWASP)

- Every refresh issues a **new** token and revokes the old one. The
  revocation is an **atomic conditional `UPDATE` (`revoked` 0 → 1)**
  which doubles as the concurrency lock: of two concurrent rotations of
  the same token, only one can succeed. No `SELECT … FOR UPDATE`, no
  race window between a check and a write.
- Tokens descending from one login share a **family id**. Replaying an
  already-rotated token is treated as a theft indicator: the **whole
  family is revoked** (including the attacker's — or the victim's —
  currently active token). The HTTP response is identical to any other
  refresh failure, so nothing is revealed to the attacker.
- Rotation re-checks `isActive()`: a blocked account cannot refresh,
  even with an otherwise valid token.

## Account-lifecycle revocation

A credential or account-state change invalidates sessions immediately:

- `hook_user_update`: a password change or an active → blocked
  transition revokes **all** of the user's refresh tokens (and jti
  sessions when revocation is enabled).
- `hook_user_cancel` / `hook_user_delete`: purge the user's rows in
  both tables.

## Endpoint hardening

- **Flood control** on `/auth/token` (per-IP **and** per name/IP pair)
  and `/auth/token/refresh` (per-IP). Exceeding a limit returns `429
  {"error":"too_many_attempts"}`. Flood control is **implemented**, not
  merely recommended.
- **Constant-time login**: on an unknown username the controller still
  verifies the submitted password against a **constant** dummy bcrypt
  hash, so "unknown user" and "wrong password" are indistinguishable by
  latency. The hash is a fixed constant (not generated per request) on
  purpose: generating one would add an extra hash for unknown users and
  *reintroduce* a timing signal. Its cost is bcrypt `10`, Drupal's
  default; a site configured with a non-default cost keeps a strong but
  slightly less exact timing profile. The refresh lookup is already
  timing-neutral (indexed SHA256).
- **`Cache-Control: no-store`** (+ `Pragma: no-cache`) on every token
  response (RFC 6749 §5.1).
- **Revoke is Bearer-only** (`_auth: ['jwt_auth']`) so a Drupal session
  cookie cannot trigger it (no logout-CSRF), idempotent `204`, and
  scoped to the caller's own tokens (no cross-user oracle).

## Anti-enumeration policy

The module follows OWASP: one machine-readable code per failure class,
never a code that distinguishes internal state.

- **Login** — a single `invalid_credentials` for *every* failure
  (unknown user, wrong password, blocked, inactive). Combined with the
  constant-time check above, accounts cannot be enumerated.
- **Refresh** — a single `invalid_token` for absent, malformed,
  unknown, expired, or already-rotated tokens. The reuse-detection
  family revocation happens silently; the response is unchanged.
- **Revoke** — always `204`, whether the submitted token exists or not.

### Scope: what this module does and does not do

This module owns the errors for **its own endpoints** and for **JWT
authentication failures on requests that present a `Bearer` token**
(`token_expired`, `invalid_token`, `session_revoked`, emitted before
Drupal's authentication runs).

It deliberately does **not** rewrite Drupal's site-wide access-denied
responses (e.g. mapping an anonymous `403` on an arbitrary protected
route to a typed `401`). A contrib module hijacking every `403/401`
would interfere with the host site and other modules. Applications that
want a uniform API error envelope across *all* their routes should
implement that at the application level (a `KernelEvents::EXCEPTION`
subscriber) — and must take care to exclude Drupal AJAX/form control
flow (`_wrapper_format=drupal_ajax`, `FormAjaxException`) when keying on
`Accept: application/json`.

## Server-side access token revocation (opt-in)

`drupal/jwt` is purely stateless: a compromised access token stays
valid until its `exp`. When `enable_access_token_revocation` is on, the
module embeds a standard **RFC 7519 `jti`** claim and validates it
against a DB allowlist, enabling **immediate logout**.

- The jti is recorded **at the point of issuance**
  (`JwtAuthEvents::GENERATE`), so every legitimately issued JWT — login,
  refresh, or a project issuer such as Azure AD — is allow-listed. A
  token can never be emitted outside the allowlist.
- It is enforced at two levels: a request-level subscriber returns a
  typed `401 session_revoked`; an authentication-level subscriber on
  `JwtAuthEvents::VALIDATE` is the route-agnostic, defense-in-depth
  check covering **every** `jwt_auth` route (REST resources included).
- **Applicative cascade**: the Drupal Schema API does not create real
  `FOREIGN KEY` constraints, so revoking a refresh token explicitly
  `DELETE`s its linked sessions in the same path. Revoking a refresh,
  rotating it, logging out, or evicting it on the per-user cap all kill
  the access tokens it issued.
- **Zero cost when off**: both subscribers short-circuit on the config
  flag before any DB lookup; nothing is stored. Stateless-pure projects
  pay nothing. `isValid()` is memoized per request, so an authenticated
  request costs at most one indexed `SELECT`.
- **Session rows are bounded by lifetime, not capped.** Unlike refresh
  tokens (which have `max_tokens_per_user`), access-token sessions are
  intentionally **not** capped per user: a cap would have to evict
  *valid* access tokens, silently logging out live sessions. Instead,
  rows are short-lived (`access_token_lifetime`, default 1 h) and purged
  on cron, so their count is bounded by the issuance rate over one
  lifetime rather than growing unbounded.

Trade-off: ~one indexed lookup per authenticated request (sub-ms) in
exchange for immediate logout. This is the killer feature versus plain
`drupal/jwt`, without the complexity of `simple_oauth`.

## Transport

All of the above assumes **TLS**. Tokens are bearer credentials; serve
the site over HTTPS only and set HSTS. The module emits `no-store` on
token responses but cannot protect tokens in transit on a plaintext
connection.

## Logging

The dedicated `jwt_token_refresh` log channel records security events
(e.g. refresh-token reuse detection) **without** raw tokens or raw jti
values. Only hashes are ever persisted.
