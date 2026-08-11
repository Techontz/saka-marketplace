"use client";

import { useMutation } from "@tanstack/react-query";
import { useState } from "react";
import { CheckCircle2, Loader2, X } from "lucide-react";

import { ApiError } from "@/lib/api/errors";
import { request } from "@/lib/api/http";
import type { ApiListing } from "@/lib/types";
import { useAuth } from "@/providers/AuthProvider";

/**
 * "Contact Seller".
 *
 * Works signed OUT as well as in — `POST /inquiries` is a public endpoint, and
 * requiring an account to ask a question is the fastest way to lose the
 * enquiry. Signing in is offered rather than demanded: it is the only way the
 * customer will see the reply in their inbox, so the form says so.
 *
 * Posted through the proxy so a signed-in sender is attributed to their
 * account, which is what puts the message in their inquiry history.
 */
export function InquiryForm({
  listing,
  open,
  onClose,
}: {
  listing: ApiListing;
  open: boolean;
  onClose: () => void;
}) {
  const { user, isAuthenticated } = useAuth();

  const [form, setForm] = useState({
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    message: "",
  });

  const send = useMutation({
    mutationFn: () =>
      request("/api/saka/inquiries", {
        method: "POST",
        body: {
          listing_slug: listing.slug,
          first_name: form.first_name || user?.first_name,
          last_name: form.last_name || user?.last_name,
          email: form.email || user?.email,
          phone: form.phone || user?.phone,
          message: form.message,
        },
      }),
  });

  if (!open) return null;

  const fieldError = (name: string) =>
    send.error instanceof ApiError ? send.error.fieldError(name) : undefined;

  return (
    <div
      className="fixed inset-0 z-[100] flex items-center justify-center bg-navy/40 px-4 backdrop-blur-sm"
      role="dialog"
      aria-modal="true"
      aria-label="Contact the seller"
      onClick={(event) => event.target === event.currentTarget && onClose()}
    >
      <div className="relative w-full max-w-lg rounded-2xl bg-white p-8 shadow-2xl animate-scale-in-soft">
        <button
          onClick={onClose}
          aria-label="Close"
          className="absolute right-5 top-5 text-muted-foreground transition hover:text-navy"
        >
          <X className="h-5 w-5" />
        </button>

        {send.isSuccess ? (
          <div className="py-6 text-center">
            <CheckCircle2 className="mx-auto h-12 w-12 text-teal" />
            <h2 className="mt-4 text-xl font-extrabold text-navy">Message sent</h2>
            <p className="mt-2 text-sm text-muted-foreground">
              {isAuthenticated
                ? "You'll find the reply in your inquiries, and we'll notify you when it arrives."
                : "The seller will reply to the email address you gave. Create an account to keep track of your messages."}
            </p>
            <button
              onClick={onClose}
              className="mt-6 rounded-full bg-teal px-6 py-2 font-semibold text-white transition hover:opacity-90"
            >
              Done
            </button>
          </div>
        ) : (
          <>
            <h2 className="text-xl font-extrabold text-navy">Contact the seller</h2>
            <p className="mt-1 mb-5 text-sm text-muted-foreground">About: {listing.title}</p>

            <form
              onSubmit={(event) => {
                event.preventDefault();
                send.mutate();
              }}
              className="space-y-4"
            >
              {!isAuthenticated && (
                <div className="grid grid-cols-2 gap-3">
                  <Field label="First name" error={fieldError("first_name")}>
                    <input
                      required
                      value={form.first_name}
                      onChange={(event) => setForm({ ...form, first_name: event.target.value })}
                      className={inputClass}
                    />
                  </Field>
                  <Field label="Last name" error={fieldError("last_name")}>
                    <input
                      value={form.last_name}
                      onChange={(event) => setForm({ ...form, last_name: event.target.value })}
                      className={inputClass}
                    />
                  </Field>
                </div>
              )}

              {!isAuthenticated && (
                <>
                  <Field label="Email" error={fieldError("email")}>
                    <input
                      required
                      type="email"
                      value={form.email}
                      onChange={(event) => setForm({ ...form, email: event.target.value })}
                      className={inputClass}
                    />
                  </Field>
                  <Field label="Phone" hint="Optional, but sellers often call back faster.">
                    <input
                      type="tel"
                      value={form.phone}
                      onChange={(event) => setForm({ ...form, phone: event.target.value })}
                      placeholder="+255…"
                      className={inputClass}
                    />
                  </Field>
                </>
              )}

              {isAuthenticated && (
                <p className="rounded-lg bg-teal/5 px-3 py-2 text-sm text-muted-foreground">
                  Sending as <span className="font-semibold text-navy">{user?.full_name}</span> ·{" "}
                  {user?.email}
                </p>
              )}

              <Field label="Message" error={fieldError("message")}>
                <textarea
                  required
                  rows={5}
                  minLength={10}
                  value={form.message}
                  onChange={(event) => setForm({ ...form, message: event.target.value })}
                  placeholder="Is this still available? Could I arrange a viewing this week?"
                  className={inputClass}
                />
              </Field>

              {send.error && (
                <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">
                  {send.error instanceof ApiError && send.error.isValidation
                    ? (send.error.allFieldMessages()[0] ?? send.error.message)
                    : (send.error as Error).message}
                </p>
              )}

              <button
                type="submit"
                disabled={send.isPending}
                className="inline-flex w-full items-center justify-center gap-2 rounded-full bg-teal px-6 py-3 font-semibold text-white transition hover:opacity-90 disabled:opacity-60"
              >
                {send.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                Send message
              </button>
            </form>
          </>
        )}
      </div>
    </div>
  );
}

const inputClass =
  "w-full rounded-lg border border-border px-3 py-2 text-navy outline-none transition focus:border-teal focus:ring-1 focus:ring-teal";

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
