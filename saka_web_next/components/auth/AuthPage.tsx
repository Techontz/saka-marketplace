"use client";

import Link from "next/link";
import { Logo } from "@/components/ui/Logo";
import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { ArrowUpRight, Loader2 } from "lucide-react";

import { ApiError } from "@/lib/api/errors";
import { authRequest } from "@/lib/api/browser";
import { useAuth } from "@/providers/AuthProvider";

/**
 * The standalone auth pages.
 *
 * The dialog covers mid-browse sign-in; these exist because emailed password
 * resets, bookmarks and the account guard all need a real URL to land on.
 * `?next=` is honoured so the guard returns people to where they were going.
 */

const inputClass =
  "w-full rounded-lg border border-border px-3 py-2.5 text-navy outline-none transition focus:border-teal focus:ring-1 focus:ring-teal";

export function AuthPage({ mode }: { mode: "login" | "register" | "forgot" | "reset" }) {
  const { login, register } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();

  const [form, setForm] = useState({
    first_name: "",
    last_name: "",
    email: searchParams.get("email") ?? "",
    phone: "",
    password: "",
    password_confirmation: "",
    remember: true,
  });

  const [pending, setPending] = useState(false);
  const [error, setError] = useState<Error | null>(null);
  const [done, setDone] = useState(false);

  const next = searchParams.get("next") ?? "/account";
  const token = searchParams.get("token") ?? "";

  const bind = (name: keyof typeof form) => ({
    value: String(form[name] ?? ""),
    onChange: (event: React.ChangeEvent<HTMLInputElement>) =>
      setForm({ ...form, [name]: event.target.value }),
  });

  const fieldError = (name: string) =>
    error instanceof ApiError ? error.fieldError(name) : undefined;

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setPending(true);
    setError(null);

    try {
      if (mode === "login") {
        await login({ email: form.email, password: form.password, remember: form.remember });
        router.replace(next);
      } else if (mode === "register") {
        await register({
          first_name: form.first_name,
          last_name: form.last_name || undefined,
          email: form.email,
          phone: form.phone || undefined,
          password: form.password,
          password_confirmation: form.password_confirmation,
        });
        router.replace(next);
      } else if (mode === "forgot") {
        await authRequest("forgot-password", { email: form.email });
        setDone(true);
      } else {
        await authRequest("reset-password", {
          token,
          email: form.email,
          password: form.password,
          password_confirmation: form.password_confirmation,
        });
        setDone(true);
      }
    } catch (cause) {
      setError(cause as Error);
    } finally {
      setPending(false);
    }
  };

  const titles = {
    login: "Welcome back",
    register: "Create your account",
    forgot: "Reset your password",
    reset: "Choose a new password",
  } as const;

  const blurbs = {
    login: "Sign in to save listings, message sellers and track your inquiries.",
    register: "Browsing is free and always will be. An account lets you save and message.",
    forgot: "Enter your email and we'll send you a link to set a new password.",
    reset: "Pick something you haven't used elsewhere.",
  } as const;

  return (
    <div className="mx-auto flex min-h-[70vh] max-w-md flex-col justify-center px-6 py-16">
      <div className="rounded-2xl border border-border bg-white p-8 shadow-sm">
        <Link href="/" aria-label="SAKA home" className="inline-flex">
          <Logo size="lg" priority />
        </Link>

        <h1 className="mt-5 text-2xl font-extrabold text-navy">{titles[mode]}</h1>
        <p className="mt-1 text-sm text-muted-foreground">{blurbs[mode]}</p>

        {done ? (
          <div className="mt-6">
            <p className="rounded-lg bg-teal/10 px-4 py-3 text-sm text-navy">
              {mode === "forgot"
                ? `If an account exists for ${form.email}, a reset link is on its way.`
                : "Your password has been changed. You can sign in now."}
            </p>
            <Link
              href="/login"
              className="mt-4 inline-flex w-full items-center justify-center rounded-full bg-teal px-6 py-2.5 font-semibold text-white"
            >
              Go to sign in
            </Link>
          </div>
        ) : (
          <form onSubmit={submit} className="mt-6 space-y-4">
            {mode === "register" && (
              <div className="grid grid-cols-2 gap-3">
                <Field label="First name" error={fieldError("first_name")}>
                  <input {...bind("first_name")} required autoFocus className={inputClass} />
                </Field>
                <Field label="Last name" error={fieldError("last_name")}>
                  <input {...bind("last_name")} className={inputClass} />
                </Field>
              </div>
            )}

            <Field label="Email" error={fieldError("email")}>
              <input
                {...bind("email")}
                type="email"
                required
                autoComplete="email"
                readOnly={mode === "reset" && Boolean(searchParams.get("email"))}
                className={inputClass}
              />
            </Field>

            {mode === "register" && (
              <Field label="Phone" hint="Optional." error={fieldError("phone")}>
                <input {...bind("phone")} type="tel" placeholder="+255…" className={inputClass} />
              </Field>
            )}

            {mode !== "forgot" && (
              <Field
                label={mode === "reset" ? "New password" : "Password"}
                error={fieldError("password")}
              >
                <input
                  {...bind("password")}
                  type="password"
                  required
                  autoComplete={mode === "login" ? "current-password" : "new-password"}
                  className={inputClass}
                />
              </Field>
            )}

            {(mode === "register" || mode === "reset") && (
              <Field label="Confirm password">
                <input
                  {...bind("password_confirmation")}
                  type="password"
                  required
                  autoComplete="new-password"
                  className={inputClass}
                />
              </Field>
            )}

            {mode === "login" && (
              <div className="flex items-center justify-between">
                <label className="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-muted-foreground">
                  <input
                    type="checkbox"
                    checked={form.remember}
                    onChange={(event) => setForm({ ...form, remember: event.target.checked })}
                    className="h-4 w-4 accent-teal"
                  />
                  Keep me signed in
                </label>
                <Link
                  href="/forgot-password"
                  className="flex min-h-11 items-center text-sm font-semibold text-teal hover:underline"
                >
                  Forgot password?
                </Link>
              </div>
            )}

            {error && (
              <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">
                {error instanceof ApiError && error.isValidation
                  ? (error.allFieldMessages()[0] ?? error.message)
                  : error.message}
              </p>
            )}

            <button
              type="submit"
              disabled={pending}
              className="inline-flex w-full items-center justify-center gap-2 rounded-full bg-teal pl-6 pr-2 py-2.5 font-semibold text-white transition hover:opacity-90 disabled:opacity-60"
            >
              {pending && <Loader2 className="h-4 w-4 animate-spin" />}
              {mode === "login"
                ? "Login Now"
                : mode === "register"
                  ? "Create account"
                  : mode === "forgot"
                    ? "Send reset link"
                    : "Set new password"}
              <span className="ml-1 flex h-8 w-8 items-center justify-center rounded-full bg-white text-teal">
                <ArrowUpRight className="h-4 w-4" />
              </span>
            </button>
          </form>
        )}

        {mode === "login" && (
          <p className="mt-6 text-center text-sm text-muted-foreground">
            New to SAKA?{" "}
            <Link href="/register" className="inline-flex min-h-11 items-center font-semibold text-teal hover:underline">
              Create an account
            </Link>
          </p>
        )}

        {mode === "register" && (
          <p className="mt-6 text-center text-sm text-muted-foreground">
            Already have an account?{" "}
            <Link href="/login" className="inline-flex min-h-11 items-center font-semibold text-teal hover:underline">
              Sign in
            </Link>
          </p>
        )}
      </div>
    </div>
  );
}

function Field({
  label,
  hint,
  error,
  children,
}: {
  label: string;
  hint?: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-sm font-semibold text-navy">{label}</span>
      {children}
      {hint && !error && <span className="mt-1 block text-xs text-muted-foreground">{hint}</span>}
      {error && <span className="mt-1 block text-xs text-destructive">{error}</span>}
    </label>
  );
}
