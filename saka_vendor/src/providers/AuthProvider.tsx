"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { createContext, useContext, useMemo, type ReactNode } from "react";

import { authRequest } from "@/lib/api/browser";
import type { SessionUser } from "@/lib/api/types";

/**
 * Who is signed in, for the whole portal.
 *
 * The session lives in an httpOnly cookie the browser cannot read, so "am I
 * signed in?" is answered by asking `/api/auth/session`, which forwards the
 * cookie to `GET /auth/me`.
 */

type SessionResponse = { data: { user: SessionUser | null } };

export type RegisterInput = {
  first_name: string;
  last_name?: string;
  email: string;
  phone?: string;
  password: string;
  password_confirmation: string;
};

type AuthContextValue = {
  user: SessionUser | null;
  isLoading: boolean;
  isAuthenticated: boolean;
  /**
   * Whether this vendor may publish.
   *
   * Publishing requires a verified phone (a platform-wide rule, not a portal
   * one). Surfaced here because half the portal's UI depends on it — the
   * publish button, the onboarding banner, the empty states.
   */
  canPublish: boolean;
  login: (input: { email: string; password: string; remember: boolean }) => Promise<SessionUser>;
  register: (input: RegisterInput) => Promise<SessionUser>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
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
    retry: false,
    staleTime: 5 * 60 * 1000,
    // A vendor who verifies their phone on another device should see the
    // publish button unlock when they come back to this tab.
    refetchOnWindowFocus: true,
  });

  const afterSignIn = async (user: SessionUser | null) => {
    queryClient.setQueryData(SESSION_QUERY_KEY, user);
    await queryClient.resetQueries({ predicate: (q) => q.queryKey[0] !== "auth" });
  };

  const loginMutation = useMutation({
    mutationFn: async (input: { email: string; password: string; remember: boolean }) => {
      const result = await authRequest<SessionResponse>("login", input);
      return result.data.user;
    },
    onSuccess: afterSignIn,
  });

  const registerMutation = useMutation({
    mutationFn: async (input: RegisterInput) => {
      const result = await authRequest<SessionResponse>("register", input);
      return result.data.user;
    },
    onSuccess: afterSignIn,
  });

  const logoutMutation = useMutation({
    mutationFn: async () => {
      await authRequest("logout");
    },
    // onSettled, not onSuccess: the cookie is dropped locally regardless.
    onSettled: async () => {
      queryClient.setQueryData(SESSION_QUERY_KEY, null);
      await queryClient.resetQueries({ predicate: (q) => q.queryKey[0] !== "auth" });
      router.replace("/login");
    },
  });

  const user = session.data ?? null;

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      isLoading: session.isPending,
      isAuthenticated: Boolean(user),
      canPublish: user?.can_publish_listings ?? false,
      login: async (input) => {
        const signedIn = await loginMutation.mutateAsync(input);
        if (!signedIn) throw new Error("Signed in, but the server returned no user.");
        return signedIn;
      },
      register: async (input) => {
        const created = await registerMutation.mutateAsync(input);
        if (!created) throw new Error("Registered, but the server returned no user.");
        return created;
      },
      logout: async () => {
        await logoutMutation.mutateAsync();
      },
      refresh: async () => {
        await queryClient.invalidateQueries({ queryKey: SESSION_QUERY_KEY });
      },
    }),
    [user, session.isPending, loginMutation, registerMutation, logoutMutation, queryClient],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) throw new Error("useAuth must be used inside <AuthProvider>.");
  return context;
}
