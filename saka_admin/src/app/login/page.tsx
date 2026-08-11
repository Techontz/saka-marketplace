"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";

import { AuthShell } from "@/components/layout/AuthShell";
import { Button, Checkbox, Field, FormError, Input } from "@/components/ui";
import { useAuth } from "@/providers/AuthProvider";

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
  const destination = nextParam && /^\/(?!\/)[\w\-./?=&%]*$/.test(nextParam) ? nextParam : "/";

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
      description="Administration portal. Staff accounts only."
      footer={
        <Link
          href="/forgot-password"
          className="inline-flex min-h-11 items-center font-medium text-brand hover:underline"
        >
          Forgot your password?
        </Link>
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
