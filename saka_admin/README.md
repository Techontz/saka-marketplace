# SAKA Admin Portal

A separate Next.js application for administering the SAKA marketplace. It shares
nothing with the public frontend except the API.

## Why a separate app

- **Different blast radius.** An admin session can suspend accounts and delete
  listings. Keeping it on its own origin means the marketplace's cookies,
  bundle and XSS surface are not the admin portal's problem.
- **Different design language.** The marketplace's design is frozen by an
  explicit instruction; this is a tool, not a shopfront, and sharing a theme
  would couple two codebases that have no reason to move together.
- **`noindex` everywhere**, and no public routes beyond the auth screens.

## Running it

```bash
cp .env.example .env.local     # point SAKA_API_URL at the Laravel API
npm install
npm run dev
```

Sign in with a `super_admin`, `admin` or `moderator` account. Any other role is
refused at the door.

> Over plain HTTP in `next start`, the session cookie is `Secure` and the
> browser will not send it back — that is correct production behaviour. Use
> `npm run dev` locally, or run behind TLS.

## How auth works

```
Client Component ──► /api/saka/[...path] ──► Laravel   (proxy attaches bearer)
Sign in / out    ──► /api/auth/[action]  ──► Laravel   (token → httpOnly cookie)
```

**The browser never holds the access token.** It lives in an httpOnly,
SameSite=Strict cookie set server-side; client-side calls go through the
same-origin proxy, which attaches it. A token in `localStorage` would turn any
XSS into a full administrative takeover.

`remember me` chooses between a cookie that lasts as long as the token and one
that dies with the tab — it cannot extend the token, which the API owns.

## Permissions

`useAuth().can("listing.moderate")` hides controls the operator cannot use.

**This is presentation, not access control.** Every admin endpoint enforces its
own permission server-side, and typing a URL still reaches it — where it
correctly 403s. Treating the nav list as the security boundary is the classic
admin-portal mistake.

The permission list comes from `GET /auth/me` rather than being derived from
roles on the client: the role/permission matrix lives in PHP, and a second copy
here would drift silently, in the direction of showing operators buttons that do
not work.

## The four states

`ListState` decides between loading / error / empty / content so no screen
forgets one. The failure mode of a page-by-page admin build is that half the
screens conflate "no results" with "the request failed", and an operator
concludes the data is gone.

## What is deliberately not here

- **Permanent deletion in bulk.** Irreversible, super-admin only, and available
  one listing at a time from the detail page.
- **Setting another user's password.** An admin who can do that can sign in as
  them, and no audit trail can tell it apart from the real person. Only a reset
  link is offered.
- **Creating or deleting homepage sections.** Each is bound to a frontend
  component by its key; a section with no component renders nothing while
  looking like a bug.
- **Editing `is_public` on a setting.** It decides whether a value is
  world-readable, so exposing it would let someone publish an SMTP password by
  accident.
- **Search analytics.** The API does not log search queries. The screen says so
  rather than charting a substitute number.
