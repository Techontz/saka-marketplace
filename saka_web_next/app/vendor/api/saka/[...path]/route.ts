import { NextRequest, NextResponse } from "next/server";

import { API_BASE } from "@/lib/vendor/api/http";
import { clearSession, readToken } from "@/lib/vendor/auth/session";

/**
 * Same-origin proxy to the SAKA API for Client Components.
 *
 * The browser calls `/api/saka/admin/listings`; this handler attaches the
 * bearer token from the httpOnly session cookie and forwards to Laravel. The
 * token is therefore never present anywhere JavaScript can reach it.
 *
 * It is a dumb pipe: it does not interpret bodies, does not retry, and does not
 * rewrite errors — the API's error envelope reaches the client untouched, so
 * `ApiError` parses proxied and direct responses identically.
 */

const ALLOWED_METHODS = new Set(["GET", "POST", "PATCH", "PUT", "DELETE"]);

/**
 * Auth endpoints are NOT proxied.
 *
 * `/auth/login` returns the access token in its response body. Forwarding that
 * verbatim would hand the browser the exact credential this arrangement exists
 * to withhold, so those routes have dedicated handlers under `/api/auth/*`.
 */
const BLOCKED_PREFIXES = ["auth/login", "auth/register", "auth/oauth", "auth/refresh"];

const FORWARDED_RESPONSE_HEADERS = [
  "content-type",
  "x-request-id",
  "x-ratelimit-limit",
  "x-ratelimit-remaining",
  "retry-after",
];

async function handler(
  request: NextRequest,
  context: { params: Promise<{ path: string[] }> },
): Promise<Response> {
  const { path } = await context.params;
  const target = path.join("/");

  if (!ALLOWED_METHODS.has(request.method)) {
    return NextResponse.json(
      { error: { code: "METHOD_NOT_ALLOWED", message: "Method not allowed." } },
      { status: 405 },
    );
  }

  if (BLOCKED_PREFIXES.some((prefix) => target.startsWith(prefix))) {
    return NextResponse.json(
      {
        error: {
          code: "FORBIDDEN",
          message: "Use the dedicated /api/auth endpoints for this operation.",
        },
      },
      { status: 403 },
    );
  }

  const token = await readToken();
  const url = `${API_BASE}/${target}${request.nextUrl.search}`;

  const headers = new Headers({ Accept: "application/json" });
  if (token) headers.set("Authorization", `Bearer ${token}`);

  const requestId = request.headers.get("x-request-id");
  if (requestId) headers.set("X-Request-Id", requestId);

  const contentType = request.headers.get("content-type");
  if (contentType) headers.set("Content-Type", contentType);

  const hasBody = request.method !== "GET" && request.method !== "DELETE";

  let upstream: Response;

  try {
    upstream = await fetch(url, {
      method: request.method,
      headers,
      // Streamed so a media upload is not buffered in this process.
      body: hasBody ? request.body : undefined,
      ...(hasBody ? { duplex: "half" } : {}),
      cache: "no-store",
      redirect: "manual",
    } as RequestInit);
  } catch {
    return NextResponse.json(
      {
        error: {
          code: "NETWORK_ERROR",
          message: "Could not reach the server. Check your connection and try again.",
        },
      },
      { status: 502 },
    );
  }

  const body = await upstream.arrayBuffer();

  const responseHeaders = new Headers();
  for (const name of FORWARDED_RESPONSE_HEADERS) {
    const value = upstream.headers.get(name);
    if (value) responseHeaders.set(name, value);
  }

  responseHeaders.set("Cache-Control", "no-store");

  const response = new NextResponse(body, {
    status: upstream.status,
    headers: responseHeaders,
  });

  /*
   * A 401 means the token is dead — expired, revoked, or the account was
   * suspended mid-session. Dropping the cookie turns that into a clean
   * signed-out state instead of a UI retrying a credential that will never
   * work again.
   */
  if (upstream.status === 401 && token) {
    await clearSession();
  }

  return response;
}

export const GET = handler;
export const POST = handler;
export const PATCH = handler;
export const PUT = handler;
export const DELETE = handler;

export const dynamic = "force-dynamic";
