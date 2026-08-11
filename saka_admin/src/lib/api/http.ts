import { ApiError, errorFromResponse, networkError } from "./errors";

/**
 * The one place an HTTP request is made.
 *
 * Two callers sit on top: `server.ts` (Server Components and Route Handlers,
 * talking straight to Laravel with the bearer token from the session cookie)
 * and `browser.ts` (Client Components, talking to this app's own `/api/saka/*`
 * proxy, which attaches the token server-side).
 *
 * The browser therefore never holds the access token. That matters more here
 * than on the marketplace: an admin token can suspend accounts and delete
 * listings, so a token readable by any script on the page is a much larger
 * prize.
 */

export const API_ORIGIN = (process.env.SAKA_API_URL ?? "http://127.0.0.1:8000").replace(/\/$/, "");
export const API_BASE = `${API_ORIGIN}/api/v1`;

/**
 * A production build must never talk to a development API.
 *
 * This is not hypothetical. Next resolves env files as
 * `.env.local` > `.env.production` > `.env`, and `.env.local` is loaded in
 * EVERY environment except test — so a developer's `.env.local` pointing at
 * 127.0.0.1 silently overrides a correct `.env.production`, and the deployment
 * comes up serving an empty site with no error anywhere. That exact shadowing
 * was observed during the saka.africa cutover.
 *
 * Failing at module load turns a silent misconfiguration into a boot failure
 * the deploy sees immediately. Mirrors AppConfig.assertSafe() in the Flutter
 * client, so all four surfaces fail the same way for the same reason.
 */
// SERVER ONLY. `SAKA_API_URL` is deliberately not NEXT_PUBLIC_, so in the
// browser bundle it is `undefined` and API_ORIGIN falls back to the dev
// default — which is harmless, because the browser never uses API_ORIGIN at
// all (it calls this app's own /api/saka proxy). Running the guard client-side
// would therefore fail on a perfectly correct deployment.
if (typeof window === "undefined" && process.env.NODE_ENV === "production") {
  const isDevelopmentOrigin =
    /^https?:\/\/(localhost|127\.0\.0\.1|0\.0\.0\.0|10\.0\.2\.2)(:|$)/.test(
      API_ORIGIN,
    );

  if (isDevelopmentOrigin) {
    throw new Error(
      `SAKA_API_URL points at a development origin (${API_ORIGIN}) in a ` +
        "production build. A stale .env.local overrides .env.production — " +
        "remove it on the server, or supply SAKA_API_URL in the process " +
        "environment, which outranks every .env file.",
    );
  }

  // The origin must NOT carry the /api/v1 path: API_BASE appends it, and a
  // doubled prefix 404s every request while still returning HTTP 200 pages.
  if (/\/api(\/v\d+)?$/.test(API_ORIGIN)) {
    throw new Error(
      `SAKA_API_URL must be an ORIGIN with no path (got ${API_ORIGIN}). ` +
        "The client appends /api/v1 itself.",
    );
  }
}


export type QueryValue = string | number | boolean | null | undefined;

export type RequestOptions = {
  method?: "GET" | "POST" | "PATCH" | "PUT" | "DELETE";
  body?: unknown;
  query?: Record<string, QueryValue | QueryValue[]>;
  token?: string | null;
  headers?: Record<string, string>;
  signal?: AbortSignal;
  cache?: RequestCache;
};

/**
 * Arrays repeat the key with `[]`, which is what Laravel's parser expects.
 * Empty values are dropped so an untouched filter never narrows a query.
 */
export function buildQuery(query: Record<string, QueryValue | QueryValue[]> | undefined): string {
  if (!query) return "";

  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(query)) {
    if (value === null || value === undefined || value === "") continue;

    if (Array.isArray(value)) {
      for (const item of value) {
        if (item === null || item === undefined || item === "") continue;
        params.append(`${key}[]`, String(item));
      }
      continue;
    }

    params.append(key, String(value));
  }

  const serialised = params.toString();
  return serialised ? `?${serialised}` : "";
}

export async function request<T>(url: string, options: RequestOptions = {}): Promise<T> {
  const { method = "GET", body, token, headers = {}, signal, cache } = options;

  const isFormData = typeof FormData !== "undefined" && body instanceof FormData;

  const init: RequestInit = {
    method,
    signal,
    headers: {
      Accept: "application/json",
      ...(isFormData ? {} : body !== undefined ? { "Content-Type": "application/json" } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
  };

  if (body !== undefined) init.body = isFormData ? (body as FormData) : JSON.stringify(body);

  // Admin data is per-user and mutable; nothing here may be served from a
  // shared or stale cache.
  init.cache = cache ?? "no-store";

  let response: Response;

  try {
    response = await fetch(url, init);
  } catch (cause) {
    if (cause instanceof DOMException && cause.name === "AbortError") throw cause;
    throw networkError(cause);
  }

  if (!response.ok) throw await errorFromResponse(response);

  if (response.status === 204 || response.headers.get("Content-Length") === "0") {
    return undefined as T;
  }

  const text = await response.text();
  if (!text) return undefined as T;

  try {
    return JSON.parse(text) as T;
  } catch (cause) {
    throw new ApiError({
      status: response.status,
      code: "INVALID_RESPONSE",
      message: "The server returned a response this app could not read.",
      details: { cause: String(cause) },
      requestId: response.headers.get("X-Request-Id"),
    });
  }
}
