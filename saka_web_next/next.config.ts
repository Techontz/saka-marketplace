import type { NextConfig } from "next";

/**
 * The SAKA marketplace.
 *
 * Every response carries the security headers a browser needs, because Next
 * sends none by default — the app was clickjackable and had no CSP.
 *
 * geolocation is ALLOWED for this origin: "near me", radius search and
 * the map's current-location control all depend on it. Blocking it here would
 * make those controls fail silently, which is exactly how a permissions policy
 * usually breaks a feature.
 */

const isProduction = process.env.NODE_ENV === "production";

/**
 * Hosts allowed to serve images, beyond this origin.
 *
 * Listing photos, avatars and logos are served by the API's storage disk, which
 * in production is a different host (S3, a CDN, or the API domain). It is an
 * ENV VAR rather than a constant because a CSP that omits the real storage host
 * fails silently — every image on the site simply does not render, and nothing
 * in the server logs says why.
 */
const imageHosts = (process.env.NEXT_PUBLIC_IMAGE_HOSTS ?? "")
  .split(/[\s,]+/)
  .filter(Boolean)
  .join(" ");

/**
 * Google AdSense.
 *
 * The CSP is widened ONLY when a publisher id is configured. An unconfigured
 * deployment — a review app, a local checkout — keeps the tighter policy, which
 * is the point: the allowances below are broad (AdSense serves creatives from
 * an open-ended set of Google and DoubleClick hosts and needs frames), and
 * carrying them on an install that shows no ads is unnecessary exposure.
 *
 * If these hosts are wrong the failure is SILENT — the unit renders as an empty
 * reserved box with only a console entry to say why — which is exactly how an
 * ad integration ends up shipped and earning nothing. Keep in step with
 * `lib/config.ts`.
 */
const adsenseEnabled = (process.env.NEXT_PUBLIC_ADSENSE_CLIENT ?? "").trim() !== "";

const ADSENSE_HOSTS = {
  script: [
    "https://pagead2.googlesyndication.com",
    "https://partner.googleadservices.com",
    "https://tpc.googlesyndication.com",
    "https://adservice.google.com",
  ],
  frame: [
    "https://googleads.g.doubleclick.net",
    "https://tpc.googlesyndication.com",
    "https://www.google.com",
  ],
  image: [
    "https://pagead2.googlesyndication.com",
    "https://googleads.g.doubleclick.net",
    "https://tpc.googlesyndication.com",
    "https://www.google.com",
    "https://www.gstatic.com",
  ],
  connect: [
    "https://pagead2.googlesyndication.com",
    "https://googleads.g.doubleclick.net",
    "https://adservice.google.com",
  ],
};

const adsense = (key: keyof typeof ADSENSE_HOSTS): string =>
  adsenseEnabled ? " " + ADSENSE_HOSTS[key].join(" ") : "";

/**
 * Content Security Policy.
 *
 * `unsafe-inline` on styles is required: Tailwind and React both emit inline
 * style attributes, and nonces cannot be applied to them. `unsafe-eval` is
 * allowed in development ONLY, where React Refresh needs it.
 */
const csp = [
  "default-src 'self'",
  `script-src 'self' 'unsafe-inline'${isProduction ? "" : " 'unsafe-eval'"}${adsense("script")}`,
  "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
  "font-src 'self' https://fonts.gstatic.com data:",
  // Map tile hosts are listed individually rather than wildcarded: img-src is
  // the ONLY thing standing between this page and an arbitrary image origin,
  // and a map layer that is not in this list fails as a blank grid with
  // nothing in the server logs. Keep in step with MAP_LAYERS in lib/config.ts.
  [
    "img-src 'self' data: blob:",
    "https://images.unsplash.com",
    "https://tile.openstreetmap.org",
    "https://server.arcgisonline.com",
    "https://tile.opentopomap.org",
    "https://a.tile.opentopomap.org",
    "https://b.tile.opentopomap.org",
    "https://c.tile.opentopomap.org",
    /*
     * The local API, for development ONLY.
     *
     * `php artisan serve` hands back absolute http://127.0.0.1:8000 media URLs,
     * so without these no listing photo renders on a developer's machine. They
     * must NOT survive into production: `upgrade-insecure-requests` is on there,
     * an http: origin in img-src is a plaintext hole in an otherwise TLS-only
     * policy, and in production media comes from NEXT_PUBLIC_IMAGE_HOSTS.
     */
    ...(isProduction ? [] : ["http://127.0.0.1:8000", "http://localhost:8000"]),
    imageHosts,
    adsenseEnabled ? ADSENSE_HOSTS.image.join(" ") : "",
  ]
    .filter(Boolean)
    .join(" "),
  // The browser only ever talks to this app's own origin: the API is reached
  // through the server-side proxy, which is what keeps the token in a cookie.
  // AdSense is the one exception, and only when it is switched on.
  `connect-src 'self'${adsense("connect")}`,
  /*
   * frame-src, not frame-ancestors — different directions.
   *
   * `frame-ancestors 'none'` below says nobody may embed SAKA. This says which
   * origins SAKA may embed, and an AdSense creative IS an iframe: without it
   * every unit renders as a blocked empty box. Default 'none' so an install
   * with ads off cannot be made to frame anything at all.
   */
  `frame-src ${adsenseEnabled ? ADSENSE_HOSTS.frame.join(" ") : "'none'"}`,
  "frame-ancestors 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "object-src 'none'",
  ...(isProduction ? ["upgrade-insecure-requests"] : []),
].join("; ");

const nextConfig: NextConfig = {
  // Advertising the framework and version only helps someone choosing an exploit.
  poweredByHeader: false,

  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "Content-Security-Policy", value: csp },
          { key: "X-Frame-Options", value: "DENY" },
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          { key: "Permissions-Policy", value: "camera=(), microphone=(), payment=(), geolocation=(self)" },
          // Only meaningful over TLS; harmless on http, and set here so a
          // production deployment behind a proxy is protected by default.
          {
            key: "Strict-Transport-Security",
            value: "max-age=31536000; includeSubDomains",
          },
        ],
      },
      /*
       * The admin portal keeps its own, stricter Permissions-Policy.
       *
       * It shipped as a separate origin that denied geolocation outright —
       * "nothing in the admin portal needs it". Folding three apps onto one
       * domain would have quietly handed it the marketplace's `geolocation=
       * (self)`, so the denial is restated here as a per-path rule. Listed
       * AFTER the catch-all: for the same header on a matching path, the later
       * entry wins.
       *
       * The vendor portal is deliberately NOT in this list. It asks for
       * location when a vendor drops a map pin on their business during
       * onboarding, so it needs the storefront's policy.
       */
      {
        source: "/admin/:path*",
        headers: [
          {
            key: "Permissions-Policy",
            value: "camera=(), microphone=(), payment=(), geolocation=()",
          },
        ],
      },
    ];
  },

  /*
   * Pin the workspace root.
   *
   * Turbopack infers a monorepo root by walking UP from the project looking for
   * lockfiles. There is a stray, empty `package-lock.json` in the user's home
   * directory, so it infers `/Users/<user>` as the root and says so on every
   * build.
   *
   * That is not cosmetic. The inferred root is what Turbopack watches, what it
   * resolves modules against, and what module ids in the React Client Manifest
   * are keyed on. If it changes between runs — a lockfile appearing anywhere
   * above the project will do it — ids computed under the old root no longer
   * match the ones being requested, and the symptom is a manifest lookup
   * failure naming modules that are perfectly valid.
   *
   * Carried over from the admin portal's config, which had already hit this.
   */
  turbopack: {
    root: __dirname,
  },
};

export default nextConfig;
