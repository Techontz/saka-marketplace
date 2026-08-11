"use client";

import { useMutation } from "@tanstack/react-query";
import { useState } from "react";
import { ArrowUpRight, Loader2, Mail, MapPin, Phone } from "lucide-react";

import { ApiError } from "@/lib/api/errors";
import { request } from "@/lib/api/http";

/**
 * The contact page, ported.
 *
 * The original set a `sent` flag and did nothing else — the message went
 * nowhere. It now posts to `/inquiries` with no listing attached, which is the
 * standalone-contact path the endpoint was built for.
 */
export function ContactForm({
  email,
  phone,
  address,
}: {
  email: string;
  phone: string;
  address: string;
}) {
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
          first_name: form.first_name,
          last_name: form.last_name || undefined,
          email: form.email,
          phone: form.phone || undefined,
          message: form.message,
        },
      }),
  });

  const bind = (name: keyof typeof form) => ({
    value: form[name],
    onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
      setForm({ ...form, [name]: event.target.value }),
  });

  return (
    <section className="bg-page py-20">
      <div className="mx-auto max-w-7xl px-6 grid grid-cols-1 lg:grid-cols-2 gap-12">
        <div>
          <h1 className="text-4xl md:text-5xl font-extrabold text-navy">Get in touch</h1>
          <p className="mt-4 text-muted-foreground">
            Have a question about a property or want to list your own? We&apos;d love to hear from
            you.
          </p>

          <div className="mt-10 space-y-6">
            {[
              { icon: Mail, label: "Email", value: email },
              { icon: Phone, label: "Phone", value: phone },
              { icon: MapPin, label: "Office", value: address },
            ].map((item) => (
              <div key={item.label} className="flex items-start gap-4">
                <div className="h-11 w-11 rounded-full bg-teal/10 flex items-center justify-center text-teal">
                  <item.icon className="h-5 w-5" />
                </div>
                <div>
                  <div className="font-bold text-navy">{item.label}</div>
                  <div className="text-muted-foreground">{item.value}</div>
                </div>
              </div>
            ))}
          </div>
        </div>

        <form
          onSubmit={(event) => {
            event.preventDefault();
            send.mutate();
          }}
          className="bg-white rounded-3xl border border-border p-8 space-y-4"
        >
          <div className="grid grid-cols-2 gap-4">
            <input
              required
              placeholder="First name"
              {...bind("first_name")}
              className="px-4 py-3 rounded-xl border border-border bg-page outline-none focus:border-teal"
            />
            <input
              required
              placeholder="Last name"
              {...bind("last_name")}
              className="px-4 py-3 rounded-xl border border-border bg-page outline-none focus:border-teal"
            />
          </div>

          <input
            required
            type="email"
            placeholder="Email"
            {...bind("email")}
            className="w-full px-4 py-3 rounded-xl border border-border bg-page outline-none focus:border-teal"
          />
          <input
            placeholder="Phone"
            {...bind("phone")}
            className="w-full px-4 py-3 rounded-xl border border-border bg-page outline-none focus:border-teal"
          />
          <textarea
            required
            placeholder="Message"
            rows={5}
            minLength={10}
            {...bind("message")}
            className="w-full px-4 py-3 rounded-xl border border-border bg-page outline-none focus:border-teal"
          />

          {send.error && (
            <p className="rounded-xl bg-destructive/10 px-4 py-3 text-sm text-destructive">
              {send.error instanceof ApiError
                ? (send.error.allFieldMessages()[0] ?? send.error.message)
                : "That didn't send. Please try again."}
            </p>
          )}

          <button
            disabled={send.isPending || send.isSuccess}
            className="w-full inline-flex items-center justify-center gap-2 rounded-full bg-teal px-6 py-3 text-white font-semibold disabled:opacity-70"
          >
            {send.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
            {send.isSuccess ? "Message sent ✓" : "Send message"}
            {!send.isPending && <ArrowUpRight className="h-4 w-4" />}
          </button>

          {send.isSuccess && (
            <p className="text-center text-sm text-muted-foreground">
              Thanks — we&apos;ll reply to {form.email}.
            </p>
          )}
        </form>
      </div>
    </section>
  );
}
