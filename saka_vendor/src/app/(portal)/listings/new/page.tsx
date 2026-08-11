"use client";

import { useMutation } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";

import {
  EMPTY_LISTING,
  ListingFields,
  listingPayload,
  type ListingDraft,
} from "@/components/listings/ListingForm";
import { Button, Card, FormError, PageHeader } from "@/components/ui";
import { apiSend } from "@/lib/api/browser";
import type { Envelope, ListingDetail } from "@/lib/api/types";
import { useVendor } from "@/providers/VendorProvider";

/**
 * Create a listing.
 *
 * Saves as a DRAFT and moves straight to the editor, where photos are added.
 * A single create-then-publish form would either force a vendor to upload
 * photos before the listing exists (nowhere to attach them) or publish
 * something with none.
 */
export default function NewListingPage() {
  const router = useRouter();
  const { noun, businessType } = useVendor();

  const [draft, setDraft] = useState<ListingDraft>(EMPTY_LISTING);

  const create = useMutation({
    mutationFn: () =>
      apiSend<Envelope<ListingDetail>>("/seller/listings", "POST", listingPayload(draft)).then(
        (r) => r.data,
      ),
    onSuccess: (listing) => router.replace(`/listings/${listing.uuid}?created=1`),
  });

  const update = (patch: Partial<ListingDraft>) =>
    setDraft((current) => ({ ...current, ...patch }));

  const ready = draft.title.trim().length > 1 && draft.category_slug !== "";

  return (
    <>
      <Link
        href="/listings"
        className="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-soft hover:text-ink"
      >
        <ArrowLeft aria-hidden className="h-4 w-4" />
        Back to {noun.plural}
      </Link>

      <PageHeader
        title={`New ${noun.singular}`}
        description="Save it as a draft, then add photos. Nothing goes live until you submit it."
      />

      <Card>
        <div className="px-5 py-5">
          <ListingFields draft={draft} onChange={update} businessType={businessType} />
          <div className="mt-5">
            <FormError error={create.error} />
          </div>
        </div>

        <div className="flex items-center justify-between border-t border-line px-5 py-3">
          <Link href="/listings">
            <Button variant="ghost">Cancel</Button>
          </Link>
          <Button
            variant="primary"
            loading={create.isPending}
            disabled={!ready}
            onClick={() => create.mutate()}
          >
            Save draft & add photos
          </Button>
        </div>
      </Card>
    </>
  );
}
