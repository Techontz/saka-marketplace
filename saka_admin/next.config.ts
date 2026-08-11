import type { NextConfig } from "next";

/**
 * The SAKA admin portal.
 *
 * Every response carries the security headers a browser needs, because Next
 * sends none by default — the app was clickjackable and had no CSP.
 *
 * Geolocation is denied outright: nothing in the admin portal needs it.
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
 * Content Security Policy.
 *
 * `unsafe-inline` on styles is required: Tailwind and React both emit inline
 * style attributes, and nonces cannot be applied to them. `unsafe-eval` is
 * allowed in development ONLY, where React Refresh needs it.
 */
const csp = [
  "default-src 'self'",
  `script-src 'self' 'unsafe-inline'${isProduction ? "" : " 'unsafe-eval'"}`,
  "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
  "font-src 'self' https://fonts.gstatic.com data:",
  // No map tile hosts: nothing in the admin portal renders a map.
  // The two localhost origins are development ONLY — `php artisan serve` hands
  // back absolute http://127.0.0.1:8000 media URLs, but in production media
  // comes from NEXT_PUBLIC_IMAGE_HOSTS and an http: origin here would be a
  // plaintext hole in an otherwise TLS-only policy.
  [
    "img-src 'self' data: blob:",
    "https://images.unsplash.com",
    ...(isProduction ? [] : ["http://127.0.0.1:8000", "http://localhost:8000"]),
    imageHosts,
  ]
    .filter(Boolean)
    .join(" "),
  // The browser only ever talks to this app's own origin: the API is reached
  // through the server-side proxy, which is what keeps the token in a cookie.
  "connect-src 'self'",
  "frame-ancestors 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "object-src 'none'",
  ...(isProduction ? ["upgrade-insecure-requests"] : []),
].join("; ");

const nextConfig: NextConfig = {
  /*
   * Pin the workspace root.
   *
   * Turbopack infers a monorepo root by walking UP from the project looking
   * for lockfiles. There is a stray, empty `package-lock.json` in the user's
   * home directory, so it was inferring `/Users/<user>` as the root and saying
   * so on every boot:
   *
   *     Warning: Next.js inferred your workspace root, but it may not be
   *     correct. We detected multiple lockfiles and selected the directory of
   *     /Users/<user>/package-lock.json as the root directory.
   *
   * That is not cosmetic. The inferred root is what Turbopack watches, what it
   * resolves modules against, and what module ids in the React Client Manifest
   * are keyed on. If it changes between runs — a lockfile appearing anywhere
   * above the project will do it — ids computed under the old root no longer
   * match the ones being requested, and the symptom is a manifest lookup
   * failure naming modules that are perfectly valid:
   *
   *     Could not find the module .../layout.tsx#default
   *     in the React Client Manifest
   *
   * Pinning it makes the module graph deterministic and independent of
   * anything outside this directory.
   */
  turbopack: { root: __dirname },

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
          { key: "Permissions-Policy", value: "camera=(), microphone=(), payment=(), geolocation=()" },
          // Only meaningful over TLS; harmless on http, and set here so a
          // production deployment behind a proxy is protected by default.
          {
            key: "Strict-Transport-Security",
            value: "max-age=31536000; includeSubDomains",
          },
        ],
      },
    ];
  },
};

export default nextConfig;
