"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ArrowRight, Check, ShieldCheck } from "lucide-react";
import Link from "next/link";
import { Logo } from "@/components/vendor/ui/Logo";
import { useRouter } from "next/navigation";
import { useState } from "react";

import {
  BrandingStep,
} from "@/components/vendor/business/BrandingStep";
import {
  BusinessStep,
  ContactStep,
  HoursStep,
  LocationStep,
  payloadFromDraft,
  useBusinessTypes,
  useProfileDraft,
  type ProfileDraft,
} from "@/components/vendor/business/BusinessForm";
import { Button, Card, ErrorState, FormError, TableSkeleton } from "@/components/vendor/ui";
import { apiSend } from "@/lib/vendor/api/browser";
import { cn } from "@/lib/vendor/cn";
import { useAuth } from "@/providers/vendor/AuthProvider";
import { PROFILE_QUERY_KEY, useVendor } from "@/providers/vendor/VendorProvider";

/**
 * The onboarding wizard.
 *
 * Six steps, and one of them can disappear: a landlord is never asked for
 * opening hours, because the business type says they have none. That is the
 * multi-vertical requirement made concrete — the same wizard, a different
 * sequence per trade.
 *
 * Each step SAVES on continue rather than everything at the end. A vendor who
 * closes the tab at step four keeps steps one to three, and `progress.next_step`
 * from the API puts them back where they left off.
 */

type StepKey = "business" | "location" | "contact" | "branding" | "hours" | "verification";

const STEP_TITLES: Record<StepKey, { title: string; subtitle: string }> = {
  business: { title: "Your business", subtitle: "What you do, and what to call you." },
  location: { title: "Where you are", subtitle: "So customers can find you." },
  contact: { title: "How to reach you", subtitle: "Shown on your public profile." },
  branding: { title: "Logo and cover", subtitle: "Optional, but it makes a difference." },
  hours: { title: "Opening hours", subtitle: "When are you open?" },
  verification: { title: "Verify your phone", subtitle: "Required before you can publish." },
};

/** Which draft fields each step writes. */
const STEP_FIELDS: Record<StepKey, (keyof ProfileDraft)[]> = {
  business: ["display_name", "business_name", "business_type", "bio", "business_reg_no", "tin"],
  location: ["region_slug", "district_slug", "ward_slug", "street", "latitude", "longitude"],
  contact: ["public_phone", "public_email", "whatsapp", "website"],
  branding: [],
  hours: ["opening_hours"],
  verification: [],
};

export default function OnboardingPage() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const { profile, progress, businessType, isLoading, error, refetch } = useVendor();
  const types = useBusinessTypes();

  const { draft, update, hydrated } = useProfileDraft(profile);
  const [current, setCurrent] = useState<StepKey | null>(null);

  // The applicable steps for THIS business type. `hours` vanishes for a
  // landlord — the API says it is not applicable, and a step that cannot be
  // completed must not be shown.
  const steps: StepKey[] = (
    ["business", "location", "contact", "branding", "hours", "verification"] as StepKey[]
  ).filter((step) => progress?.steps[step]?.applicable !== false);

  // Resume where the API says they left off, but only for the initial landing —
  // once the vendor is moving between steps, their position wins. Set during
  // render rather than in an effect, so the first paint is the right step.
  if (current === null && progress) {
    setCurrent((progress.next_step as StepKey | null) ?? "business");
  }

  const save = useMutation({
    mutationFn: (fields: (keyof ProfileDraft)[]) =>
      apiSend("/seller/vendor-profile", "PATCH", payloadFromDraft(draft, fields)),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: PROFILE_QUERY_KEY }),
  });

  if (isLoading || !hydrated || current === null) {
    return (
      <div className="mx-auto max-w-2xl p-6">
        <Card>
          <TableSkeleton rows={6} />
        </Card>
      </div>
    );
  }

  if (error) {
    return (
      <div className="mx-auto max-w-2xl p-6">
        <Card>
          <ErrorState error={error} onRetry={refetch} title="We couldn't load your business" />
        </Card>
      </div>
    );
  }

  const index = steps.indexOf(current);
  const isLast = index === steps.length - 1;

  const goNext = async () => {
    const fields = STEP_FIELDS[current];

    if (fields.length > 0) {
      try {
        await save.mutateAsync(fields);
      } catch {
        // The error renders below; staying on the step is the right response.
        return;
      }
    }

    if (isLast) {
      router.replace("/vendor");
      return;
    }

    setCurrent(steps[index + 1]);
  };

  return (
    <div className="min-h-screen bg-canvas">
      <header className="border-b border-line bg-surface">
        <div className="mx-auto flex max-w-3xl items-center justify-between px-4 py-4 sm:px-6">
          <span className="flex items-center gap-2">
            <Logo size="sm" alt="SAKA" />
            <span className="text-sm font-semibold tracking-tight text-brand">for Business</span>
          </span>
          {/* Escapable on purpose. A wizard with no exit is a trap, and every
              field here is editable later from Business profile. */}
          <Link href="/vendor" className="text-sm text-ink-soft hover:text-ink">
            Finish later
          </Link>
        </div>
      </header>

      <div className="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        <ol className="mb-8 flex flex-wrap items-center gap-2">
          {steps.map((step, stepIndex) => {
            const done = progress?.steps[step]?.complete ?? false;
            const active = step === current;

            return (
              <li key={step} className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => setCurrent(step)}
                  aria-current={active ? "step" : undefined}
                  className={cn(
                    "flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors",
                    active
                      ? "bg-brand text-white"
                      : done
                        ? "bg-ok-soft text-ok"
                        : "bg-muted-soft text-ink-soft hover:text-ink",
                  )}
                >
                  {done && !active && <Check aria-hidden className="h-3 w-3" />}
                  {STEP_TITLES[step].title}
                </button>
                {stepIndex < steps.length - 1 && (
                  <span aria-hidden className="text-ink-faint">
                    ·
                  </span>
                )}
              </li>
            );
          })}
        </ol>

        <Card>
          <div className="border-b border-line px-5 py-4">
            <h1 className="text-base font-semibold text-ink">{STEP_TITLES[current].title}</h1>
            <p className="mt-0.5 text-sm text-ink-soft">{STEP_TITLES[current].subtitle}</p>
          </div>

          <div className="px-5 py-5">
            {current === "business" && (
              <BusinessStep draft={draft} onChange={update} types={types.data ?? []} />
            )}
            {current === "location" && (
              <LocationStep draft={draft} onChange={update} businessType={businessType} />
            )}
            {current === "contact" && (
              <ContactStep
                draft={draft}
                onChange={update}
                accountPhone={user?.phone ?? null}
                accountEmail={user?.email ?? null}
              />
            )}
            {current === "branding" && (
              <BrandingStep
                logoUrl={profile?.branding.logo_url}
                coverUrl={profile?.branding.cover_url}
              />
            )}
            {current === "hours" && <HoursStep draft={draft} onChange={update} />}
            {current === "verification" && <VerificationStep verified={user?.phone_verified ?? false} />}

            <FormError error={save.error} />
          </div>

          <div className="flex items-center justify-between border-t border-line px-5 py-3">
            <Button
              type="button"
              variant="ghost"
              disabled={index === 0}
              onClick={() => setCurrent(steps[index - 1])}
            >
              <ArrowLeft aria-hidden className="h-4 w-4" />
              Back
            </Button>

            <div className="flex items-center gap-2">
              <span className="text-xs text-ink-faint">
                Step {index + 1} of {steps.length}
              </span>
              <Button type="button" variant="primary" loading={save.isPending} onClick={goNext}>
                {isLast ? "Go to dashboard" : "Save & continue"}
                {!isLast && <ArrowRight aria-hidden className="h-4 w-4" />}
              </Button>
            </div>
          </div>
        </Card>
      </div>
    </div>
  );
}

function VerificationStep({ verified }: { verified: boolean }) {
  if (verified) {
    return (
      <div className="flex items-start gap-3 rounded-[var(--radius-control)] bg-ok-soft p-4">
        <ShieldCheck aria-hidden className="mt-0.5 h-5 w-5 shrink-0 text-ok" />
        <div>
          <p className="text-sm font-medium text-ink">Your phone is verified</p>
          <p className="mt-0.5 text-sm text-ink-soft">
            You can publish listings. Submitting an ID or business document later unlocks a
            verified badge on your profile.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="rounded-[var(--radius-control)] bg-warn-soft p-4">
        <p className="text-sm font-medium text-ink">Your phone isn&apos;t verified yet</p>
        <p className="mt-0.5 text-sm text-ink-soft">
          You can set everything else up and save drafts — they just stay private until your
          number is confirmed.
        </p>
      </div>

      <Link href="/vendor/verification">
        <Button type="button" variant="primary">
          Verify my phone
        </Button>
      </Link>
    </div>
  );
}
