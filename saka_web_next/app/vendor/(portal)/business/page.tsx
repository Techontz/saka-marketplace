"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ExternalLink } from "lucide-react";
import { Suspense, useState } from "react";

import { BrandingStep } from "@/components/vendor/business/BrandingStep";
import {
  BusinessStep,
  ContactStep,
  HoursStep,
  LocationStep,
  SocialStep,
  payloadFromDraft,
  useBusinessTypes,
  useProfileDraft,
  type ProfileDraft,
} from "@/components/vendor/business/BusinessForm";
import {
  Button,
  Card,
  ErrorState,
  FormError,
  PageHeader,
  TableSkeleton,
} from "@/components/vendor/ui";
import { apiSend } from "@/lib/vendor/api/browser";
import { MARKETPLACE_URL } from "@/lib/vendor/config";
import { useUrlFilters } from "@/lib/vendor/hooks";
import { useAuth } from "@/providers/vendor/AuthProvider";
import { PROFILE_QUERY_KEY, useVendor } from "@/providers/vendor/VendorProvider";

/**
 * Business settings.
 *
 * The same steps as onboarding, reached in any order and saved one section at a
 * time. Reusing the step components rather than writing a second set of forms
 * is what keeps the per-vertical rules — a landlord has no opening hours, a
 * mobile service has no walk-in address — true in both places at once.
 */

type SectionKey = "business" | "location" | "contact" | "branding" | "hours" | "social";

const SECTIONS: { key: SectionKey; label: string; blurb: string; fields: (keyof ProfileDraft)[] }[] = [
  {
    key: "business",
    label: "Business",
    blurb: "What you do, and what customers should call you.",
    fields: ["display_name", "business_name", "business_type", "bio", "business_reg_no", "tin"],
  },
  {
    key: "location",
    label: "Location",
    blurb: "Where you are, and where you appear on the map.",
    fields: ["region_slug", "district_slug", "ward_slug", "street", "latitude", "longitude"],
  },
  {
    key: "contact",
    label: "Contact",
    blurb: "How customers reach you. Shown on your public profile.",
    fields: ["public_phone", "public_email", "whatsapp", "website"],
  },
  {
    key: "branding",
    label: "Logo & cover",
    blurb: "Uploads save immediately.",
    fields: [],
  },
  {
    key: "hours",
    label: "Opening hours",
    blurb: "When you are open. An empty day means closed.",
    fields: ["opening_hours"],
  },
  {
    key: "social",
    label: "Social links",
    blurb: "Optional profiles customers can follow.",
    fields: ["social_links"],
  },
];

export default function BusinessSettingsPage() {
  return (
    <Suspense fallback={null}>
      <BusinessSettings />
    </Suspense>
  );
}

function BusinessSettings() {
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const { profile, progress, businessType, isLoading, error, refetch } = useVendor();
  const types = useBusinessTypes();

  const { draft, update, hydrated } = useProfileDraft(profile);
  const { filters, setFilters } = useUrlFilters({ section: "business" });
  const [savedSection, setSavedSection] = useState<SectionKey | null>(null);

  const save = useMutation({
    mutationFn: ({ fields }: { key: SectionKey; fields: (keyof ProfileDraft)[] }) =>
      apiSend("/seller/vendor-profile", "PATCH", payloadFromDraft(draft, fields)),
    onSuccess: async (_data, variables) => {
      setSavedSection(variables.key);
      await queryClient.invalidateQueries({ queryKey: PROFILE_QUERY_KEY });
    },
  });

  if (isLoading || !hydrated) {
    return (
      <Card>
        <TableSkeleton rows={8} />
      </Card>
    );
  }

  if (error) {
    return (
      <Card>
        <ErrorState error={error} onRetry={refetch} title="We couldn't load your business" />
      </Card>
    );
  }

  // Hours are hidden for a business type that has none — the same rule the
  // onboarding wizard follows, taken from the API rather than a local list.
  const sections = SECTIONS.filter(
    (section) => section.key !== "hours" || progress?.steps.hours?.applicable !== false,
  );

  const current = sections.find((section) => section.key === filters.section) ?? sections[0];
  const publicUrl =
    MARKETPLACE_URL && profile?.slug ? `${MARKETPLACE_URL}/sellers/${profile.slug}` : null;

  return (
    <>
      <PageHeader
        title="Business profile"
        description="What customers see about you."
        actions={
          publicUrl ? (
            <a href={publicUrl} target="_blank" rel="noopener noreferrer">
              <Button variant="secondary">
                <ExternalLink aria-hidden className="h-4 w-4" />
                View public profile
              </Button>
            </a>
          ) : undefined
        }
      />

      <div className="grid gap-4 lg:grid-cols-[220px_1fr]">
        <nav aria-label="Settings sections">
          <Card>
            <ul className="p-1.5">
              {sections.map((section) => {
                const isCurrent = section.key === current.key;

                return (
                  <li key={section.key}>
                    <button
                      type="button"
                      aria-current={isCurrent ? "true" : undefined}
                      onClick={() => {
                        setSavedSection(null);
                        setFilters({ section: section.key }, { resetPage: false });
                      }}
                      className={
                        "w-full rounded-[var(--radius-control)] px-3 py-2 text-left text-sm transition-colors " +
                        (isCurrent
                          ? "bg-brand-soft font-medium text-brand-ink"
                          : "text-ink-soft hover:bg-muted-soft hover:text-ink")
                      }
                    >
                      {section.label}
                    </button>
                  </li>
                );
              })}
            </ul>
          </Card>
        </nav>

        <Card>
          <div className="border-b border-line px-5 py-3">
            <h2 className="text-sm font-semibold text-ink">{current.label}</h2>
            <p className="text-xs text-ink-soft">{current.blurb}</p>
          </div>

          <div className="px-5 py-5">
            {current.key === "business" && (
              <BusinessStep draft={draft} onChange={update} types={types.data ?? []} />
            )}

            {current.key === "location" && (
              <LocationStep draft={draft} onChange={update} businessType={businessType} />
            )}

            {current.key === "contact" && (
              <ContactStep
                draft={draft}
                onChange={update}
                accountPhone={user?.phone ?? null}
                accountEmail={user?.email ?? null}
              />
            )}

            {current.key === "branding" && (
              <BrandingStep
                logoUrl={profile?.branding.logo_url}
                coverUrl={profile?.branding.cover_url}
              />
            )}

            {current.key === "hours" && <HoursStep draft={draft} onChange={update} />}

            {current.key === "social" && <SocialStep draft={draft} onChange={update} />}
          </div>

          {/* Branding uploads save themselves, so a Save button under them
              would do nothing and imply the uploads had not taken. */}
          {current.fields.length > 0 && (
            <div className="flex items-center justify-between gap-3 border-t border-line px-5 py-3">
              <div className="min-w-0">
                <FormError error={save.error} />
                {savedSection === current.key && !save.isPending && !save.error && (
                  <span className="text-sm text-ok">Saved.</span>
                )}
              </div>

              <Button
                variant="primary"
                loading={save.isPending}
                onClick={() => save.mutate({ key: current.key, fields: current.fields })}
              >
                Save {current.label.toLowerCase()}
              </Button>
            </div>
          )}
        </Card>
      </div>
    </>
  );
}
