"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { ArrowUpRight, Building2, Loader2, Mail, MessageCircle, MessageSquare, Phone, ShieldCheck } from "lucide-react";

import { InquiryForm } from "@/components/listings/InquiryForm";
import { SafeImage } from "@/components/ui/SafeImage";
import { apiGet } from "@/lib/api/browser";
import type { ApiBusiness, ApiInquiry, ApiListing, Paginated } from "@/lib/types";
import { useAuth } from "@/providers/AuthProvider";

/**
 * How you reach the seller: phone, WhatsApp, and an in-app message.
 *
 * ── On "Online Chat" ──────────────────────────────────────────────────────
 * There is no realtime chat in this system. The backend has no conversations,
 * messages or threads table and no websocket — `features.messaging_enabled` is
 * false in settings, and the technical-debt log lists messaging as unbuilt.
 *
 * What DOES exist is the inquiry thread: `POST /inquiries` opens one against a
 * listing, the seller replies from the vendor portal, and the customer reads
 * the reply at `/account/inquiries/{uuid}`. That is a persistent, per-listing,
 * two-party message thread — the same primitive a chat would be built on.
 *
 * So this button behaves exactly as asked, on the messaging the API actually
 * has: if a thread already exists for this listing it OPENS it, otherwise it
 * creates one. It is labelled "Message" rather than "Chat" because calling a
 * reply-by-email thread a live chat would set an expectation of an instant
 * answer that nothing here can meet. Upgrading this to realtime is a backend
 * change, listed in the report.
 */
export function SellerContactCard({ listing }: { listing: ApiListing }) {
  const [inquiryOpen, setInquiryOpen] = useState(false);
  const { isAuthenticated } = useAuth();

  const seller = listing.seller;

  /*
   * The listing payload carries the seller's phone but not their WhatsApp,
   * e-mail or logo. Rather than showing a thinner card than the business page
   * does, the profile is fetched once and cached — it is a public, cacheable
   * read, and every listing by the same seller reuses it.
   */
  const profile = useQuery({
    queryKey: ["business", seller?.slug],
    queryFn: () => apiGet<{ data: ApiBusiness }>(`/businesses/${seller!.slug}`),
    enabled: Boolean(seller?.slug),
    staleTime: 10 * 60 * 1000,
  });

  const business = profile.data?.data;

  const phone = business?.contact?.phone ?? seller?.phone ?? null;
  const email = business?.contact?.email ?? null;
  const logo = business?.logo_url ?? seller?.logo_url ?? null;

  // Most sellers here use one number for calls and WhatsApp; fall back to it
  // rather than hiding the button.
  const whatsappRaw = business?.contact?.whatsapp ?? seller?.whatsapp ?? phone;
  const whatsapp = whatsappRaw ? whatsappRaw.replace(/[^0-9]/g, "") : null;

  /*
   * Does this customer already have a thread on this listing?
   *
   * Only asked when signed in — a guest has no threads, and firing an
   * authenticated request for them would 401 on every listing page.
   */
  const existing = useQuery({
    queryKey: ["listing-inquiry", listing.slug],
    queryFn: () => apiGet<Paginated<ApiInquiry>>("/account/inquiries", { per_page: 100 }),
    enabled: isAuthenticated,
    staleTime: 60 * 1000,
  });

  const thread = existing.data?.data.find((inquiry) => inquiry.listing?.slug === listing.slug);

  const message = `Hello, I found your listing on SAKA — "${listing.title}". Is it still available?`;

  const base =
    "inline-flex w-full items-center justify-center gap-2 rounded-full px-5 py-3 text-sm font-semibold transition";

  return (
    <>
      <div className="rounded-xl border border-border bg-white p-5">
        <h3 className="mb-4 text-lg font-bold text-navy">Contact the seller</h3>

        {seller?.slug && (
          <Link
            href={`/businesses/${seller.slug}`}
            className="mb-4 flex items-center gap-3 rounded-lg border border-border p-3 transition hover:border-teal"
          >
            <SafeImage
              src={logo}
              alt={`${seller.display_name} logo`}
              className="h-11 w-11 rounded-full object-cover"
              fallbackClassName="h-11 w-11 rounded-full bg-teal/10 text-teal"
              fallback={<Building2 className="h-5 w-5" />}
            />
            <span className="min-w-0 flex-1">
              <span className="flex items-center gap-1 truncate text-sm font-bold text-navy">
                {seller.display_name}
                {seller.is_verified && <ShieldCheck className="h-3.5 w-3.5 shrink-0 text-teal" />}
              </span>
              <span className="text-xs text-muted-foreground">View business profile</span>
            </span>
            <ArrowUpRight className="h-4 w-4 shrink-0 text-muted-foreground" />
          </Link>
        )}

        <div className="space-y-2.5">
          {/* ---- Call ---------------------------------------------------- */}
          {phone ? (
            <a href={`tel:${phone}`} className={`${base} bg-teal text-white hover:opacity-90`}>
              <Phone className="h-4 w-4" />
              Call {phone}
            </a>
          ) : (
            <p className="rounded-full border border-dashed border-border px-5 py-3 text-center text-sm text-muted-foreground">
              No phone published — use a message instead
            </p>
          )}

          {/* ---- WhatsApp ------------------------------------------------- */}
          {whatsapp && (
            <a
              href={`https://wa.me/${whatsapp}?text=${encodeURIComponent(message)}`}
              target="_blank"
              rel="noopener noreferrer"
              className={`${base} bg-[#25D366] text-white hover:opacity-90`}
            >
              <MessageCircle className="h-4 w-4" />
              WhatsApp
            </a>
          )}

          {email && (
            <a
              href={`mailto:${email}?subject=${encodeURIComponent(listing.title)}&body=${encodeURIComponent(message)}`}
              className={`${base} border border-border bg-white text-navy hover:border-teal hover:text-teal`}
            >
              <Mail className="h-4 w-4" />
              Email
            </a>
          )}

          {/* ---- In-app message ------------------------------------------- */}
          {existing.isFetching && !existing.data ? (
            <span
              className={`${base} border border-border bg-white text-muted-foreground`}
              aria-live="polite"
            >
              <Loader2 className="h-4 w-4 animate-spin" />
              Checking your messages…
            </span>
          ) : thread ? (
            <Link
              href={`/account/inquiries/${thread.uuid}`}
              className={`${base} border-2 border-teal bg-white text-teal hover:bg-teal hover:text-white`}
            >
              <MessageSquare className="h-4 w-4" />
              Open your message
            </Link>
          ) : (
            <button
              type="button"
              onClick={() => setInquiryOpen(true)}
              className={`${base} border-2 border-teal bg-white text-teal hover:bg-teal hover:text-white`}
            >
              <MessageSquare className="h-4 w-4" />
              Message the seller
            </button>
          )}
        </div>

        <p className="mt-3 text-center text-xs text-muted-foreground">
          Never pay a deposit before viewing in person.
        </p>
      </div>

      <InquiryForm
        listing={listing}
        open={inquiryOpen}
        onClose={() => setInquiryOpen(false)}
      />
    </>
  );
}
