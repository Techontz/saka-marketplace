"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { createContext, useCallback, useContext, useMemo, type ReactNode } from "react";

import { authRequest } from "@/lib/admin/api/browser";
import type { SessionUser } from "@/lib/admin/api/types";

/**
 * Who is signed in, for the whole portal.
 *
 * The session lives in an httpOnly cookie the browser cannot read, so "am I
 * signed in?" is answered by asking `/api/auth/session`, which forwards the
 * cookie to `GET /auth/me`. React Query caches that one answer, so mounting
 * this costs one request per page load rather than one per consumer.
 */

type SessionResponse = { data: { user: SessionUser | null } };

type AuthContextValue = {
  user: SessionUser | null;
  isLoading: boolean;
  isAuthenticated: boolean;
  /** Role check. The API is still the authority; this only shapes the UI. */
  hasRole: (...roles: string[]) => boolean;
  /**
   * Permission check, from the caller's own `/auth/me` payload.
   *
   * UI-SHAPING ONLY. Hiding a button the operator cannot use is a courtesy;
   * the API enforces every permission on every request, and typing the URL
   * still reaches the endpoint, where it correctly 403s.
   */
  can: (...permissions: string[]) => boolean;
  isSuperAdmin: boolean;
  login: (input: { email: string; password: string; remember: boolean }) => Promise<SessionUser>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export const SESSION_QUERY_KEY = ["auth", "session"] as const;

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const router = useRouter();

  const session = useQuery({
    queryKey: SESSION_QUERY_KEY,
    queryFn: async () => {
      const result = await authRequest<SessionResponse>("session");
      return result.data.user;
    },
    // "Signed out" is an answer, never an error to retry.
    retry: false,
    staleTime: 5 * 60 * 1000,
    // A session can be revoked from another device, or the account suspended.
    refetchOnWindowFocus: true,
  });

  const loginMutation = useMutation({
    mutationFn: async (input: { email: string; password: string; remember: boolean }) => {
      const result = await authRequest<SessionResponse>("login", input);
      return result.data.user;
    },
    onSuccess: async (user) => {
      queryClient.setQueryData(SESSION_QUERY_KEY, user);
      // Everything the API returns is scoped to the caller, so a sign-in
      // invalidates all of it.
      await queryClient.resetQueries({ predicate: (q) => q.queryKey[0] !== "auth" });
    },
  });

  const logoutMutation = useMutation({
    mutationFn: async () => {
      await authRequest("logout");
    },
    // onSettled, not onSuccess: the cookie is dropped locally regardless, so
    // the UI must not keep claiming a session that no longer exists.
    onSettled: async () => {
      queryClient.setQueryData(SESSION_QUERY_KEY, null);
      await queryClient.resetQueries({ predicate: (q) => q.queryKey[0] !== "auth" });
      router.replace("/admin/login");
    },
  });

  const user = session.data ?? null;

  const hasRole = useCallback(
    (...roles: string[]) => roles.some((role) => user?.roles.includes(role) ?? false),
    [user],
  );

  const can = useCallback(
    (...permissions: string[]) =>
      permissions.length === 0 ||
      permissions.some((permission) => user?.permissions?.includes(permission) ?? false),
    [user],
  );

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      isLoading: session.isPending,
      isAuthenticated: Boolean(user),
      hasRole,
      can,
      isSuperAdmin: user?.roles.includes("super_admin") ?? false,
      login: async (input) => {
        const signedIn = await loginMutation.mutateAsync(input);
        if (!signedIn) throw new Error("Signed in, but the server returned no user.");
        return signedIn;
      },
      logout: async () => {
        await logoutMutation.mutateAsync();
      },
    }),
    [user, session.isPending, hasRole, can, loginMutation, logoutMutation],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) throw new Error("useAuth must be used inside <AuthProvider>.");
  return context;
}
