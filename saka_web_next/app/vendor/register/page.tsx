"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { AuthShell } from "@/components/vendor/layout/AuthShell";
import { Button, Field, FormError, Input } from "@/components/vendor/ui";
import { useAuth } from "@/providers/vendor/AuthProvider";

/**
 * Vendor registration.
 *
 * Deliberately short: name, email, phone, password. Everything about the
 * BUSINESS is collected by onboarding, after the account exists.
 *
 * Asking a landlord for their TIN and opening hours before they have an account
 * is how sign-up forms get abandoned — and most of those fields depend on the
 * business type, which is itself the first onboarding question.
 *
 * The phone is optional here but requested up front, because publishing needs a
 * verified number and collecting it now saves a second trip later.
 */
export default function RegisterPage() {
  const { register } = useAuth();
  const router = useRouter();

  const [firstName, setFirstName] = useState("");
  const [lastName, setLastName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
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
      await register({
        first_name: firstName,
        last_name: lastName || undefined,
        email,
        phone: phone || undefined,
        password,
        password_confirmation: confirmation,
      });

      // Registration signs them in, so go straight to onboarding rather than
      // back to a sign-in page they just came from.
      router.replace("/vendor/onboarding");
    } catch (cause) {
      setError(cause);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthShell
      title="Create a business account"
      description="Takes a minute. You'll set up your business next."
      footer={
        <>
          Already have an account?{" "}
          <Link href="/vendor/login" className="inline-flex min-h-11 items-center font-medium text-brand hover:underline">
            Sign in
          </Link>
        </>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="First name" required>
            <Input
              value={firstName}
              onChange={(event) => setFirstName(event.target.value)}
              autoComplete="given-name"
              required
              autoFocus
            />
          </Field>
          <Field label="Last name">
            <Input
              value={lastName}
              onChange={(event) => setLastName(event.target.value)}
              autoComplete="family-name"
            />
          </Field>
        </div>

        <Field label="Email" required>
          <Input
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            autoComplete="email"
            required
          />
        </Field>

        <Field
          label="Phone"
          hint="You'll need a verified number before you can publish."
        >
          <Input
            type="tel"
            value={phone}
            onChange={(event) => setPhone(event.target.value)}
            autoComplete="tel"
            placeholder="+255…"
          />
        </Field>

        <Field label="Password" hint="At least 8 characters." required>
          <Input
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            autoComplete="new-password"
            required
          />
        </Field>

        <Field label="Confirm password" required>
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
          Create account
        </Button>
      </form>
    </AuthShell>
  );
}
