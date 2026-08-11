"use client";

import { createContext, useContext, useMemo, useState, type ReactNode } from "react";

import { AuthDialog } from "@/components/auth/AuthDialog";

/**
 * The sign-in dialog, reachable from anywhere.
 *
 * Saving a listing, sending an inquiry and writing a review all need an
 * account, and all three happen mid-browse. Sending someone to /login loses
 * the page they were on — and, worse, the thing they were about to do. A
 * dialog keeps them where they are.
 */

type AuthDialogContextValue = {
  open: (mode?: "login" | "register", reason?: string) => void;
  close: () => void;
};

const AuthDialogContext = createContext<AuthDialogContextValue | null>(null);

export function AuthDialogProvider({ children }: { children: ReactNode }) {
  /*
   * `instance` increments on every open and keys the dialog.
   *
   * The dialog stays mounted for the life of the app, so without this its
   * internal state survives a close: opening it in `register` mode after a
   * previous `login` would still show the login tab, and a half-typed email
   * would still be sitting in the box. Remounting also re-runs the enter
   * animation, which is what the original got for free by rendering `null`.
   */
  const [state, setState] = useState<{
    open: boolean;
    mode: "login" | "register";
    reason?: string;
    instance: number;
  }>({ open: false, mode: "login", instance: 0 });

  const value = useMemo<AuthDialogContextValue>(
    () => ({
      open: (mode = "login", reason) =>
        setState((current) => ({ open: true, mode, reason, instance: current.instance + 1 })),
      close: () => setState((current) => ({ ...current, open: false })),
    }),
    [],
  );

  return (
    <AuthDialogContext.Provider value={value}>
      {children}
      <AuthDialog
        key={state.instance}
        open={state.open}
        mode={state.mode}
        reason={state.reason}
        onClose={value.close}
      />
    </AuthDialogContext.Provider>
  );
}

export function useAuthDialog(): AuthDialogContextValue {
  const context = useContext(AuthDialogContext);
  if (!context) throw new Error("useAuthDialog must be used inside <AuthDialogProvider>.");
  return context;
}
