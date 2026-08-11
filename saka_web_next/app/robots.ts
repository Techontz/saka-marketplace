import type { MetadataRoute } from "next";

import { SITE_URL } from "@/lib/config";

/**
 * Crawl rules.
 *
 * The account area and the auth pages are disallowed: they are per-customer,
 * they require a session, and a crawler that indexes them produces search
 * results that lead strangers to a sign-in wall. The API proxy is disallowed
 * for the same reason — it is not content.
 */
export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/account", "/account/", "/api/", "/login", "/register", "/reset-password", "/forgot-password"],
    },
    sitemap: `${SITE_URL}/sitemap.xml`,
  };
}
