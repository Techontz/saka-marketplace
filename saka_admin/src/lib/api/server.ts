import "server-only";

import { readToken } from "@/lib/auth/session";
import { API_BASE, buildQuery, request, type RequestOptions } from "./http";

/** Server-side API access for Server Components and Route Handlers. */
export type ServerFetchOptions = Omit<RequestOptions, "token">;

export async function serverGet<T>(path: string, options: ServerFetchOptions = {}): Promise<T> {
  const { query, ...rest } = options;
  const token = await readToken();

  return request<T>(`${API_BASE}${path}${buildQuery(query)}`, { ...rest, token });
}
