import "server-only";

import { cookies } from "next/headers";

/**
 * The customer access token lives in an httpOnly cookie, never in JavaScript.
 *
 * Same arrangement as the admin and vendor portals, and for the same reason: a
 * token in localStorage turns any XSS into account takeover. A customer token
 * can read their inquiries, post reviews in their name and close their account.
 *
 * The cookie NAMES are deliberately different from the other two apps'. All
 * three run on localhost in development, cookies are not isolated by port, and
 * shared names would mean signing into one silently signs you out of another.
 */

const TOKEN_COOKIE = "saka_customer_token";
const EXPIRY_COOKIE = "saka_customer_expires";

/** Readable by JS on purpose: it holds a boolean, not a secret. */
export const SESSION_FLAG_COOKIE = "saka_customer_session";

const baseCookieOptions = {
  httpOnly: true,
  // `lax`, not `strict`: customers arrive on listing links shared over
  // WhatsApp and from search engines, and `strict` would drop the session on
  // every one of those — landing a signed-in customer on a page that has
  // forgotten their saved listings.
  sameSite: "lax" as const,
  path: "/",
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
    store.delete(name);
  }
}
