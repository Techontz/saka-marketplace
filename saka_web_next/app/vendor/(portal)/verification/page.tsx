"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CheckCircle2, Upload } from "lucide-react";
import { useRef, useState } from "react";

import {
  Badge,
  Button,
  Card,
  Field,
  FormError,
  Input,
  ListState,
  PageHeader,
  Select,
  humanise,
  statusTone,
} from "@/components/vendor/ui";
import { apiGet, apiSend } from "@/lib/vendor/api/browser";
import { request } from "@/lib/vendor/api/http";
import type { VendorVerification, VerificationsMeta } from "@/lib/vendor/api/types";
import { useAuth } from "@/providers/vendor/AuthProvider";
import { PROFILE_QUERY_KEY, useVendor } from "@/providers/vendor/VendorProvider";

type VerificationsResponse = {
  data: VendorVerification[];
  meta: VerificationsMeta;
};

/**
 * Verification.
 *
 * Two independent things share this screen because a vendor thinks of them as
 * one question — "am I allowed to sell yet?":
 *
 *   1. PHONE, which gates publishing. Platform-wide rule, not a portal one.
 *   2. DOCUMENTS, which earn the verified badge. Reviewed by a moderator, so
 *      the honest state here is "waiting", not "done".
 */
export default function VerificationPage() {
  const { user, canPublish, refresh } = useAuth();
  const { profile } = useVendor();

  return (
    <>
      <PageHeader
        title="Verification"
        description="Verify your phone to publish. Send documents to earn the verified badge."
      />

      <div className="grid gap-4 lg:grid-cols-2">
        <PhoneCard
          phone={user?.phone ?? null}
          verified={user?.phone_verified ?? false}
          canPublish={canPublish}
          onVerified={refresh}
        />

        <Card>
          <div className="border-b border-line px-5 py-3">
            <h2 className="text-sm font-semibold text-ink">Business verification</h2>
            <p className="text-xs text-ink-soft">Reviewed by our team, usually within a few days.</p>
          </div>

          <div className="px-5 py-5">
            {profile?.verification.is_verified ? (
              <div className="flex items-start gap-2">
                <CheckCircle2 aria-hidden className="mt-0.5 h-5 w-5 shrink-0 text-ok" />
                <div>
                  <p className="text-sm font-medium text-ink">Your business is verified.</p>
                  <p className="mt-0.5 text-sm text-ink-soft">
                    Level: {humanise(profile.verification.level)}
                    {profile.verification.verified_at &&
                      ` · since ${new Date(profile.verification.verified_at).toLocaleDateString()}`}
                  </p>
                </div>
              </div>
            ) : (
              <p className="text-sm text-ink-soft">
                Verified businesses appear with a badge on every listing, and buyers contact them
                more often. Send a document below to start.
              </p>
            )}

            {/*
              Email verification is listed in the portal spec but has no
              endpoint in the API — there is no send-verification-email or
              confirm route. Saying nothing would be the wrong call: a vendor
              who sees "phone verified" and no mention of email assumes email
              was checked too.
            */}
            <p className="mt-4 border-t border-line pt-4 text-xs text-ink-faint">
              Email addresses are not separately verified on SAKA today.
            </p>
          </div>
        </Card>
      </div>

      <div className="mt-4">
        <DocumentsCard />
      </div>
    </>
  );
}

// ------------------------------------------------------------------- phone

function PhoneCard({
  phone,
  verified,
  canPublish,
  onVerified,
}: {
  phone: string | null;
  verified: boolean;
  canPublish: boolean;
  onVerified: () => Promise<void>;
}) {
  const [number, setNumber] = useState(phone ?? "");
  const [code, setCode] = useState("");
  const [sent, setSent] = useState(false);
  const [resendAfter, setResendAfter] = useState<number | null>(null);

  const sendCode = useMutation({
    mutationFn: () =>
      apiSend<{ data: { resend_after_seconds: number } }>("/auth/phone/request-otp", "POST", {
        phone: number,
      }),
    onSuccess: (response) => {
      setSent(true);
      setResendAfter(response.data.resend_after_seconds);
    },
  });

  const verify = useMutation({
    mutationFn: () => apiSend("/auth/phone/verify-otp", "POST", { phone: number, code }),
    onSuccess: async () => {
      setCode("");
      setSent(false);
      // The session carries can_publish_listings; without this the publish
      // buttons stay disabled until the next full page load.
      await onVerified();
    },
  });

  return (
    <Card>
      <div className="border-b border-line px-5 py-3">
        <h2 className="text-sm font-semibold text-ink">Phone number</h2>
        <p className="text-xs text-ink-soft">Required before anything you list can go live.</p>
      </div>

      <div className="px-5 py-5">
        {verified ? (
          <div className="flex items-start gap-2">
            <CheckCircle2 aria-hidden className="mt-0.5 h-5 w-5 shrink-0 text-ok" />
            <div>
              <p className="text-sm font-medium text-ink">{phone} is verified.</p>
              <p className="mt-0.5 text-sm text-ink-soft">
                {canPublish
                  ? "You can publish."
                  : "Publishing is still restricted on this account — contact support."}
              </p>
            </div>
          </div>
        ) : (
          <div className="space-y-4">
            <Field
              label="Mobile number"
              hint="Tanzanian format, e.g. 0712 345 678 or +255 712 345 678."
            >
              <Input
                type="tel"
                inputMode="tel"
                value={number}
                onChange={(event) => setNumber(event.target.value)}
                placeholder="+255…"
                autoComplete="tel"
              />
            </Field>

            {sent && (
              <Field label="Verification code" hint="Six digits, sent by SMS.">
                <Input
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={6}
                  value={code}
                  onChange={(event) => setCode(event.target.value.replace(/\D/g, ""))}
                  autoFocus
                />
              </Field>
            )}

            <FormError error={sendCode.error ?? verify.error} />

            <div className="flex flex-wrap items-center gap-2">
              <Button
                variant={sent ? "secondary" : "primary"}
                loading={sendCode.isPending}
                disabled={number.trim().length < 9}
                onClick={() => sendCode.mutate()}
              >
                {sent ? "Send a new code" : "Send code"}
              </Button>

              {sent && (
                <Button
                  variant="primary"
                  loading={verify.isPending}
                  disabled={code.length < 4}
                  onClick={() => verify.mutate()}
                >
                  Verify
                </Button>
              )}
            </div>

            {sent && resendAfter !== null && (
              <p className="text-xs text-ink-faint">
                If it hasn&apos;t arrived, you can request another in {resendAfter} seconds.
              </p>
            )}
          </div>
        )}
      </div>
    </Card>
  );
}

// --------------------------------------------------------------- documents

function DocumentsCard() {
  const queryClient = useQueryClient();
  const fileRef = useRef<HTMLInputElement>(null);

  const [type, setType] = useState("");
  const [documentNumber, setDocumentNumber] = useState("");
  const [file, setFile] = useState<File | null>(null);

  const query = useQuery({
    queryKey: ["vendor-verifications"],
    queryFn: () => apiGet<VerificationsResponse>("/seller/verifications"),
  });

  const submit = useMutation({
    mutationFn: async () => {
      const body = new FormData();
      body.append("type", type);
      body.append("document", file as File);
      if (documentNumber.trim()) body.append("document_number", documentNumber.trim());

      // FormData goes through request() directly: apiSend sets a JSON
      // Content-Type, which strips the multipart boundary.
      return request("/api/saka/seller/verifications", { method: "POST", body });
    },
    onSuccess: async () => {
      setFile(null);
      setDocumentNumber("");
      if (fileRef.current) fileRef.current.value = "";
      await queryClient.invalidateQueries({ queryKey: ["vendor-verifications"] });
      await queryClient.invalidateQueries({ queryKey: PROFILE_QUERY_KEY });
    },
  });

  const types = query.data?.meta.types ?? [];
  const automated = query.data?.meta.automated_verification;
  const nidaDigits = query.data?.meta.nida_digits ?? 20;
  const isNationalId = type === "national_id";
  // Counted on digits alone, because the server normalises before validating —
  // "19900101-12345-00001-23" and its bare-digit twin are the same number.
  const nidaDigitCount = documentNumber.replace(/\D+/g, "").length;
  const rows = query.data?.data ?? [];
  const pendingTypes = new Set(
    rows.filter((row) => row.status === "pending").map((row) => row.type),
  );

  return (
    <Card>
      <div className="border-b border-line px-5 py-3">
        <h2 className="text-sm font-semibold text-ink">Documents</h2>
        {/*
          * Stated unconditionally, not behind the automated_verification flag
          * below: if that meta key were ever absent the vendor would be left to
          * assume a machine is deciding. Who reads the document is the single
          * most important thing this screen has to tell them.
          */}
        <p className="text-xs text-ink-soft">
          Stored privately. Identity verification is manually reviewed by authorized
          administrators.
        </p>
      </div>

      <div className="grid gap-5 px-5 py-5 lg:grid-cols-2">
        <div className="space-y-4">
          <Field label="Document type" required>
            <Select value={type} onChange={(event) => setType(event.target.value)}>
              <option value="">Choose…</option>
              {types.map((option) => (
                <option key={option.value} value={option.value} disabled={pendingTypes.has(option.value)}>
                  {option.label}
                  {pendingTypes.has(option.value) ? " — already under review" : ""}
                </option>
              ))}
            </Select>
          </Field>

          <Field
            label={isNationalId ? "NIDA number" : "Document number"}
            required={isNationalId}
            hint={
              isNationalId
                ? `${nidaDigits} digits, as printed on the card. Dashes and spaces are fine.`
                : "Optional, but it speeds up the review."
            }
            error={
              // Only once enough has been typed to judge — warning on the first
              // keystroke of a 20-digit number is noise.
              isNationalId && nidaDigitCount > 0 && nidaDigitCount !== nidaDigits
                ? `${nidaDigitCount} of ${nidaDigits} digits.`
                : undefined
            }
          >
            <Input
              value={documentNumber}
              inputMode="numeric"
              autoComplete="off"
              onChange={(event) => setDocumentNumber(event.target.value)}
              maxLength={60}
            />
          </Field>

          {/*
            Images only. The media pipeline validates by magic bytes against
            JPEG/PNG/WebP and rejects everything else, so offering PDF here
            produced a file picker that accepted a document the API then
            refused — the vendor sees a validation error on a file the UI
            told them was fine.
          */}
          <Field label="File" required hint="A clear photo or scan. JPG, PNG or WebP, up to 5 MB.">
            <input
              ref={fileRef}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              onChange={(event) => setFile(event.target.files?.[0] ?? null)}
              className="block w-full text-sm text-ink-soft file:mr-3 file:rounded-[var(--radius-control)] file:border file:border-line file:bg-surface file:px-3 file:py-1.5 file:text-sm file:text-ink hover:file:bg-muted-soft"
            />
          </Field>

          <FormError error={submit.error} />

          <Button
            variant="primary"
            loading={submit.isPending}
            disabled={!type || !file || pendingTypes.has(type)}
            onClick={() => submit.mutate()}
          >
            <Upload aria-hidden className="h-4 w-4" />
            Submit for review
          </Button>

          {submit.isSuccess && !submit.isPending && (
            <p className="text-sm text-ok">Sent. We&apos;ll email you when it has been reviewed.</p>
          )}
        </div>

        <div>
          <p className="mb-2 text-[13px] font-medium text-ink">What you&apos;ve sent</p>

          <ListState
            isLoading={query.isPending}
            error={query.error}
            isEmpty={rows.length === 0}
            onRetry={() => void query.refetch()}
            emptyTitle="Nothing submitted yet"
            emptyDescription="Documents you send appear here with their review status."
          >
            <ul className="divide-y divide-line rounded-[var(--radius-control)] border border-line">
              {rows.map((row) => (
                <li key={row.uuid} className="px-3 py-2.5">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm text-ink">{humanise(row.type)}</span>

                    {/*
                      * "Needs correction" is a real state and reads very
                      * differently from "Pending review": one is waiting on us,
                      * the other is waiting on the vendor. Showing both as
                      * amber "Pending" left people watching a queue when they
                      * had been asked for something.
                      */}
                    <Badge tone={row.needs_correction ? "warn" : statusTone(row.status)}>
                      {row.needs_correction ? "Needs correction" : humanise(row.status)}
                    </Badge>

                    {row.document_number_masked && (
                      <span className="font-mono text-[11px] text-ink-faint">
                        {row.document_number_masked}
                      </span>
                    )}

                    <span className="text-[11px] text-ink-faint">
                      {new Date(row.created_at).toLocaleDateString()}
                    </span>
                  </div>

                  {row.reviewer_note && (
                    <p
                      className={`mt-1 rounded-[var(--radius-control)] px-2.5 py-1.5 text-xs ${
                        row.needs_correction ? "bg-warn-soft text-warn" : "text-ink-soft"
                      }`}
                    >
                      {row.reviewer_note}
                    </p>
                  )}
                </li>
              ))}
            </ul>
          </ListState>

          {/*
            * Said out loud, not implied by silence.
            *
            * There is no automated NIDA check — the authority publishes no
            * integration a marketplace can call — so every document here is
            * read by a person. A vendor waiting on a queue deserves to know it
            * is a human queue rather than assuming a machine has stalled.
            */}
          {automated && !automated.available && (
            <p className="mt-3 rounded-[var(--radius-control)] bg-muted-soft px-3 py-2 text-xs text-ink-soft">
              Automated identity checks are not available in Tanzania yet, so a member of the SAKA
              team reviews every document by hand. You will be told either way.
            </p>
          )}
        </div>
      </div>
    </Card>
  );
}
