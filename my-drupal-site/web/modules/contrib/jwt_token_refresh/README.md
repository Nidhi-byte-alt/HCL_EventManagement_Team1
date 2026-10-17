# JWT Token Refresh

Lightweight JWT authentication for decoupled Drupal: login, **refresh
token rotation**, configurable token lifetimes, and **optional
server-side revocation** (immediate logout) — without the weight of a
full OAuth2 stack.

`drupal/jwt` issues and validates JWTs but stops there: no login
endpoint, no refresh tokens, and an access token stays valid until its
`exp` (no instant logout). This module fills that gap.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/jwt_token_refresh).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/jwt_token_refresh).

## When to use this

| You need… | Use |
|-----------|-----|
| Issue/validate a JWT, nothing else | `drupal/jwt` |
| Login + silent refresh for a SPA or mobile app, optional instant logout | **this module** |
| Full OAuth2: multiple clients, third-party authorization, scopes | `simple_oauth` |

The differentiator over plain `drupal/jwt` is **opt-in instant logout**
(server-side `jti` revocation) without the OAuth2 machinery.

## Requirements

- Drupal `^10.3 || ^11`
- [`drupal/jwt`](https://www.drupal.org/project/jwt) `^2.0` with the
  **JWT Authentication Issuer** and **JWT Authentication Consumer**
  submodules (declared as dependencies).
- A configured JWT signing key, created with the `key` module that `jwt`
  already requires. See step 2 of the quick start below: it is the only
  step that needs doing, and the only one that breaks every endpoint if
  skipped.

## Quick start

### 1. Install

```bash
composer require drupal/jwt_token_refresh
drush en jwt_token_refresh
```

Composer pulls in `drupal/jwt` with its Issuer and Consumer submodules,
and `key`. Nothing else to add.

### 2. Create a signing key

The one real step, and the one that makes every endpoint fail if you skip
it. There is no signing-key setting in this module: it signs with the key
`drupal/jwt` already owns.

At **Administration › Configuration › System › Keys › Add key**
(`/admin/config/system/keys/add`). The form grows three sections as you
fill it in:

- **Key name** — anything, e.g. `JWT signing key`.
- **Type settings › Key type**: `JWT HMAC Key`. Choosing it reveals
  **JWT Algorithm** — pick `HS512` (or `HS256`).
- **Provider settings › Key provider**: `Configuration`, the default.
  Leave its **Base64-encoded** box unchecked.
- **Value › Key value**: paste a secret of your own, for instance
  `openssl rand -hex 32` — 64 characters, the 512 bits `HS512` needs.
  Leave the second **Base64-encoded** box unchecked as well.

**There is no generate button for a JWT key.** The input widget is not a
choice: `KeyFormBase` derives it from the key type and no installed type
asks for the `generate` plugin, so `JWT HMAC Key` gets a plain text
field. Bring your own secret.

**The two Base64-encoded boxes mean different things.** The provider's
encodes the secret *at rest* and decodes it on read; the value's declares
that *what you paste* is already base64 and decodes it before storing.
Both unchecked means you paste raw.

Whatever you paste, the form rejects it below 512 bits for `HS512`, or
256 bits for `HS256`.

Then select the key at **Administration › Configuration › System › JWT
Authentication** (`/admin/config/system/jwt`) and **press Save
configuration**. That page pre-selects your only key, so it looks done
before it is: nothing is stored until you submit it, and until then every
endpoint answers `500`.

To check, open the status report (`/admin/reports/status`): the **JWT
signing key** line should read *Configured*.

Scripted equivalent, for a CI or a fresh dev site:

```bash
SECRET=$(openssl rand -hex 32)   # 64 chars = 512 bits, what HS512 needs
drush php:eval "\Drupal\key\Entity\Key::create([
  'id' => 'jwt_signing_key',
  'label' => 'JWT signing key',
  'key_type' => 'jwt_hs',
  'key_type_settings' => ['algorithm' => 'HS512'],
  'key_provider' => 'config',
  'key_provider_settings' => ['key_value' => '$SECRET'],
  'key_input' => 'text_field',
])->save();"
drush config:set jwt.config key_id jwt_signing_key -y
```

Note that `php:eval` bypasses the form validation that enforces the
minimum key size, so size the secret yourself.

To confirm, open `/admin/reports/status`: the **JWT signing key** line
reads *Configured*. Until it does it reads *Not configured* as an error,
and `/auth/token` answers `500 internal_error` with the reason in the
`jwt_token_refresh` log channel.

### 3. Log in

```bash
curl -X POST https://example.com/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"name":"alice","pass":"s3cr3t"}'
```
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzUxMiJ9...",
  "refresh_token": "9f2c7a...",
  "refresh_expires_in": 604800,
  "token_type": "Bearer"
}
```

The access token lasts an hour by default, the refresh token a week.

To keep going from a shell without copying tokens by hand:

```bash
S=https://example.com
R=$(curl -s -X POST $S/auth/token -H 'Content-Type: application/json' \
  -d '{"name":"alice","pass":"s3cr3t"}')
ACCESS=$(echo "$R"  | python3 -c 'import json,sys; print(json.load(sys.stdin)["access_token"])')
REFRESH=$(echo "$R" | python3 -c 'import json,sys; print(json.load(sys.stdin)["refresh_token"])')
```

### 4. Use it

```bash
curl -H "Authorization: Bearer $ACCESS_TOKEN" \
  'https://example.com/user/login_status?_format=json'
# 1   -- authenticated. Without the header: 0.
```

From here the same header works on JSON:API, REST, or any route of your
own.

### 5. Refresh

```bash
curl -X POST https://example.com/auth/token/refresh \
  -H 'Content-Type: application/json' \
  -d '{"refresh_token":"9f2c7a..."}'
```

A brand-new pair, and the refresh token you just sent is dead. Store the
new one. Sending a spent refresh token again is treated as a theft
indicator and revokes the whole chain, so never retry a refresh with a
token you have already used.

### 6. Log out

```bash
curl -X POST https://example.com/auth/token/revoke \
  -H "Authorization: Bearer $ACCESS_TOKEN"
# 204 No Content
```

**Read this before testing it.** By default that kills the refresh token,
so the session cannot be renewed, but the access token the client already
holds stays valid until its `exp`: Drupal validates a JWT from its
signature alone, with no lookup. For a logout that takes effect on the
very next request, enable **server-side access token revocation** (see
below); the revoked token then answers `401 session_revoked` immediately.

**One thing that will bite you while testing.** Five failed logins for the
same account return `429 too_many_attempts` and lock it for six hours —
the correct password included. Clear it with:

```bash
drush sql:query "DELETE FROM flood WHERE event LIKE 'jwt_token_refresh%'"
```

Then review the settings at **Administration › Configuration › System ›
JWT Token Refresh** (`/admin/config/system/jwt-token-refresh`); every
field ships with a working default.

### When something does not work

Every symptom below was met on a real install, in this order.

| What you see | What it is |
|---|---|
| `404` on `/auth/token` | The module is not enabled. `drush en jwt_token_refresh` |
| `500 internal_error` on login | No usable signing key. Check the status report, and remember `/admin/config/system/jwt` has to be **saved**. The reason is named in the `jwt_token_refresh` log channel |
| `429 too_many_attempts`, even with the right password | Flood control: five failed logins lock that account for six hours. `drush sql:query "DELETE FROM flood WHERE event LIKE 'jwt_token_refresh%'"` |
| `401 invalid_token` refreshing a token you believe is valid | It was already rotated — or a replay revoked its whole family. Log in again |
| `400 validation_failed` | The request body is not JSON, or `name`/`pass` are missing |
| The access token still works after logout | Expected. Immediate revocation is the opt-in setting; see above |
| Drupal's own HTML 403 instead of a JSON error | Your `Authorization` header carries a Bearer token that is not JWT-shaped, so this module deliberately leaves it to whichever provider owns it |
| Every authenticated request is anonymous, on Apache | The `Authorization` header is not reaching PHP. Apache with CGI/FastCGI drops it unless `CGIPassAuth On` is set — a server-level problem, not a module one |

## Endpoints

All token responses carry `Cache-Control: no-store`.

### `POST /auth/token` — login

```json
// request
{ "name": "alice", "pass": "s3cr3t" }
```
```json
// 200
{
  "access_token": "eyJhbGc…",
  "refresh_token": "a1b2c3…",
  "refresh_expires_in": 604800,
  "token_type": "Bearer"
}
```
```json
// 401 — any failed login (unknown user, wrong password, blocked…)
{ "error": "invalid_credentials", "message": "Invalid credentials." }
```

### `POST /auth/token/refresh` — rotate

```json
// request
{ "refresh_token": "a1b2c3…" }
```
```json
// 200 — a brand-new pair; the old refresh token is now dead
{ "access_token": "eyJhbGc…", "refresh_token": "x9y8z7…",
  "refresh_expires_in": 604800, "token_type": "Bearer" }
```
```json
// 401 — absent, malformed, unknown, expired, or already rotated
{ "error": "invalid_token", "message": "Invalid or expired token." }
```

Replaying an already-rotated token additionally revokes the whole token
family (reuse detection); the response stays identical.

### `POST /auth/token/revoke` — logout

Bearer-only. Send the access token in the `Authorization` header.
Optionally send `{"refresh_token": "…"}` to revoke a single token;
otherwise all of the caller's tokens are revoked.

```
Authorization: Bearer eyJhbGc…
```
Returns `204 No Content` and is idempotent (same `204` whether the
token existed or not). With no `Authorization` header at all, it answers
`401 invalid_token` in the usual JSON envelope, with a
`WWW-Authenticate: Bearer` header — not an HTML access-denied page.

One case is deliberately left to Drupal: a Bearer token that is *not*
JWT-shaped (no `header.payload.signature`) may belong to another
authentication provider, so this module does not claim it and the request
gets Drupal's own access response. Only JWT-shaped tokens, and the absence
of a credential, are answered with the typed envelope.

## Error format

Every error is a JSON object with a stable, machine-readable code:

```json
{ "error": "<machine_code>", "message": "<human-readable>" }
```

`message` is English by default. The `error` field is stable across
versions and is what consumers should branch on. (This is intentionally
**not** RFC 7807 `application/problem+json`; a minimal envelope covers
the need and is easier to consume. An optional `details` object is
reserved for future structured field-level errors.)

### Code catalogue

| Code                  | Status | Where |
|-----------------------|--------|-------|
| `invalid_credentials` | 401 | `POST /auth/token` |
| `validation_failed`   | 400 | malformed body on `/auth/token` |
| `invalid_token`       | 401 | `POST /auth/token/refresh`, a forged/malformed Bearer, or no `Authorization` header on `/auth/token/revoke` |
| `token_expired`       | 401 | a Bearer token past its `exp` |
| `session_revoked`     | 401 | a Bearer token revoked server-side (opt-in) |
| `too_many_attempts`   | 429 | flood control on login/refresh |
| `internal_error`      | 500 | token generation failed (no signing key) |

## Configuration

| Setting | Key | Default | Notes |
|---------|-----|---------|-------|
| Access token lifetime (s) | `access_token_lifetime` | `3600` | JWT `exp`. Max 86400 |
| Refresh token lifetime (s) | `refresh_token_lifetime` | `604800` | Max 2592000 (30 d) |
| Refresh token entropy (bytes) | `refresh_token_bytes` | `64` | **Min 32** |
| Max tokens per user | `max_tokens_per_user` | `10` | 0 disables the cap |
| Inject into /user/login | `inject_into_user_login` | `false` | adds `refresh_token` |
| Cleanup on cron | `cleanup_on_cron` | `true` | purge expired rows |
| Flood: attempts/h per IP | `flood_ip_limit` | `50` | |
| Flood: attempts per user/IP | `flood_user_limit` | `5` | 6 h window |
| Server-side revocation | `enable_access_token_revocation` | `false` | opt-in, see below |
| jti entropy (bytes) | `jti_bytes` | `16` | **Min 16** |

## Server-side revocation (opt-in)

By default the module is stateless: an access token is valid until its
`exp`. Enable `enable_access_token_revocation` to make **logout
immediate** — a revoked JWT is rejected with `401 session_revoked` on
the next request, instead of remaining valid until expiry.

This embeds a standard RFC 7519 `jti` claim and checks it against a DB
allowlist (one indexed lookup per authenticated request, memoized).
Revoking, rotating, or logging out kills the access tokens that the
refresh token issued (applicative cascade). With the flag off there is
**zero** per-request cost and nothing is stored.

### Per-device logout

With revocation enabled, an application can log out **only the current
device** (other sessions stay alive) by resolving the request's access
token `jti` to its issuing refresh token:

```php
$jti = $jwt->getClaim('jti');
$id = \Drupal::service('jwt_token_refresh.access_token_session_manager')
  ->getRefreshTokenIdForJti($jti);
if ($id !== NULL) {
  \Drupal::service('jwt_token_refresh.refresh_token_manager')
    ->revokeById($id);
}
```

The caller is responsible for authorization: only revoke ids resolved
from the current user's own token. For a global "sign out everywhere",
use `revokeAllForUser($uid)` instead.

See [SECURITY.md](SECURITY.md) for the full rationale and trade-offs.

## Extending the response (events)

Application modules can enrich the login/refresh body without patching
this module:

```php
use Drupal\jwt_token_refresh\Event\JwtLoginResponseEvent;
use Drupal\jwt_token_refresh\Event\JwtTokenRefreshEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class MyLoginSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [JwtTokenRefreshEvents::LOGIN_RESPONSE => 'onLogin'];
  }

  public function onLogin(JwtLoginResponseEvent $event): void {
    $event->addData('display_name', $event->getUser()->getDisplayName());
  }

}
```

`JwtTokenRefreshEvents::REFRESH_RESPONSE` works the same way for the
refresh endpoint.

## /user/login injection (opt-in)

With `inject_into_user_login` enabled, a successful
`POST /user/login?_format=json` gains `refresh_token`,
`refresh_expires_in` and `token_type` — and an `access_token` too if
`jwt_auth_issuer` did not already add one — so a frontend can keep using
the core login endpoint and still refresh.

### Mind the box on the other side

`jwt_auth_issuer` has its own setting, **Include a JWT token in the user
login response**, on the JWT Configuration page. **It ships enabled**
(`jwt_in_login_response: true`), so it is already active on your site.
It adds an `access_token` to the same core response — but that submodule
has no concept of refresh, so the token it hands out cannot be renewed.

Only one of the four combinations is a trap, and it is the default one:

| `jwt_in_login_response` | `inject_into_user_login` | Result on `/user/login` |
|---|---|---|
| on *(default)* | off *(default)* | an access token nothing can refresh |
| on | on | works; the issuer sets `access_token`, this module completes the pair |
| off | on | works; this module supplies both |
| off | off | plain core response — use `POST /auth/token` instead |

For a decoupled frontend, `POST /auth/token` is the endpoint meant for
you, and a second half-working login path is a liability: **uncheck
*Include a JWT token in the user login response*** unless you
deliberately use core's `/user/login`. If you do use it, turn
`inject_into_user_login` on so the response carries a refresh token too.

One more consequence worth knowing: core's `/user/login` opens a Drupal
session cookie. This module's own `/auth/token` does not — it is
stateless by design.

## Security

See [SECURITY.md](SECURITY.md): token hashing, CSPRNG, atomic rotation
with OWASP reuse detection, flood control, constant-time login,
account-lifecycle revocation, the applicative cascade, and the
anti-enumeration policy.

## Maintainers

- Rym Oueslati - [rym_oueslati](https://www.drupal.org/u/rym_oueslati)

Supporting organization:

- [Neovia](https://www.drupal.org/neovia)

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
