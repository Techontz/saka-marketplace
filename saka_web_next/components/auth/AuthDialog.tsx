"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Loader2, Lock, Mail, User, X } from "lucide-react";

import { ApiError } from "@/lib/api/errors";
import { useAuth } from "@/providers/AuthProvider";
import { Logo } from "@/components/ui/Logo";

/**
 * Sign in or create an account, without leaving the page.
 *
 * The chrome is the original `LoginDialog`, class for class: the glass panel at
 * 440px with its 20px radius and 80px shadow, the blurred navy scrim, the
 * mount transition, the segmented Login/Sign up pill, the Google button, the OR
 * rule, the icon-prefixed fields with their teal focus ring, and the gradient
 * submit that lifts on hover.
 *
 * Only the data source changed. The original's form called
 * `onSubmit={(e) => e.preventDefault()}` and went nowhere; this one signs in
 * and registers against the Laravel API through `AuthProvider`, which puts the
 * token in an httpOnly cookie. Validation errors from the API surface under the
 * field they belong to, which is the one thing the design had no state for.
 */

function GoogleButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="w-full h-12 flex items-center justify-center gap-3 rounded-[5px] bg-white text-navy font-semibold border border-black/5 shadow-[0_1px_2px_rgba(0,0,0,0.06)] hover:shadow-[0_6px_18px_rgba(0,0,0,0.08)] hover:-translate-y-0.5 active:scale-[0.98] transition-all duration-200"
    >
      <svg className="h-5 w-5" viewBox="0 0 48 48" aria-hidden="true">
        <path
          fill="#FFC107"
          d="M43.6 20.5H42V20H24v8h11.3c-1.6 4.6-6 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C33.9 6.1 29.2 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.3-.4-3.5z"
        />
        <path
          fill="#FF3D00"
          d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C33.9 6.1 29.2 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"
        />
        <path
          fill="#4CAF50"
          d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2c-2 1.5-4.5 2.4-7.2 2.4-5.3 0-9.7-3.4-11.3-8L6.2 33C9.6 39.6 16.2 44 24 44z"
        />
        <path
          fill="#1976D2"
          d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.1-4.1 5.5l6.2 5.2C41.1 35.5 44 30.2 44 24c0-1.3-.1-2.3-.4-3.5z"
        />
      </svg>
      {label}
    </button>
  );
}

function Field({
  icon: Icon,
  error,
  ...props
}: React.InputHTMLAttributes<HTMLInputElement> & {
  icon: React.ComponentType<{ className?: string }>;
  error?: string;
}) {
  return (
    <div>
      <div className="relative group">
        <Icon className="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-navy/40 group-focus-within:text-teal transition-colors" />
        <input
          {...props}
          aria-invalid={error ? true : undefined}
          className={`w-full h-12 rounded-[5px] bg-white/70 backdrop-blur-md border pl-11 pr-4 text-sm text-navy placeholder:text-navy/40 outline-none focus:border-teal focus:bg-white focus:shadow-[0_0_0_4px_rgba(20,150,150,0.10)] transition-all duration-200 ${
            error ? "border-destructive" : "border-navy/10"
          }`}
        />
      </div>
      {error && <p className="mt-1.5 pl-1 text-xs text-destructive">{error}</p>}
    </div>
  );
}

export function AuthDialog({
  open,
  mode: initialMode,
  reason,
  onClose,
}: {
  open: boolean;
  mode: "login" | "register";
  reason?: string;
  onClose: () => void;
}) {
  const { login, register } = useAuth();

  const [mode, setMode] = useState<"login" | "register">(initialMode);
  const [mounted, setMounted] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<ApiError | Error | null>(null);
  const [form, setForm] = useState({ name: "", email: "", password: "" });

  useEffect(() => {
    // No reset branch is needed: the provider keys this component per open, so
    // a reopened dialog is a fresh mount with `mounted` already false.
    if (!open) return;

    // Enter animation on the next frame, exactly as the original.
    const raf = requestAnimationFrame(() => setMounted(true));
    const onKey = (event: KeyboardEvent) => event.key === "Escape" && onClose();
    document.addEventListener("keydown", onKey);

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    return () => {
      cancelAnimationFrame(raf);
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = previousOverflow;
    };
  }, [open, onClose]);

  if (!open) return null;

  const isLogin = mode === "login";

  const bind = (name: keyof typeof form) => ({
    value: form[name],
    onChange: (event: React.ChangeEvent<HTMLInputElement>) =>
      setForm((current) => ({ ...current, [name]: event.target.value })),
  });

  const fieldError = (name: string) =>
    error instanceof ApiError ? error.fieldError(name) : undefined;

  const generalError =
    error && !(error instanceof ApiError && error.isValidation)
      ? error.message
      : error instanceof ApiError && error.isValidation && !error.allFieldMessages().length
        ? error.message
        : null;

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setPending(true);
    setError(null);

    try {
      if (isLogin) {
        await login({ email: form.email, password: form.password, remember: true });
      } else {
        /*
         * The design asks for one "Full name" box; the API wants first and last
         * names separately. Splitting on the first space keeps the single field
         * the design specifies without inventing a second one — and a mononym
         * simply has no last name, which the API allows.
         */
        const trimmed = form.name.trim();
        const firstSpace = trimmed.indexOf(" ");

        await register({
          first_name: firstSpace === -1 ? trimmed : trimmed.slice(0, firstSpace),
          last_name: firstSpace === -1 ? undefined : trimmed.slice(firstSpace + 1).trim(),
          email: form.email,
          password: form.password,
          // The design has no confirmation box, so the value is echoed to
          // satisfy the API's `confirmed` rule rather than adding a field.
          password_confirmation: form.password,
        });
      }

      onClose();
    } catch (cause) {
      setError(cause as Error);
    } finally {
      setPending(false);
    }
  };

  return (
    <div
      onClick={(event) => event.target === event.currentTarget && onClose()}
      role="dialog"
      aria-modal="true"
      aria-label={isLogin ? "Sign in" : "Create an account"}
      className={`fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-6 bg-navy/50 backdrop-blur-md transition-opacity duration-300 ${
        mounted ? "opacity-100" : "opacity-0"
      }`}
    >
      <div
        className={`relative w-full max-w-[440px] max-h-[calc(100vh-2rem)] overflow-y-auto rounded-[20px] border border-white/40 bg-white/80 backdrop-blur-2xl shadow-[0_25px_80px_rgba(15,23,42,0.30)] transition-all duration-300 ${
          mounted ? "opacity-100 scale-100 translate-y-0" : "opacity-0 scale-95 translate-y-2"
        }`}
      >
        <button
          onClick={onClose}
          aria-label="Close"
          className="absolute right-4 top-4 z-10 flex h-9 w-9 items-center justify-center rounded-[5px] bg-white/60 text-navy/70 hover:bg-white hover:text-navy hover:rotate-90 transition-all duration-300"
        >
          <X className="h-4 w-4" />
        </button>

        <div className="px-7 pt-8 pb-7 sm:px-8">
          <div className="text-center mb-6">
            <Logo size="lg" className="mb-4" />
            <h2 className="text-[22px] font-bold text-navy leading-tight">
              {isLogin ? "Welcome back" : "Create your account"}
            </h2>
            <p className="text-navy/60 text-sm mt-1.5">
              {reason ??
                (isLogin
                  ? "Sign in to continue exploring SAKA."
                  : "Join SAKA to list, save and discover listings.")}
            </p>
          </div>

          <div className="flex items-center gap-1 p-1 rounded-[5px] bg-navy/5 mb-6">
            <button
              type="button"
              onClick={() => {
                setMode("login");
                setError(null);
              }}
              className={`flex-1 h-9 text-sm font-semibold rounded-[5px] transition-all duration-200 ${
                isLogin
                  ? "bg-white text-navy shadow-[0_2px_6px_rgba(0,0,0,0.06)]"
                  : "text-navy/60 hover:text-navy"
              }`}
            >
              Login
            </button>
            <button
              type="button"
              onClick={() => {
                setMode("register");
                setError(null);
              }}
              className={`flex-1 h-9 text-sm font-semibold rounded-[5px] transition-all duration-200 ${
                !isLogin
                  ? "bg-white text-navy shadow-[0_2px_6px_rgba(0,0,0,0.06)]"
                  : "text-navy/60 hover:text-navy"
              }`}
            >
              Sign up
            </button>
          </div>

          <GoogleButton
            label={isLogin ? "Continue with Google" : "Sign up with Google"}
            onClick={() =>
              setError(
                new Error(
                  "Google sign-in is not enabled on this deployment yet. Use your email and password.",
                ),
              )
            }
          />

          <div className="flex items-center gap-3 my-6">
            <div className="h-px flex-1 bg-navy/10" />
            <span className="text-[11px] text-navy/40 font-semibold tracking-widest">OR</span>
            <div className="h-px flex-1 bg-navy/10" />
          </div>

          <form onSubmit={submit} className="space-y-4">
            {!isLogin && (
              <Field
                {...bind("name")}
                icon={User}
                type="text"
                required
                autoComplete="name"
                placeholder="Full name"
                error={fieldError("first_name") ?? fieldError("last_name")}
              />
            )}

            <Field
              {...bind("email")}
              icon={Mail}
              type="email"
              required
              autoComplete="email"
              placeholder="you@example.com"
              error={fieldError("email")}
            />

            <Field
              {...bind("password")}
              icon={Lock}
              type="password"
              required
              autoComplete={isLogin ? "current-password" : "new-password"}
              placeholder="Password"
              error={fieldError("password")}
            />

            {isLogin && (
              <div className="flex justify-end -mt-1">
                <Link
                  href="/forgot-password"
                  onClick={onClose}
                  className="text-xs font-semibold text-teal hover:underline"
                >
                  Forgot password?
                </Link>
              </div>
            )}

            {generalError && (
              <p className="rounded-[5px] bg-destructive/10 px-3 py-2 text-sm text-destructive">
                {generalError}
              </p>
            )}

            <button
              type="submit"
              disabled={pending}
              className="w-full h-12 rounded-[5px] font-semibold text-white bg-gradient-to-r from-teal to-[oklch(0.62_0.13_200)] shadow-[0_10px_24px_-8px_rgba(20,150,150,0.55)] hover:shadow-[0_14px_30px_-8px_rgba(20,150,150,0.65)] hover:-translate-y-0.5 active:scale-[0.98] transition-all duration-200 disabled:opacity-60 disabled:hover:translate-y-0 inline-flex items-center justify-center gap-2"
            >
              {pending && <Loader2 className="h-4 w-4 animate-spin" />}
              {isLogin ? "Sign in" : "Create account"}
            </button>
          </form>

          <p className="text-center text-sm text-navy/60 mt-6">
            {isLogin ? "Don't have an account? " : "Already have an account? "}
            <button
              type="button"
              onClick={() => {
                setMode(isLogin ? "register" : "login");
                setError(null);
              }}
              className="font-semibold text-teal hover:underline"
            >
              {isLogin ? "Sign up" : "Sign in"}
            </button>
          </p>
        </div>
      </div>
    </div>
  );
}
