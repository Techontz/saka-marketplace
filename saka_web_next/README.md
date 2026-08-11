# SAKA web

One Next.js application serving three areas from one origin, against the Laravel
API at `https://api.saka.africa`.

| URL | Area | Layout |
|---|---|---|
| `/` | Marketplace | `app/(marketplace)/layout.tsx` |
| `/vendor` | Vendor portal | `app/vendor/layout.tsx` |
| `/admin` | Admin portal | `app/admin/layout.tsx` |

The vendor and admin portals were separate Next.js projects (`saka_vendor/`,
`saka_admin/`) until they were folded in here. Their code moved essentially
unchanged — routes under `app/{vendor,admin}/`, everything else namespaced under
`lib/`, `components/` and `providers/` — so each portal keeps the UI it was
built with. The original projects are in git history if you need to compare.

## Why they were separate, and what replaced each reason

The portals were split for real reasons. Consolidating onto one origin means
each of those reasons now needs an explicit mechanism instead of a domain
boundary. If you change any of this, change it knowing what it was for.

- **Blast radius.** An admin session can suspend accounts and delete listings.
  Separate origins kept the marketplace's cookies and XSS surface out of the
  admin portal's problem space. Now: three distinct cookie names
  (`saka_customer_token`, `saka_vendor_token`, `saka_admin_token`), each read
  only by its own area's proxy, and the portal cookies are scoped to `path=
  /vendor` and `path=/admin` so they are not even transmitted on storefront
  requests. A cookie from one area does not authenticate another.
- **Design language.** The marketplace is a shopfront; the portals are tools.
  Both portals were built with the *same* token names and different values —
  teal for vendors, blue for staff — so the shared names are declared once in
  `app/globals.css` and the differing values are scoped to `.theme-vendor` /
  `.theme-admin`, applied on each portal's layout. Two of those names
  (`--color-muted`, `--font-sans`) also collide with the storefront and are
  overridden only inside those scopes.
- **`noindex` everywhere.** Still true, set per-area in each portal's layout
  metadata rather than by the deployment.

## Layouts

The root layout holds only `<html>`, the font and the stylesheet. The
marketplace header and footer live in the `(marketplace)` route group — a group
contributes nothing to a URL — because a root layout is inherited by everything,
and the storefront chrome must not appear inside the portals.

## API access

The browser never calls the API directly. Each area proxies through its own
server route, which is what keeps the access token in an httpOnly cookie:

- `/api/saka/*` → marketplace
- `/vendor/api/saka/*` → vendor
- `/admin/api/saka/*` → admin

All three read `SAKA_API_URL` and append `/api/v1` themselves, so the variable is
an **origin with no path** — a value ending in `/api/v1` produces
`/api/v1/api/v1/…` and 404s everything. `lib/api/http.ts` refuses to boot a
production build against a development origin rather than serving localhost data
under a real domain.

## Running it

```bash
cp .env.example .env.local     # point SAKA_API_URL at the Laravel API
npm install
npm run dev
```

`npm run build` needs `SAKA_API_URL` too, and note that Next resolves
`.env.local` **above** `.env.production` — a stale `.env.local` on a server
silently overrides production config. The guard above turns that into a loud
failure instead of a quiet one.

## Deployment

One Vercel project, root directory `saka_web_next`, serving `saka.africa/`,
`saka.africa/vendor/*` and `saka.africa/admin/*`. There is no separate vendor or
admin deployment.
