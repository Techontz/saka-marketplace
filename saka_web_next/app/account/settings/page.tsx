"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useRef, useState } from "react";
import { Loader2, Trash2, Upload, User } from "lucide-react";

import { ErrorState } from "@/components/ui/states";
import { apiGet, apiSend } from "@/lib/api/browser";
import { ApiError } from "@/lib/api/errors";
import { request } from "@/lib/api/http";
import type { SessionUser } from "@/lib/types";
import { SESSION_QUERY_KEY, useAuth } from "@/providers/AuthProvider";
import { SafeImage } from "@/components/ui/SafeImage";

/**
 * Profile, avatar, password, notification preferences and account closure.
 *
 * Closing an account asks for the password even though the visitor is already
 * signed in — it is irreversible from their side, and a borrowed session must
 * not be enough to destroy someone's account.
 */

type Preference = { key: string; enabled: boolean; default: boolean };

const PREFERENCE_LABELS: Record<string, { title: string; description: string }> = {
  favorite_alerts: {
    title: "Saved listing alerts",
    description: "Price changes and availability on listings you saved.",
  },
  inquiry_replies: {
    title: "Replies to your messages",
    description: "When a business answers an inquiry you sent.",
  },
  review_replies: {
    title: "Replies to your reviews",
    description: "When a business responds to something you wrote.",
  },
  listing_updates: {
    title: "Your own listings",
    description: "Moderation outcomes, if you also sell on SAKA.",
  },
};

export default function SettingsPage() {
  const { user, refresh, logout } = useAuth();
  const queryClient = useQueryClient();
  const router = useRouter();
  const fileRef = useRef<HTMLInputElement>(null);

  const [profile, setProfile] = useState({
    first_name: user?.first_name ?? "",
    last_name: user?.last_name ?? "",
    email: user?.email ?? "",
    phone: user?.phone ?? "",
  });

  /*
   * Re-seed the form from the SERVER's answer, not from what was typed.
   *
   * `useState` runs its initialiser once, so the form held the typed values
   * forever — including any the API had rejected or normalised. Combined with a
   * server that was silently dropping `phone`, that produced the exact symptom
   * reported: it looked saved, and it was gone after a reload.
   *
   * Re-seeding during render, keyed on the identity the session actually
   * carries, means the form always shows what is stored. Editing is unaffected
   * because `user` only changes when a save or a session refresh completes.
   */
  const identity = `${user?.uuid ?? ""}|${user?.first_name ?? ""}|${user?.last_name ?? ""}|${user?.email ?? ""}|${user?.phone ?? ""}`;
  const [seeded, setSeeded] = useState(identity);

  if (user && seeded !== identity) {
    setSeeded(identity);
    setProfile({
      first_name: user.first_name ?? "",
      last_name: user.last_name ?? "",
      email: user.email ?? "",
      phone: user.phone ?? "",
    });
  }

  const [passwords, setPasswords] = useState({
    current_password: "",
    password: "",
    password_confirmation: "",
  });

  const [deletePassword, setDeletePassword] = useState("");
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  const preferences = useQuery({
    queryKey: ["notification-preferences"],
    queryFn: () => apiGet<{ data: Preference[] }>("/account/notifications/preferences"),
  });

  const saveProfile = useMutation({
    mutationFn: () => apiSend<{ data: SessionUser }>("/account/profile", "PATCH", profile),
    onSuccess: async () => {
      await refresh();
    },
  });

  const savePassword = useMutation({
    mutationFn: () => apiSend("/account/password", "PATCH", passwords),
    onSuccess: () => setPasswords({ current_password: "", password: "", password_confirmation: "" }),
  });

  const uploadAvatar = useMutation({
    mutationFn: (file: File) => {
      const body = new FormData();
      body.append("avatar", file);
      return request("/api/saka/account/avatar", { method: "POST", body });
    },
    onSuccess: async () => {
      if (fileRef.current) fileRef.current.value = "";
      await queryClient.invalidateQueries({ queryKey: SESSION_QUERY_KEY });
      await refresh();
    },
  });

  const removeAvatar = useMutation({
    mutationFn: () => apiSend("/account/avatar", "DELETE"),
    onSuccess: () => refresh(),
  });

  const setPreference = useMutation({
    mutationFn: (patch: Record<string, boolean>) =>
      apiSend("/account/notifications/preferences", "PATCH", { preferences: patch }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["notification-preferences"] });
    },
  });

  const closeAccount = useMutation({
    mutationFn: () => apiSend("/account", "DELETE", { password: deletePassword }),
    onSuccess: async () => {
      await logout();
      router.replace("/");
    },
  });

  const fieldError = (mutation: { error: unknown }, name: string) =>
    mutation.error instanceof ApiError ? mutation.error.fieldError(name) : undefined;

  return (
    <>
      <h2 className="text-2xl font-extrabold text-navy">Settings</h2>
      <p className="mt-1 mb-6 text-muted-foreground">Your details, and what we tell you about.</p>

      <section className="rounded-xl border border-border bg-white p-6">
        <h2 className="mb-4 text-lg font-bold text-navy">Photo</h2>

        <div className="flex flex-wrap items-center gap-5">
          <span className="flex h-20 w-20 items-center justify-center overflow-hidden rounded-full bg-teal text-2xl font-bold text-white">
            {user?.avatar_url ? (
              <SafeImage
                src={user.avatar_url}
                alt=""
                className="h-full w-full object-cover"
                fallbackClassName="h-full w-full bg-teal text-white"
                fallback={<User className="h-8 w-8" />}
              />
            ) : (
              <User className="h-8 w-8" />
            )}
          </span>

          <div>
            <input
              ref={fileRef}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="hidden"
              onChange={(event) => {
                const file = event.target.files?.[0];
                if (file) uploadAvatar.mutate(file);
              }}
            />

            <div className="flex flex-wrap gap-2">
              <button
                onClick={() => fileRef.current?.click()}
                disabled={uploadAvatar.isPending}
                className="inline-flex items-center gap-2 rounded-full bg-teal px-5 py-2 text-sm font-semibold text-white disabled:opacity-60"
              >
                {uploadAvatar.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Upload className="h-4 w-4" />}
                Upload photo
              </button>

              {user?.avatar_url && (
                <button
                  onClick={() => removeAvatar.mutate()}
                  className="rounded-full border border-border px-5 py-2 text-sm font-semibold text-navy"
                >
                  Remove
                </button>
              )}
            </div>

            <p className="mt-2 text-xs text-muted-foreground">JPG, PNG or WebP, up to 5&nbsp;MB.</p>
            {uploadAvatar.error && (
              <p className="mt-1 text-xs text-destructive">
                {(uploadAvatar.error as ApiError).message}
              </p>
            )}
          </div>
        </div>
      </section>

      <section className="mt-6 rounded-xl border border-border bg-white p-6">
        <h2 className="mb-4 text-lg font-bold text-navy">Your details</h2>

        <form
          onSubmit={(event) => {
            event.preventDefault();
            saveProfile.mutate();
          }}
          className="space-y-4"
        >
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="First name" error={fieldError(saveProfile, "first_name")}>
              <input
                value={profile.first_name}
                onChange={(event) => setProfile({ ...profile, first_name: event.target.value })}
                className={inputClass}
              />
            </Field>
            <Field label="Last name" error={fieldError(saveProfile, "last_name")}>
              <input
                value={profile.last_name ?? ""}
                onChange={(event) => setProfile({ ...profile, last_name: event.target.value })}
                className={inputClass}
              />
            </Field>
          </div>

          <Field
            label="Email"
            hint={user?.email_verified ? undefined : "Changing this will need verifying again."}
            error={fieldError(saveProfile, "email")}
          >
            <input
              type="email"
              value={profile.email}
              onChange={(event) => setProfile({ ...profile, email: event.target.value })}
              className={inputClass}
            />
          </Field>

          <Field
            label="Phone"
            hint={
              user?.phone_verified && profile.phone !== (user.phone ?? "")
                ? "This number is verified. Changing it means verifying the new one before you can publish a listing."
                : undefined
            }
            error={fieldError(saveProfile, "phone")}
          >
            <input
              type="tel"
              value={profile.phone ?? ""}
              onChange={(event) => setProfile({ ...profile, phone: event.target.value })}
              placeholder="+255…"
              inputMode="tel"
              autoComplete="tel"
              className={inputClass}
            />
          </Field>

          <div className="flex items-center gap-3">
            <button
              type="submit"
              disabled={saveProfile.isPending}
              className="inline-flex items-center gap-2 rounded-full bg-teal px-5 py-2 text-sm font-semibold text-white disabled:opacity-60"
            >
              {saveProfile.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
              Save changes
            </button>
            {saveProfile.isSuccess && <span className="text-sm text-teal">Saved.</span>}
          </div>
        </form>
      </section>

      <section className="mt-6 rounded-xl border border-border bg-white p-6">
        <h2 className="mb-4 text-lg font-bold text-navy">Password</h2>

        <form
          onSubmit={(event) => {
            event.preventDefault();
            savePassword.mutate();
          }}
          className="space-y-4"
        >
          <Field label="Current password" error={fieldError(savePassword, "current_password")}>
            <input
              type="password"
              autoComplete="current-password"
              value={passwords.current_password}
              onChange={(event) => setPasswords({ ...passwords, current_password: event.target.value })}
              className={inputClass}
            />
          </Field>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="New password" error={fieldError(savePassword, "password")}>
              <input
                type="password"
                autoComplete="new-password"
                value={passwords.password}
                onChange={(event) => setPasswords({ ...passwords, password: event.target.value })}
                className={inputClass}
              />
            </Field>
            <Field label="Confirm new password">
              <input
                type="password"
                autoComplete="new-password"
                value={passwords.password_confirmation}
                onChange={(event) =>
                  setPasswords({ ...passwords, password_confirmation: event.target.value })
                }
                className={inputClass}
              />
            </Field>
          </div>

          <div className="flex items-center gap-3">
            <button
              type="submit"
              disabled={savePassword.isPending}
              className="inline-flex items-center gap-2 rounded-full bg-teal px-5 py-2 text-sm font-semibold text-white disabled:opacity-60"
            >
              {savePassword.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
              Update password
            </button>
            {savePassword.isSuccess && (
              <span className="text-sm text-teal">Updated. Other devices were signed out.</span>
            )}
          </div>
        </form>
      </section>

      <section id="notifications" className="mt-6 scroll-mt-24 rounded-xl border border-border bg-white p-6">
        <h2 className="mb-1 text-lg font-bold text-navy">What we tell you about</h2>
        <p className="mb-4 text-sm text-muted-foreground">
          Moderation outcomes on your own content are always sent — you would not otherwise know why
          something disappeared.
        </p>

        {preferences.isPending ? (
          <div className="h-24 animate-pulse rounded-lg bg-page" />
        ) : preferences.error ? (
          <ErrorState error={preferences.error} onRetry={() => void preferences.refetch()} />
        ) : (
          <ul className="space-y-3">
            {(preferences.data?.data ?? []).map((preference) => {
              const copy = PREFERENCE_LABELS[preference.key] ?? {
                title: preference.key,
                description: "",
              };

              return (
                <li key={preference.key} className="flex items-start justify-between gap-4">
                  <span>
                    <span className="block font-semibold text-navy">{copy.title}</span>
                    <span className="block text-sm text-muted-foreground">{copy.description}</span>
                  </span>

                  <label className="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input
                      type="checkbox"
                      className="peer sr-only"
                      checked={preference.enabled}
                      onChange={(event) =>
                        setPreference.mutate({ [preference.key]: event.target.checked })
                      }
                    />
                    <span className="h-6 w-11 rounded-full bg-border transition peer-checked:bg-teal" />
                    <span className="absolute left-1 h-4 w-4 rounded-full bg-white transition peer-checked:translate-x-5" />
                  </label>
                </li>
              );
            })}
          </ul>
        )}
      </section>

      <section className="mt-6 rounded-xl border border-destructive/30 bg-destructive/5 p-6">
        <h2 className="mb-1 text-lg font-bold text-navy">Close your account</h2>
        <p className="mb-4 text-sm text-muted-foreground">
          Your email and phone are released so you can sign up again later. Reviews you wrote stay on
          SAKA — other people rely on them — but they are no longer linked to a live account.
        </p>

        {confirmingDelete ? (
          <div className="space-y-3">
            <Field label="Confirm your password" error={fieldError(closeAccount, "password")}>
              <input
                type="password"
                value={deletePassword}
                onChange={(event) => setDeletePassword(event.target.value)}
                className={inputClass}
              />
            </Field>

            {closeAccount.error && (
              <p className="text-sm text-destructive">{(closeAccount.error as ApiError).message}</p>
            )}

            <div className="flex gap-2">
              <button
                onClick={() => closeAccount.mutate()}
                disabled={closeAccount.isPending || deletePassword.length === 0}
                className="inline-flex items-center gap-2 rounded-full bg-destructive px-5 py-2 text-sm font-semibold text-white disabled:opacity-60"
              >
                {closeAccount.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                Permanently close my account
              </button>
              <button
                onClick={() => setConfirmingDelete(false)}
                className="rounded-full border border-border bg-white px-5 py-2 text-sm font-semibold text-navy"
              >
                Cancel
              </button>
            </div>
          </div>
        ) : (
          <button
            onClick={() => setConfirmingDelete(true)}
            className="inline-flex items-center gap-2 rounded-full border border-destructive px-5 py-2 text-sm font-semibold text-destructive transition hover:bg-destructive hover:text-white"
          >
            <Trash2 className="h-4 w-4" />
            Close account
          </button>
        )}
      </section>
    </>
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
