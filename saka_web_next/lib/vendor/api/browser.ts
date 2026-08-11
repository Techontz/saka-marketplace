import { buildQuery, request, type QueryValue, type RequestOptions } from "./http";

/**
 * Client-side API access, through this app's own proxy so the token stays in an
 * httpOnly cookie. See `src/app/api/saka/[...path]/route.ts`.
 */

const PROXY_BASE = "/vendor/api/saka";

export type BrowserRequestOptions = Omit<RequestOptions, "token" | "cache">;

export function apiGet<T>(
  path: string,
  query?: Record<string, QueryValue | QueryValue[]>,
  options: BrowserRequestOptions = {},
): Promise<T> {
  return request<T>(`${PROXY_BASE}${path}${buildQuery(query)}`, { ...options, method: "GET" });
}

export function apiSend<T>(
  path: string,
  method: "POST" | "PATCH" | "PUT" | "DELETE",
  body?: unknown,
): Promise<T> {
  return request<T>(`${PROXY_BASE}${path}`, { method, body });
}

/** Auth actions bypass the proxy — only those handlers may see a token. */
export function authRequest<T>(
  action: "login" | "register" | "logout" | "session" | "forgot-password" | "reset-password",
  body?: unknown,
): Promise<T> {
  return request<T>(`/vendor/api/auth/${action}`, {
    method: action === "session" ? "GET" : "POST",
    body,
  });
}
