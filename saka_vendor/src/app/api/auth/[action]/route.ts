import { NextRequest, NextResponse } from "next/server";

import { ApiError } from "@/lib/api/errors";
import { API_BASE, request } from "@/lib/api/http";
import { clearSession, readToken, writeSession } from "@/lib/auth/session";

/**
 * The credential boundary.
 *
 * These actions are the only ones that ever touch an access token, and the
 * token never leaves this file: it goes from the API response straight into an
 * httpOnly cookie. The browser gets the user object and nothing else.
 *
 *   POST /api/auth/login            { email, password, remember? }
 *   POST /api/auth/logout
 *   POST /api/auth/forgot-password  { email }
 *   POST /api/auth/reset-password   { token, email, password, ... }
 *   GET  /api/auth/session
 */

type ApiUser = {
  uuid: string;
  first_name: string;
  last_name: string | null;
  full_name: string;
  email: string;
  phone: string | null;
  status: string;
  roles: string[];
  email_verified: boolean;
  phone_verified: boolean;
  /** Publishing is gated on a verified phone; this is what the portal checks. */
  can_publish_listings: boolean;
  permissions?: string[];
};

type AuthResult = { user: ApiUser; token: string; expires_at: string };

/**
 * Who may use this portal.
 *
 * DELIBERATELY PERMISSIVE, unlike the admin portal's staff-only door. Any
 * registered account can sign in here, because the seller role is granted
 * automatically on a user's FIRST published listing — so a would-be vendor who
 * has not listed anything yet does not have it, and refusing them would lock
 * new vendors out of the tool they need to become vendors.
 *
 * The API is still the authority: every seller endpoint enforces its own
 * permission, and publishing additionally requires a verified phone.
 */
const PORTAL_ROLES: string[] | null = null;

export async function POST(
  httpRequest: NextRequest,
  context: { params: Promise<{ action: string }> },
): Promise<Response> {
  const { action } = await context.params;

  if (action === "logout") return handleLogout();
  if (action === "login") return handleLogin(httpRequest);
  if (action === "register") return handleRegister(httpRequest);
  if (action === "forgot-password") return relayPublic(httpRequest, "/auth/forgot-password");
  if (action === "reset-password") return relayPublic(httpRequest, "/auth/reset-password");

  return errorResponse(404, "NOT_FOUND", "Unknown auth action.");
}

export async function GET(
  _httpRequest: NextRequest,
  context: { params: Promise<{ action: string }> },
): Promise<Response> {
  const { action } = await context.params;

  if (action !== "session") return errorResponse(404, "NOT_FOUND", "Unknown auth action.");

  const token = await readToken();

  if (!token) return json({ data: { user: null } });

  try {
    const result = await request<{ data: ApiUser }>(`${API_BASE}/auth/me`, { token });
    return json({ data: { user: result.data } });
  } catch (error) {
    /*
     * A rejected token means the session is over. Answer "signed out" rather
     * than surfacing a 401, so the UI settles into a correct state instead of
     * retrying a credential that will never work.
     */
    if (error instanceof ApiError && error.isUnauthenticated) {
      await clearSession();
      return json({ data: { user: null } });
    }

    return relayError(error);
  }
}

async function handleLogin(httpRequest: NextRequest): Promise<Response> {
  let payload: { email?: string; password?: string; remember?: boolean };

  try {
    payload = await httpRequest.json();
  } catch {
    return errorResponse(400, "VALIDATION_FAILED", "Expected a JSON body.");
  }

  try {
    const result = await request<{ data: AuthResult }>(`${API_BASE}/auth/login`, {
      method: "POST",
      body: { email: payload.email, password: payload.password },
      headers: forwardedHeaders(httpRequest),
    });

    const { user, token, expires_at: expiresAt } = result.data;

    if (PORTAL_ROLES !== null && !user.roles.some((role) => PORTAL_ROLES.includes(role))) {
      await request(`${API_BASE}/auth/logout`, { method: "DELETE", token }).catch(() => undefined);

      return errorResponse(403, "FORBIDDEN", "This account cannot use the vendor portal.");
    }

    await writeSession(token, expiresAt, payload.remember === true);

    /*
     * Re-read the user from `/auth/me` rather than returning the login payload.
     *
     * `POST /auth/login` builds its UserResource for a request that is not yet
     * authenticated, so the API omits `permissions` and `phone_verified` is the
     * only signal the portal has for whether this vendor can publish. One extra
     * server-side request makes this endpoint's contract match
     * `/api/auth/session` exactly.
     */
    const me = await request<{ data: ApiUser }>(`${API_BASE}/auth/me`, { token }).catch(() => null);

    // Note what is NOT here: `token`.
    return json({ data: { user: me?.data ?? user } });
  } catch (error) {
    return relayError(error);
  }
}

/**
 * Registration signs the new vendor straight in.
 *
 * `POST /auth/register` returns a token exactly like login does, so it goes
 * through the same cookie path — asking someone to sign in immediately after
 * creating an account is friction with no security benefit.
 */
async function handleRegister(httpRequest: NextRequest): Promise<Response> {
  let payload: unknown;

  try {
    payload = await httpRequest.json();
  } catch {
    return errorResponse(400, "VALIDATION_FAILED", "Expected a JSON body.");
  }

  try {
    const result = await request<{ data: AuthResult }>(`${API_BASE}/auth/register`, {
      method: "POST",
      body: payload,
      headers: forwardedHeaders(httpRequest),
    });

    const { token, expires_at: expiresAt } = result.data;

    await writeSession(token, expiresAt, true);

    const me = await request<{ data: ApiUser }>(`${API_BASE}/auth/me`, { token }).catch(() => null);

    return json({ data: { user: me?.data ?? result.data.user } }, 201);
  } catch (error) {
    return relayError(error);
  }
}

/**
 * Forgot/reset password are unauthenticated and return no token, so they are
 * relayed as-is. They are still routed through this file rather than the proxy
 * so that every credential-adjacent flow lives in one place.
 */
async function relayPublic(httpRequest: NextRequest, path: string): Promise<Response> {
  let payload: unknown;

  try {
    payload = await httpRequest.json();
  } catch {
    return errorResponse(400, "VALIDATION_FAILED", "Expected a JSON body.");
  }

  try {
    const result = await request<unknown>(`${API_BASE}${path}`, {
      method: "POST",
      body: payload,
      headers: forwardedHeaders(httpRequest),
    });

    return json(result as Record<string, unknown>);
  } catch (error) {
    return relayError(error);
  }
}

/**
 * Revokes the token server-side, then drops the cookie.
 *
 * The cookie is cleared even if the API call fails: someone who clicked "sign
 * out" must end up signed out on this device regardless of whether the server
 * was reachable.
 */
async function handleLogout(): Promise<Response> {
  const token = await readToken();

  if (token) {
    await request(`${API_BASE}/auth/logout`, { method: "DELETE", token }).catch(() => undefined);
  }

  await clearSession();

  return json({ data: { user: null } });
}

function forwardedHeaders(httpRequest: NextRequest): Record<string, string> {
  const headers: Record<string, string> = {};

  // So the API's per-IP throttles and its audit trail see the real client,
  // not this server.
  const forwardedFor = httpRequest.headers.get("x-forwarded-for");
  if (forwardedFor) headers["X-Forwarded-For"] = forwardedFor;

  const userAgent = httpRequest.headers.get("user-agent");
  if (userAgent) headers["User-Agent"] = userAgent;

  return headers;
}

function json(body: Record<string, unknown>, status = 200): Response {
  return NextResponse.json(body, { status, headers: { "Cache-Control": "no-store" } });
}

/** Re-emits an ApiError in the API's own envelope so the client parses it uniformly. */
function relayError(error: unknown): Response {
  if (error instanceof ApiError) {
    return NextResponse.json(
      {
        error: {
          code: error.code,
          message: error.message,
          details: error.details,
          request_id: error.requestId,
        },
        ...(error.isValidation ? { errors: error.fieldErrors } : {}),
      },
      { status: error.status || 502, headers: { "Cache-Control": "no-store" } },
    );
  }

  return errorResponse(502, "NETWORK_ERROR", "Could not reach the server. Please try again.");
}

function errorResponse(status: number, code: string, message: string): Response {
  return NextResponse.json(
    { error: { code, message } },
    { status, headers: { "Cache-Control": "no-store" } },
  );
}

export const dynamic = "force-dynamic";
