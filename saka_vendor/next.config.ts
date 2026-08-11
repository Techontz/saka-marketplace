import type { NextConfig } from "next";

/**
 * The SAKA vendor portal.
 *
 * Every response carries the security headers a browser needs, because Next
 * sends none by default — the app was clickjackable and had no CSP.
 *
 * Geolocation is allowed: a vendor drops a map pin on their own business
 * during onboarding.
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
  // Map tile hosts are listed individually rather than wildcarded: img-src is
  // the only thing standing between this page and an arbitrary image origin,
  // and a layer missing from this list fails as a blank grid with nothing in
  // the server logs. Keep in step with MAP_LAYERS in src/lib/map.ts.
  [
    "img-src 'self' data: blob:",
    "https://images.unsplash.com",
    "https://tile.openstreetmap.org",
    "https://server.arcgisonline.com",
    "https://tile.opentopomap.org",
    // Development ONLY — see the marketplace next.config.ts for why these must
    // not reach production. Media there comes from NEXT_PUBLIC_IMAGE_HOSTS.
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
    ];
  },
};

export default nextConfig;
