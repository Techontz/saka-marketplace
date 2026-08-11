import "server-only";

import { cookies } from "next/headers";

/**
 * The admin access token lives in an httpOnly cookie, never in JavaScript.
 *
 * The API issues Sanctum bearer tokens. Keeping one in localStorage and
 * attaching it from the client is the obvious approach and is exactly what
 * turns any XSS into a full administrative takeover — and an admin token here
 * can suspend accounts, delete listings and read the audit log.
 *
 * Instead the token is written server-side with httpOnly. Client Components
 * call this app's `/api/saka/*` proxy, which reads the cookie and forwards the
 * Authorization header, so the browser drives the whole admin API without ever
 * being able to read the credential.
 */

const TOKEN_COOKIE = "saka_admin_token";
const EXPIRY_COOKIE = "saka_admin_expires";

/** Readable by JS on purpose: it holds a boolean, not a secret. */
export const SESSION_FLAG_COOKIE = "saka_admin_session";

const baseCookieOptions = {
  httpOnly: true,
  // `strict`, not `lax`. The marketplace uses lax so an inbound link keeps the
  // session; an admin portal has no such flow, and strict removes the
  // cross-site request surface entirely.
  sameSite: "strict" as const,
  /*
   * Scoped to the portal, not the whole site.
   *
   * This was "/" when the portal owned its own subdomain, where "/" and
   * "the portal" were the same thing. On the shared saka.africa domain a
   * "/" path would attach the admin session to every storefront request
   * too — a token travelling somewhere it is never read. All admin pages
   * and its API proxy live under /admin, so the narrower path costs
   * nothing and keeps the three sessions genuinely separate.
   */
  path: "/admin",
  secure: process.env.NODE_ENV === "production",
};

export async function readToken(): Promise<string | null> {
  const store = await cookies();
  return store.get(TOKEN_COOKIE)?.value ?? null;
}

/**
 * @param expiresAt ISO-8601 from the API's `expires_at`.
 * @param remember  Extends the COOKIE's lifetime to the token's full validity.
 *                  Without it the cookie is a session cookie and dies when the
 *                  browser closes — the safer default for a shared machine.
 */
export async function writeSession(token: string, expiresAt: string, remember = false): Promise<void> {
  const store = await cookies();
  const expires = new Date(expiresAt);

  const validExpiry = Number.isNaN(expires.getTime())
    ? new Date(Date.now() + 24 * 60 * 60 * 1000)
    : expires;

  /*
   * "Remember me" cannot outlive the TOKEN — the API decides that, and a
   * cookie that survives its own credential just produces confusing 401s.
   * So this is a choice between "until the token expires" and "until the tab
   * closes", not an extension.
   */
  const cookieExpiry = remember ? { expires: validExpiry } : {};

  store.set(TOKEN_COOKIE, token, { ...baseCookieOptions, ...cookieExpiry });
  store.set(EXPIRY_COOKIE, validExpiry.toISOString(), { ...baseCookieOptions, ...cookieExpiry });
  store.set(SESSION_FLAG_COOKIE, "1", { ...baseCookieOptions, httpOnly: false, ...cookieExpiry });
}

export async function clearSession(): Promise<void> {
  const store = await cookies();
  for (const name of [TOKEN_COOKIE, EXPIRY_COOKIE, SESSION_FLAG_COOKIE]) {
    // The PATH must match the one the cookie was written with, or the browser
    // keeps it: a delete for "/" does not touch a cookie scoped to
    // "/admin". Without this, signing out clears the server-side token
    // but leaves a stale cookie behind, and the next request arrives with a
    // credential the API has already revoked.
    store.delete({ name, path: baseCookieOptions.path });
  }
}
