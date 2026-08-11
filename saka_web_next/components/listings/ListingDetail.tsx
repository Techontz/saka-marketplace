"use client";

import { useState } from "react";
import { MapPin } from "lucide-react";

import { LandParcelPanel } from "@/components/listings/LandParcelPanel";
import { ListingGallery } from "@/components/listings/ListingGallery";
import { ListingSummary } from "@/components/listings/ListingSummary";
import { SellerContactCard } from "@/components/listings/SellerContactCard";
import { ListingReviews } from "@/components/listings/ListingReviews";
import { DirectionsLinks } from "@/components/map/DirectionsLinks";
import { LazyMapView } from "@/components/map/LazyMapView";
import type { ApiListing } from "@/lib/types";
import { attributeMap, formatPrice, toListingView } from "@/lib/view-models";

/**
 * The listing detail body.
 *
 * LAYOUT
 * ------
 * Gallery, then a full summary block, in the left column; the seller card
 * sticks alongside. The tabs stay, but they are no longer where a buyer finds
 * the basics — the column under the gallery used to be blank on every listing
 * because the sidebar is taller than the gallery, and a page that is empty in
 * the middle reads as broken however good the rest of it is.
 *
 * WHAT IS IN A TAB NOW
 * --------------------
 * Only the things a buyer looks up rather than reads: the full location
 * breakdown and map, the amenity and facility lists, and reviews. Price,
 * address, features, description and the seller's standing are all above,
 * where the decision is actually made.
 *
 * The land boundary, when there is one, is not in a tab at all. For a plot it
 * IS the listing.
 */

const TABS = ["Location", "Amenities", "Facilities", "Reviews"] as const;
type Tab = (typeof TABS)[number];

export function ListingDetail({ listing }: { listing: ApiListing }) {
  const view = toListingView(listing);
  const attributes = attributeMap(listing.attributes);

  const [tab, setTab] = useState<Tab>("Location");

  const images = listing.images?.length
    ? listing.images
    : listing.primary_image
      ? [listing.primary_image]
      : [];

  const lat = listing.location.latitude;
  const lng = listing.location.longitude;
  const hasCoords = lat !== null && lng !== null;

  const boundary = listing.boundary ?? null;

  return (
    <div className="mx-auto grid max-w-7xl grid-cols-1 gap-8 px-6 py-10 lg:grid-cols-3">
      {/* ------------------------------------------------- left column --- */}
      <div className="space-y-6 lg:col-span-2">
        <ListingGallery images={images} title={listing.title} />

        <ListingSummary listing={listing} />

        {boundary && (
          <LandParcelPanel
            boundary={boundary}
            title={listing.title}
            fallbackLat={lat}
            fallbackLng={lng}
          />
        )}

        {/*
          A plot that can carry a boundary but has none says so, rather than
          leaving a buyer to wonder whether the seller drew one. Only shown on
          categories where a boundary is possible.
        */}
        {!boundary && listing.supports_boundary && (
          <div className="rounded-xl border border-dashed border-border bg-white p-6 text-center">
            <p className="font-semibold text-navy">No surveyed boundary yet</p>
            <p className="mt-1 text-sm text-muted-foreground">
              The seller has not marked this plot&apos;s corners. Ask them for the survey plan
              before agreeing a price.
            </p>
          </div>
        )}
      </div>

      {/* ------------------------------------------------------ sidebar -- */}
      <aside className="space-y-4 lg:sticky lg:top-6 lg:self-start">
        <div className="overflow-hidden rounded-xl border border-border bg-white">
          <div className="h-56 bg-muted">
            {hasCoords ? (
              <LazyMapView
                pins={[
                  {
                    id: listing.slug,
                    lat,
                    lng,
                    label: listing.title,
                    sublabel: formatPrice(view),
                    tone: "listing",
                  },
                ]}
                polygons={
                  boundary
                    ? [{ id: "parcel", rings: boundary.rings, tone: "parcel" }]
                    : []
                }
                center={{ lat, lng }}
                zoom={15}
                height={224}
                allowLayers={false}
                allowMeasure={false}
                allowFullscreen={false}
              />
            ) : (
              <div className="flex h-full items-center justify-center px-4 text-center text-sm text-muted-foreground">
                This listing has no map location
              </div>
            )}
          </div>

          <div className="p-4">
            <p className="flex items-start gap-1.5 text-sm text-muted-foreground">
              <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-teal" />
              {view.location || "Location not published"}
            </p>

            {hasCoords && <DirectionsLinks className="mt-3" lat={lat} lng={lng} label={listing.title} />}
          </div>
        </div>

        <SellerContactCard listing={listing} />
      </aside>

      {/* --------------------------------------------------------- tabs -- */}
      <div className="lg:col-span-3">
        <div className="flex flex-wrap gap-2 border-b border-border">
          {TABS.map((item) => (
            <button
              key={item}
              onClick={() => setTab(item)}
              aria-current={tab === item ? "page" : undefined}
              className={`px-5 py-3 text-[15px] font-semibold transition-colors ${
                tab === item
                  ? "border-b-2 border-teal text-teal"
                  : "border-b-2 border-transparent text-navy hover:text-teal"
              }`}
            >
              {item}
            </button>
          ))}
        </div>

        {tab === "Location" && (
          <div className="mt-6 animate-fade-up space-y-6">
            <div className="rounded-xl border border-border bg-white p-6 sm:p-8">
              <h3 className="mb-4 text-2xl font-extrabold text-navy">Location</h3>
              <div className="grid grid-cols-1 gap-x-12 md:grid-cols-2">
                <div>
                  <InfoRow label="Country" value="Tanzania" />
                  {listing.location.region && <InfoRow label="Region" value={listing.location.region} />}
                  {listing.location.district && (
                    <InfoRow label="District" value={listing.location.district} />
                  )}
                  {listing.location.ward && <InfoRow label="Ward" value={listing.location.ward} />}
                </div>
                <div>
                  {listing.location.address_line && (
                    <InfoRow label="Address" value={listing.location.address_line} />
                  )}
                  {hasCoords && (
                    <>
                      <InfoRow label="Latitude" value={lat.toFixed(6)} />
                      <InfoRow label="Longitude" value={lng.toFixed(6)} />
                    </>
                  )}
                  {/* Whatever else the vertical happens to define — kept here
                      as well as in Key features so the Location tab is not
                      thin on a listing with no coordinates. */}
                  {attributes.floor_number !== undefined && attributes.floor_number !== null && (
                    <InfoRow label="Floor" value={String(attributes.floor_number)} />
                  )}
                </div>
              </div>
            </div>

            <div className="rounded-xl border border-border bg-white p-6 sm:p-8">
              <h3 className="mb-4 text-2xl font-extrabold text-navy">Map</h3>
              {hasCoords ? (
                <>
                  <LazyMapView
                    pins={[
                      {
                        id: listing.slug,
                        lat,
                        lng,
                        label: listing.title,
                        sublabel: formatPrice(view),
                        meta: view.location,
                        tone: "listing",
                      },
                    ]}
                    polygons={
                      boundary ? [{ id: "parcel", rings: boundary.rings, tone: "parcel" }] : []
                    }
                    center={{ lat, lng }}
                    zoom={16}
                    height={420}
                    allowRotate
                  />
                  <DirectionsLinks className="mt-4" lat={lat} lng={lng} label={listing.title} />
                </>
              ) : (
                <EmptyPanel text="This listing has no map location" />
              )}
            </div>
          </div>
        )}

        {tab === "Amenities" && (
          <div className="mt-6 animate-fade-up rounded-xl border border-border bg-white p-6 sm:p-8">
            <h3 className="mb-4 text-2xl font-extrabold text-navy">Amenities</h3>
            {listing.amenities?.length ? (
              <div className="flex flex-wrap gap-2">
                {listing.amenities.map((amenity) => (
                  <span
                    key={amenity.slug}
                    className="rounded-full border border-border px-4 py-2 text-sm text-navy"
                  >
                    {amenity.icon} {amenity.name}
                  </span>
                ))}
              </div>
            ) : (
              <EmptyPanel text="The seller has not listed any amenities" />
            )}
          </div>
        )}

        {tab === "Facilities" && (
          <div className="mt-6 animate-fade-up rounded-xl border border-border bg-white p-6 sm:p-8">
            <h3 className="mb-4 text-2xl font-extrabold text-navy">Nearby facilities</h3>
            {listing.facilities?.length ? (
              <div className="flex flex-wrap gap-2">
                {listing.facilities.map((facility) => (
                  <span
                    key={facility.slug}
                    className="rounded-full border border-border px-4 py-2 text-sm text-navy"
                  >
                    {facility.icon} {facility.name}
                  </span>
                ))}
              </div>
            ) : (
              <EmptyPanel text="The seller has not listed any nearby facilities" />
            )}
          </div>
        )}

        {tab === "Reviews" && <ListingReviews slug={listing.slug} />}
      </div>
    </div>
  );
}

function InfoRow({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="grid grid-cols-[140px_16px_1fr] gap-2 py-2 text-[15px]">
      <span className="font-semibold capitalize text-navy">{label}</span>
      <span className="text-muted-foreground">:</span>
      <span className="text-muted-foreground">{value}</span>
    </div>
  );
}

function EmptyPanel({ text }: { text: string }) {
  return (
    <div className="flex flex-col items-center justify-center py-12 text-muted-foreground">
      <MapPin className="mb-3 h-8 w-8 text-border" />
      <p className="text-sm">{text}</p>
    </div>
  );
}
