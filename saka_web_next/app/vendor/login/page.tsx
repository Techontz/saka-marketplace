"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";

import { AuthShell } from "@/components/vendor/layout/AuthShell";
import { Button, Checkbox, Field, FormError, Input } from "@/components/vendor/ui";
import { useAuth } from "@/providers/vendor/AuthProvider";

export default function LoginPage() {
  return (
    // useSearchParams needs a Suspense boundary or the whole route opts out of
    // static rendering.
    <Suspense fallback={null}>
      <LoginForm />
    </Suspense>
  );
}

function LoginForm() {
  const { login, isAuthenticated, isLoading } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [remember, setRemember] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<unknown>(null);

  /*
   * `next` is attacker-controlled, so only a same-origin absolute PATH is
   * followed. A full URL or a protocol-relative "//evil.com" falls back to the
   * dashboard. Without this check a login page is an open redirect, which is
   * a very effective phishing primitive precisely because the domain is real.
   */
  const nextParam = searchParams.get("next");
  const destination =
    nextParam &&
    /^\/(?!\/)[\w\-./?=&%]*$/.test(nextParam) &&
    // Must land INSIDE the vendor portal. The same-origin test above was
    // sufficient when this app owned its own subdomain; sharing saka.africa
    // with the storefront and the admin portal means "same origin" no longer
    // means "this app", and the fallback below is the vendor dashboard, not
    // the marketplace homepage.
    (nextParam === "/vendor" || nextParam.startsWith("/vendor/") || nextParam.startsWith("/vendor?"))
      ? nextParam
      : "/vendor";

  useEffect(() => {
    if (!isLoading && isAuthenticated) router.replace(destination);
  }, [isLoading, isAuthenticated, destination, router]);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (submitting) return;

    setSubmitting(true);
    setError(null);

    try {
      await login({ email, password, remember });
      router.replace(destination);
    } catch (cause) {
      setError(cause);
      setPassword("");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthShell
      title="Sign in"
      description="Manage your business on SAKA."
      footer={
        <div className="space-y-2">
          <p>
            <Link
              href="/vendor/forgot-password"
              className="inline-flex min-h-11 items-center font-medium text-brand hover:underline"
            >
              Forgot your password?
            </Link>
          </p>
          <p>
            New here?{" "}
            <Link
              href="/vendor/register"
              className="inline-flex min-h-11 items-center font-medium text-brand hover:underline"
            >
              Create a business account
            </Link>
          </p>
        </div>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        <Field label="Email" required>
          <Input
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            autoComplete="username"
            required
            autoFocus
          />
        </Field>

        <Field label="Password" required>
          <Input
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            autoComplete="current-password"
            required
          />
        </Field>

        <Checkbox
          label="Keep me signed in"
          checked={remember}
          onChange={(event) => setRemember(event.target.checked)}
        />

        <FormError error={error} />

        <Button type="submit" variant="primary" loading={submitting} className="w-full">
          Sign in
        </Button>
      </form>
    </AuthShell>
  );
}
