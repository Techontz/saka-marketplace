import { NextRequest, NextResponse } from "next/server";

import { ApiError } from "@/lib/admin/api/errors";
import { API_BASE, request } from "@/lib/admin/api/http";
import { clearSession, readToken, writeSession } from "@/lib/admin/auth/session";

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
  status: string;
  roles: string[];
  /** Only present when the API is describing the CALLER to themselves. */
  permissions?: string[];
};

type AuthResult = { user: ApiUser; token: string; expires_at: string };

/**
 * Roles allowed to use this portal at all.
 *
 * Checked HERE, at sign-in, rather than only per-page. A buyer with valid
 * credentials would otherwise receive an admin session cookie and be stopped
 * only by each endpoint's own 403 — which works, but means the portal hands out
 * sessions it has no intention of honouring, and every screen has to fail
 * gracefully for a user who should never have got in.
 *
 * The API is still the authority: every endpoint enforces its own permission.
 * This is the front door, not the lock.
 */
const PORTAL_ROLES = ["super_admin", "admin", "moderator"];

export async function POST(
  httpRequest: NextRequest,
  context: { params: Promise<{ action: string }> },
): Promise<Response> {
  const { action } = await context.params;

  if (action === "logout") return handleLogout();
  if (action === "login") return handleLogin(httpRequest);
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

    if (!user.roles.some((role) => PORTAL_ROLES.includes(role))) {
      /*
       * Credentials were correct, so the token now exists server-side. Revoke
       * it rather than just declining to store it — otherwise every rejected
       * sign-in leaves a live admin-scoped token behind, valid until it
       * expires, that nobody is tracking.
       */
      await request(`${API_BASE}/auth/logout`, { method: "DELETE", token }).catch(() => undefined);

      return errorResponse(403, "FORBIDDEN", "This account does not have access to the admin portal.");
    }

    await writeSession(token, expiresAt, payload.remember === true);

    /*
     * Re-read the user from `/auth/me` rather than returning the login
     * payload.
     *
     * `POST /auth/login` builds its UserResource for a request that is not yet
     * authenticated, and the API only includes `permissions` when the caller is
     * asking about themselves — so the login payload carries an EMPTY
     * permission list. Returning it would leave the portal's `can()` false for
     * everything and the sidebar blank until something happened to refetch.
     *
     * One extra server-side request, and this endpoint's contract matches
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
