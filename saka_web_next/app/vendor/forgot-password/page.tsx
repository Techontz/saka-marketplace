"use client";

import Link from "next/link";
import { useState } from "react";

import { AuthShell } from "@/components/vendor/layout/AuthShell";
import { authRequest } from "@/lib/vendor/api/browser";
import { Button, Field, FormError, Input } from "@/components/vendor/ui";

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (submitting) return;

    setSubmitting(true);
    setError(null);

    try {
      await authRequest("forgot-password", { email });
      setSent(true);
    } catch (cause) {
      setError(cause);
    } finally {
      setSubmitting(false);
    }
  };

  if (sent) {
    return (
      <AuthShell
        title="Check your email"
        /*
         * Deliberately does NOT confirm whether the address exists. The API
         * responds identically either way, and saying "no account found" here
         * would turn this page into a way to enumerate staff email addresses.
         */
        description="If that address belongs to an account, a reset link is on its way. The link expires in 60 minutes."
        footer={
          <Link href="/vendor/login" className="inline-flex min-h-11 items-center font-medium text-brand hover:underline">
            Back to sign in
          </Link>
        }
      >
        <p className="text-sm text-ink-soft">
          Didn&apos;t get it? Check your spam folder, then try again.
        </p>
      </AuthShell>
    );
  }

  return (
    <AuthShell
      title="Reset your password"
      description="We'll email you a link to set a new one."
      footer={
        <Link href="/vendor/login" className="inline-flex min-h-11 items-center font-medium text-brand hover:underline">
          Back to sign in
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

        <FormError error={error} />

        <Button type="submit" variant="primary" loading={submitting} className="w-full">
          Send reset link
        </Button>
      </form>
    </AuthShell>
  );
}
