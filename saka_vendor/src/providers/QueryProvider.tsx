"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState, type ReactNode } from "react";

import { ApiError } from "@/lib/api/errors";

/**
 * The React Query client.
 *
 * Created inside useState so it is never shared across requests — a
 * module-level client on the server would leak one operator's cached data into
 * another's render.
 */
export function QueryProvider({ children }: { children: ReactNode }) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            /*
             * Retrying a 4xx re-sends a request the server rejected on its
             * merits: a 403 will not become a 200 on the third attempt, and a
             * 429 explicitly asks us to stop. Only transport failures and 5xx
             * are worth another go.
             */
            retry: (failureCount, error) =>
              error instanceof ApiError ? error.isRetryable && failureCount < 2 : failureCount < 2,
            retryDelay: (attempt) => Math.min(1000 * 2 ** attempt, 8000),
            // Short: an admin acting on data expects the list to reflect it.
            staleTime: 15 * 1000,
            refetchOnWindowFocus: false,
          },
          mutations: {
            // A mutation is not idempotent by default; a silent retry could
            // approve a listing twice or send two reset emails.
            retry: false,
          },
        },
      }),
  );

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}
