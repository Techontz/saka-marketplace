"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ExternalLink } from "lucide-react";
import Link from "next/link";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import {
  EMPTY_LISTING,
  ListingFields,
  listingPayload,
  type ListingDraft,
} from "@/components/vendor/listings/ListingForm";
import { BoundaryEditor } from "@/components/vendor/listings/BoundaryEditor";
import { MediaManager } from "@/components/vendor/listings/MediaManager";
import {
  Badge,
  Button,
  Card,
  ErrorState,
  FormError,
  Modal,
  PageHeader,
  TableSkeleton,
  humanise,
  statusTone,
} from "@/components/vendor/ui";
import { apiGet, apiSend } from "@/lib/vendor/api/browser";
import { marketplaceListingUrl } from "@/lib/vendor/config";
import type { Envelope, ListingDetail } from "@/lib/vendor/api/types";
import { useAuth } from "@/providers/vendor/AuthProvider";
import { useVendor } from "@/providers/vendor/VendorProvider";
import { formatCount, formatMoney } from "@/lib/vendor/format";

type Tab = "details" | "photos" | "boundary" | "preview";

export default function ListingEditorPage() {
  return (
    <Suspense fallback={null}>
      <ListingEditor />
    </Suspense>
  );
}

/**
 * Edit one listing: its details, its photos, and how it will look.
 *
 * Tabbed rather than one long page, because the three are used at different
 * moments — writing the copy, uploading photos afterwards, and checking the
 * result before submitting.
 */
function ListingEditor() {
  const params = useParams<{ uuid: string }>();
  const uuid = params.uuid;

  const router = useRouter();
  const searchParams = useSearchParams();
  const queryClient = useQueryClient();
  const { noun, businessType } = useVendor();
  const { canPublish } = useAuth();

  const [tab, setTab] = useState<Tab>(searchParams.get("created") ? "photos" : "details");
  const [draft, setDraft] = useState<ListingDraft>(EMPTY_LISTING);
  /** The uuid the draft was seeded from, or null before it loads. */
  const [hydrated, setHydrated] = useState<string | null>(null);
  const [confirmArchive, setConfirmArchive] = useState(false);

  const query = useQuery({
    queryKey: ["vendor-listing", uuid],
    queryFn: () => apiGet<Envelope<ListingDetail>>(`/seller/listings/${uuid}`).then((r) => r.data),
  });

  const listing = query.data;

  // Seed the draft ONCE, during render rather than in an effect: an effect
  // would paint an empty form first and then replace it. Re-seeding on every
  // refetch would discard whatever the vendor is currently typing, so this is
  // keyed on the listing itself.
  if (listing && hydrated !== listing.uuid) {
    setHydrated(listing.uuid);
    setDraft({
      ...EMPTY_LISTING,
      title: listing.title,
      description: listing.description ?? "",
      category_slug: listing.category?.slug ?? "",
      purpose: listing.purpose ?? "sale",
      price: listing.price?.amount?.toString() ?? "",
      currency: listing.price?.currency ?? "TZS",
      price_unit: listing.price?.unit ?? "total",
      region_slug: listing.location.region_slug ?? "",
      district_slug: listing.location.district_slug ?? "",
      ward_slug: listing.location.ward_slug ?? "",
      address_line: listing.location.address_line ?? "",
      available_from: listing.available_from ?? "",
      // The detail endpoint describes each attribute; the form only needs the
      // value, keyed by code, which is also the shape a write takes.
      attributes: Object.fromEntries(
        (listing.attributes ?? []).map((attribute) => [
          attribute.code,
          attribute.value === null ? "" : String(attribute.value),
        ]),
      ),
      amenities: listing.amenities.map((item) => item.slug),
      facilities: listing.facilities.map((item) => item.slug),
    });
  }

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ["vendor-listing", uuid] });
    await queryClient.invalidateQueries({ queryKey: ["vendor-listings"] });
    await queryClient.invalidateQueries({ queryKey: ["vendor", "dashboard"] });
  };

  const save = useMutation({
    mutationFn: () => apiSend(`/seller/listings/${uuid}`, "PATCH", listingPayload(draft)),
    onSuccess: invalidate,
  });

  const act = useMutation({
    mutationFn: (action: string) => apiSend(`/seller/listings/${uuid}/${action}`, "POST"),
    onSuccess: async () => {
      setConfirmArchive(false);
      await invalidate();
    },
  });

  const remove = useMutation({
    mutationFn: () => apiSend(`/seller/listings/${uuid}`, "DELETE"),
    onSuccess: async () => {
      await invalidate();
      router.replace("/vendor/listings");
    },
  });

  if (query.isPending) {
    return (
      <Card>
        <TableSkeleton rows={8} />
      </Card>
    );
  }

  if (query.error || !listing) {
    return (
      <Card>
        <ErrorState
          error={query.error}
          onRetry={() => void query.refetch()}
          title={`We couldn't load this ${noun.singular}`}
        />
      </Card>
    );
  }

  const publicUrl = listing.status === "published" ? marketplaceListingUrl(listing.slug) : null;

  return (
    <>
      <Link
        href="/vendor/listings"
        className="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-soft hover:text-ink"
      >
        <ArrowLeft aria-hidden className="h-4 w-4" />
        Back to {noun.plural}
      </Link>

      <PageHeader
        title={listing.title}
        description={
          <span className="flex flex-wrap items-center gap-2">
            <Badge tone={statusTone(listing.status)}>{humanise(listing.status)}</Badge>
            {listing.is_featured && <Badge tone="brand">Featured</Badge>}
            <span className="text-ink-soft">
              {formatCount(listing.stats?.views)} views · {formatCount(listing.stats?.inquiries)} inquiries
            </span>
          </span>
        }
        actions={
          <div className="flex flex-wrap gap-2">
            {publicUrl && (
              <a href={publicUrl} target="_blank" rel="noopener noreferrer">
                <Button variant="secondary">
                  <ExternalLink aria-hidden className="h-4 w-4" />
                  View live
                </Button>
              </a>
            )}

            {listing.status === "draft" && (
              <Button
                variant="primary"
                loading={act.isPending}
                disabled={!canPublish}
                title={canPublish ? undefined : "Verify your phone to publish"}
                onClick={() => act.mutate("submit")}
              >
                Submit for review
              </Button>
            )}

            {listing.status === "published" && (
              <Button variant="secondary" loading={act.isPending} onClick={() => act.mutate("pause")}>
                Pause
              </Button>
            )}

            {listing.status === "paused" && (
              <Button
                variant="primary"
                loading={act.isPending}
                disabled={!canPublish}
                onClick={() => act.mutate("resume")}
              >
                Resume
              </Button>
            )}

            {listing.status !== "archived" && (
              <Button variant="danger" onClick={() => setConfirmArchive(true)}>
                Archive
              </Button>
            )}
          </div>
        }
      />

      {listing.rejection_reason && (
        <div className="mb-4 rounded-[var(--radius-card)] border border-danger/30 bg-danger-soft px-4 py-3">
          <p className="text-sm font-medium text-danger">A moderator asked for changes</p>
          <p className="mt-0.5 text-sm text-ink-soft">{listing.rejection_reason}</p>
        </div>
      )}

      <div className="mb-4 flex gap-1 rounded-[var(--radius-control)] border border-line bg-surface p-1">
        {(
          [
            "details",
            "photos",
            // A boundary is only meaningful on land, and the API says which
            // categories those are — the portal does not keep its own list.
            ...(listing.supports_boundary ? (["boundary"] as Tab[]) : []),
            "preview",
          ] as Tab[]
        ).map((value) => (
          <button
            key={value}
            type="button"
            onClick={() => setTab(value)}
            aria-current={tab === value ? "true" : undefined}
            className={
              "flex-1 rounded-[calc(var(--radius-control)-2px)] px-3 py-1.5 text-sm font-medium transition-colors " +
              (tab === value ? "bg-brand-soft text-brand-ink" : "text-ink-soft hover:text-ink")
            }
          >
            {value === "details"
              ? "Details"
              : value === "photos"
                ? `Photos (${listing.images.length})`
                : value === "boundary"
                  ? "Boundary"
                  : "Preview"}
          </button>
        ))}
      </div>

      {tab === "details" && (
        <Card>
          <div className="px-5 py-5">
            <ListingFields
              draft={draft}
              onChange={(patch) => setDraft((current) => ({ ...current, ...patch }))}
              businessType={businessType}
            />
            <div className="mt-5">
              <FormError error={save.error} />
            </div>
          </div>

          <div className="flex items-center justify-between border-t border-line px-5 py-3">
            {save.isSuccess ? (
              <span className="text-sm text-ok">Saved.</span>
            ) : (
              <span className="text-xs text-ink-faint">
                Changes to a live {noun.singular} take effect immediately.
              </span>
            )}
            <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
              Save changes
            </Button>
          </div>
        </Card>
      )}

      {tab === "photos" && (
        <Card>
          <div className="px-5 py-5">
            <MediaManager
              listingUuid={uuid}
              media={listing.images}
              onChanged={() => void query.refetch()}
            />
          </div>
        </Card>
      )}

      {tab === "boundary" && <BoundaryEditor uuid={uuid} listingTitle={listing.title} />}

      {tab === "preview" && <Preview listing={listing} />}

      <Modal
        open={confirmArchive}
        onClose={() => setConfirmArchive(false)}
        title={`Archive this ${noun.singular}?`}
        description="It comes off the marketplace and stops receiving inquiries. Archiving cannot be undone — copy it first if you might relist."
        footer={
          <>
            <Button variant="ghost" onClick={() => setConfirmArchive(false)}>
              Cancel
            </Button>
            {listing.status === "draft" && (
              <Button variant="danger" loading={remove.isPending} onClick={() => remove.mutate()}>
                Delete draft
              </Button>
            )}
            <Button variant="danger" loading={act.isPending} onClick={() => act.mutate("archive")}>
              Archive
            </Button>
          </>
        }
      >
        <FormError error={act.error ?? remove.error} />
      </Modal>
    </>
  );
}

/**
 * How the listing will look to a buyer.
 *
 * Rendered from the vendor's own data rather than by linking to the
 * marketplace, because the listing a vendor most needs to preview is a DRAFT —
 * which by definition has no public URL. Approximate by design: it shows the
 * content and photos, not the marketplace's exact chrome.
 */
function Preview({ listing }: { listing: ListingDetail }) {
  const primary = listing.images.find((image) => image.is_primary) ?? listing.images[0];

  return (
    <Card>
      <div className="border-b border-line px-5 py-3">
        <h2 className="text-sm font-semibold text-ink">Buyer&apos;s view</h2>
        <p className="text-xs text-ink-soft">
          Roughly how this appears on the marketplace. Works for drafts, which have no public page
          yet.
        </p>
      </div>

      <div className="px-5 py-5">
        <div className="mx-auto max-w-2xl overflow-hidden rounded-[var(--radius-card)] border border-line">
          <div className="aspect-[16/9] bg-muted-soft">
            {primary ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={primary.url} alt="" className="h-full w-full object-cover" />
            ) : (
              <div className="flex h-full items-center justify-center text-sm text-ink-faint">
                No photo — buyers scroll past listings without one
              </div>
            )}
          </div>

          <div className="p-5">
            <h3 className="text-lg font-semibold text-ink">{listing.title}</h3>

            <p className="mt-1 text-sm text-ink-soft">
              {listing.location.address_line ?? listing.location.region ?? "Location not set"}
            </p>

            <p className="mt-3 text-xl font-semibold text-brand">
              {formatMoney(listing.price) === "Price on request" ? "No price set" : formatMoney(listing.price)}
              {listing.price?.unit && listing.price.unit !== "total" && (
                <span className="text-sm font-normal text-ink-soft"> / {listing.price.unit}</span>
              )}
            </p>

            {(listing.attributes ?? []).length > 0 && (
              <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-soft">
                {listing.attributes.map((attribute) => (
                  <li key={attribute.code}>
                    <span className="text-ink">
                      {attribute.label ?? String(attribute.value ?? "")}
                    </span>{" "}
                    {attribute.unit ?? attribute.name.toLowerCase()}
                  </li>
                ))}
              </ul>
            )}

            {listing.description ? (
              <p className="mt-4 text-sm whitespace-pre-wrap text-ink-soft">{listing.description}</p>
            ) : (
              <p className="mt-4 text-sm text-ink-faint">
                No description — buyers are far more likely to enquire when there is one.
              </p>
            )}

            {listing.amenities.length > 0 && (
              <div className="mt-4 flex flex-wrap gap-1.5">
                {listing.amenities.map((amenity) => (
                  <Badge key={amenity.slug} tone="muted">
                    {amenity.name}
                  </Badge>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </Card>
  );
}
