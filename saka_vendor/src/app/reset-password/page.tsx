"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { AuthShell } from "@/components/layout/AuthShell";
import { authRequest } from "@/lib/api/browser";
import { Button, Field, FormError, Input } from "@/components/ui";

export default function ResetPasswordPage() {
  return (
    <Suspense fallback={null}>
      <ResetPasswordForm />
    </Suspense>
  );
}

function ResetPasswordForm() {
  const searchParams = useSearchParams();
  const router = useRouter();

  // Both arrive in the emailed link.
  const token = searchParams.get("token") ?? "";
  const email = searchParams.get("email") ?? "";

  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (submitting) return;

    setSubmitting(true);
    setError(null);

    try {
      await authRequest("reset-password", {
        token,
        email,
        password,
        password_confirmation: confirmation,
      });

      // Not signed in automatically. The reset proves control of the mailbox,
      // not of the password just chosen — and a fresh sign-in confirms it was
      // typed correctly before the old one stops working.
      router.replace("/login?reset=1");
    } catch (cause) {
      setError(cause);
    } finally {
      setSubmitting(false);
    }
  };

  if (!token || !email) {
    return (
      <AuthShell
        title="This link is incomplete"
        description="Reset links expire after 60 minutes and can only be used once. Request a new one."
        footer={
          <Link href="/forgot-password" className="font-medium text-brand hover:underline">
            Request a new link
          </Link>
        }
      >
        <p className="text-sm text-ink-soft">
          Make sure you opened the most recent email and copied the whole link.
        </p>
      </AuthShell>
    );
  }

  return (
    <AuthShell title="Choose a new password" description={`For ${email}`}>
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        <Field label="New password" hint="At least 8 characters." required>
          <Input
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            autoComplete="new-password"
            required
            autoFocus
          />
        </Field>

        <Field label="Confirm new password" required>
          <Input
            type="password"
            value={confirmation}
            onChange={(event) => setConfirmation(event.target.value)}
            autoComplete="new-password"
            required
          />
        </Field>

        <FormError error={error} />

        <Button type="submit" variant="primary" loading={submitting} className="w-full">
          Set new password
        </Button>
      </form>
    </AuthShell>
  );
}
