"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CalendarDays, Clock, Plus } from "lucide-react";
import Link from "next/link";
import { Suspense, useState } from "react";

import {
  Badge,
  Button,
  Card,
  Checkbox,
  Field,
  FormError,
  Input,
  ListState,
  Modal,
  PageHeader,
  Select,
  Textarea,
  type BadgeTone,
} from "@/components/vendor/ui";
import { apiGet, apiSend } from "@/lib/vendor/api/browser";
import { formatCount } from "@/lib/vendor/format";
import { useUrlFilters } from "@/lib/vendor/hooks";
import type {
  Envelope,
  ListingSummary,
  Paginated,
  SpecialistAvailability,
  SpecialistOptions,
  SpecialistService,
  VendorBooking,
} from "@/lib/vendor/api/types";

/**
 * The specialist's practice: services, working hours and the diary.
 *
 * A specialist IS a listing, so this screen does not duplicate the profile
 * editor — name, photo, bio, category attributes and location are all edited
 * where every other listing is. What lives here is the three things a listing
 * cannot express: what they sell, when they are free, and who has booked them.
 *
 * Everything is the existing vendor kit — the same Card, Table, Badge and Modal
 * as Listings and Inquiries — so this reads as another part of the dashboard
 * rather than a calendar application bolted onto it.
 */

type Tab = "services" | "availability" | "bookings";

function bookingTone(status: string): BadgeTone {
  switch (status) {
    case "confirmed":
      return "ok";
    case "pending":
      return "warn";
    case "declined":
    case "cancelled":
    case "no_show":
      return "danger";
    default:
      return "muted";
  }
}

export default function SpecialistPage() {
  return (
    <Suspense fallback={null}>
      <SpecialistView />
    </Suspense>
  );
}

function SpecialistView() {
  const { filters, setFilters } = useUrlFilters({ listing: "", tab: "services" });
  const tab = (filters.tab || "services") as Tab;

  const options = useQuery({
    queryKey: ["specialist-options"],
    queryFn: () => apiGet<Envelope<SpecialistOptions>>("/seller/specialist/options").then((r) => r.data),
    staleTime: 30 * 60 * 1000,
  });

  /*
   * The vendor's specialist listings.
   *
   * Filtered on the CATEGORY LINEAGE rather than a flag, so a subcategory an
   * administrator adds to the Specialists vertical appears here with no deploy
   * — the same reason the public page does it that way.
   */
  const listings = useQuery({
    queryKey: ["vendor-specialist-listings"],
    queryFn: () =>
      apiGet<Paginated<ListingSummary>>("/seller/listings", { per_page: 100 }).then((response) =>
        response.data.filter(
          (listing) =>
            listing.category?.parent?.slug === "specialists" ||
            listing.category?.slug === "specialists",
        ),
      ),
  });

  const profiles = listings.data ?? [];
  const selected = profiles.find((listing) => listing.uuid === filters.listing) ?? profiles[0];

  if (listings.isPending) {
    return <Card><div className="h-40 animate-pulse" /></Card>;
  }

  if (profiles.length === 0) {
    /*
     * A real empty state with a real route out.
     *
     * A specialist profile is created the same way any listing is — choosing a
     * category under Specialists. Sending them to a "create specialist profile"
     * form that does not exist would be the dead end this whole phase avoids.
     */
    return (
      <>
        <PageHeader
          title="Specialist"
          description="Offer consultations, lessons or professional services that customers can book."
        />
        <Card>
          <div className="px-6 py-14 text-center">
            <CalendarDays aria-hidden className="mx-auto mb-3 h-8 w-8 text-ink-faint" />
            <p className="text-sm font-medium text-ink">No specialist profile yet</p>
            <p className="mx-auto mt-1 max-w-md text-sm text-ink-soft">
              Create a listing and choose a category under <strong>Specialists</strong> — lawyer,
              tutor, engineer, developer and so on. Your services and availability appear here once
              it exists.
            </p>
            <Link href="/vendor/listings/new">
              <Button className="mt-4" variant="primary">
                <Plus aria-hidden className="h-4 w-4" />
                Create a specialist profile
              </Button>
            </Link>
          </div>
        </Card>
      </>
    );
  }

  return (
    <>
      <PageHeader
        title="Specialist"
        description="Your services, working hours and appointments."
        actions={
          profiles.length > 1 ? (
            <Select
              aria-label="Choose a profile"
              className="w-auto"
              value={selected?.uuid ?? ""}
              onChange={(event) => setFilters({ listing: event.target.value })}
            >
              {profiles.map((listing) => (
                <option key={listing.uuid} value={listing.uuid}>
                  {listing.title}
                </option>
              ))}
            </Select>
          ) : undefined
        }
      />

      <div className="mb-4 flex flex-wrap gap-2">
        {(["services", "availability", "bookings"] as Tab[]).map((key) => (
          <button
            key={key}
            type="button"
            onClick={() => setFilters({ tab: key })}
            className={`h-10 rounded-[var(--radius-control)] px-4 text-sm font-medium capitalize transition ${
              tab === key
                ? "bg-brand text-white"
                : "border border-line bg-surface text-ink-soft hover:text-ink"
            }`}
          >
            {key}
          </button>
        ))}
      </div>

      {selected && tab === "services" && (
        <ServicesTab listingUuid={selected.uuid} options={options.data} />
      )}
      {selected && tab === "availability" && (
        <AvailabilityTab listingUuid={selected.uuid} options={options.data} />
      )}
      {selected && tab === "bookings" && (
        <BookingsTab listingUuid={selected.uuid} options={options.data} />
      )}
    </>
  );
}

// ------------------------------------------------------------------ services

type ServiceDraft = {
  uuid?: string;
  name: string;
  description: string;
  duration_minutes: string;
  buffer_minutes: string;
  price_amount: string;
  mode: string;
  is_active: boolean;
};

const EMPTY_SERVICE: ServiceDraft = {
  name: "",
  description: "",
  duration_minutes: "60",
  buffer_minutes: "0",
  price_amount: "",
  mode: "both",
  is_active: true,
};

function ServicesTab({
  listingUuid,
  options,
}: {
  listingUuid: string;
  options?: SpecialistOptions;
}) {
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState<ServiceDraft | null>(null);
  const [deleting, setDeleting] = useState<SpecialistService | null>(null);

  const key = ["specialist-services", listingUuid];

  const services = useQuery({
    queryKey: key,
    queryFn: () =>
      apiGet<Envelope<SpecialistService[]>>(`/seller/specialist/${listingUuid}/services`).then(
        (r) => r.data,
      ),
  });

  const save = useMutation({
    mutationFn: (draft: ServiceDraft) => {
      const body = {
        name: draft.name,
        description: draft.description || null,
        duration_minutes: Number(draft.duration_minutes),
        buffer_minutes: Number(draft.buffer_minutes || 0),
        // Empty means "price on enquiry", which is a null — not a zero, which
        // would advertise the work as free.
        price_amount: draft.price_amount === "" ? null : Number(draft.price_amount),
        mode: draft.mode,
        is_active: draft.is_active,
      };

      return draft.uuid
        ? apiSend(`/seller/specialist/services/${draft.uuid}`, "PATCH", body)
        : apiSend(`/seller/specialist/${listingUuid}/services`, "POST", body);
    },
    onSuccess: async () => {
      setEditing(null);
      await queryClient.invalidateQueries({ queryKey: key });
    },
  });

  const remove = useMutation({
    mutationFn: (uuid: string) => apiSend(`/seller/specialist/services/${uuid}`, "DELETE"),
    onSuccess: async () => {
      setDeleting(null);
      await queryClient.invalidateQueries({ queryKey: key });
    },
  });

  const rows = services.data ?? [];

  return (
    <>
      <Card>
        <div className="flex items-center justify-between border-b border-line px-4 py-3">
          <div>
            <h2 className="text-sm font-semibold text-ink">Services</h2>
            <p className="text-xs text-ink-soft">What customers can book with you.</p>
          </div>
          <Button variant="primary" size="sm" onClick={() => setEditing({ ...EMPTY_SERVICE })}>
            <Plus aria-hidden className="h-4 w-4" />
            New service
          </Button>
        </div>

        <ListState
          isLoading={services.isPending}
          error={services.error}
          isEmpty={rows.length === 0}
          onRetry={() => void services.refetch()}
          emptyTitle="No services yet"
          emptyDescription="Add a consultation, lesson or assessment that customers can book a time for."
          emptyAction={
            <Button variant="primary" onClick={() => setEditing({ ...EMPTY_SERVICE })}>
              Add your first service
            </Button>
          }
        >
          <ul className="divide-y divide-line">
            {rows.map((service) => (
              <li key={service.uuid} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-semibold text-ink">{service.name}</p>
                    <Badge tone={service.is_active ? "ok" : "muted"}>
                      {service.is_active ? "Bookable" : "Not bookable"}
                    </Badge>
                  </div>

                  {service.description && (
                    <p className="mt-0.5 text-xs text-ink-soft">{service.description}</p>
                  )}

                  <p className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-soft">
                    <span className="flex items-center gap-1">
                      <Clock aria-hidden className="h-3.5 w-3.5" />
                      {service.duration_minutes} min
                      {service.buffer_minutes ? ` (+${service.buffer_minutes} buffer)` : ""}
                    </span>
                    <span>{service.mode_label}</span>
                    <span className="font-medium text-ink">
                      {service.price
                        ? `${service.price.currency} ${service.price.amount.toLocaleString()}`
                        : "Price on enquiry"}
                    </span>
                    {service.bookings_count !== undefined && service.bookings_count > 0 && (
                      <span>{formatCount(service.bookings_count)} booked</span>
                    )}
                  </p>
                </div>

                <div className="flex shrink-0 gap-2">
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() =>
                      setEditing({
                        uuid: service.uuid,
                        name: service.name,
                        description: service.description ?? "",
                        duration_minutes: String(service.duration_minutes),
                        buffer_minutes: String(service.buffer_minutes ?? 0),
                        price_amount: service.price ? String(service.price.amount) : "",
                        mode: service.mode,
                        is_active: service.is_active,
                      })
                    }
                  >
                    Edit
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setDeleting(service)}>
                    Delete
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        </ListState>
      </Card>

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing?.uuid ? "Edit service" : "New service"}
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditing(null)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              loading={save.isPending}
              disabled={!editing || editing.name.trim().length < 2}
              onClick={() => editing && save.mutate(editing)}
            >
              Save
            </Button>
          </>
        }
      >
        {editing && (
          <div className="space-y-4">
            <FormError error={save.error} />

            <Field label="Service name" required>
              <Input
                value={editing.name}
                maxLength={150}
                placeholder="Initial consultation"
                onChange={(event) => setEditing({ ...editing, name: event.target.value })}
              />
            </Field>

            <Field label="Description">
              <Textarea
                rows={2}
                maxLength={2000}
                value={editing.description}
                onChange={(event) => setEditing({ ...editing, description: event.target.value })}
              />
            </Field>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Field label="Duration (minutes)" required hint="What the customer is booking.">
                <Input
                  type="number"
                  min={5}
                  max={480}
                  value={editing.duration_minutes}
                  onChange={(event) =>
                    setEditing({ ...editing, duration_minutes: event.target.value })
                  }
                />
              </Field>

              <Field
                label="Buffer (minutes)"
                hint="Kept free after each appointment. Not shown to customers."
              >
                <Input
                  type="number"
                  min={0}
                  max={120}
                  value={editing.buffer_minutes}
                  onChange={(event) =>
                    setEditing({ ...editing, buffer_minutes: event.target.value })
                  }
                />
              </Field>
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Field label="Price (TZS)" hint="Leave blank for 'price on enquiry'.">
                <Input
                  type="number"
                  min={0}
                  value={editing.price_amount}
                  placeholder="50000"
                  onChange={(event) =>
                    setEditing({ ...editing, price_amount: event.target.value })
                  }
                />
              </Field>

              <Field label="Delivered">
                <Select
                  value={editing.mode}
                  onChange={(event) => setEditing({ ...editing, mode: event.target.value })}
                >
                  {(options?.modes ?? []).map((mode) => (
                    <option key={mode.value} value={mode.value}>
                      {mode.label}
                    </option>
                  ))}
                </Select>
              </Field>
            </div>

            <Checkbox
              label="Bookable — customers can request this service"
              checked={editing.is_active}
              onChange={(event) => setEditing({ ...editing, is_active: event.target.checked })}
            />
          </div>
        )}
      </Modal>

      <Modal
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title="Delete this service?"
        description="If anyone has booked it, deactivate it instead — that stops new bookings without cancelling appointments you have already agreed."
        footer={
          <>
            <Button variant="ghost" onClick={() => setDeleting(null)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              loading={remove.isPending}
              onClick={() => deleting && remove.mutate(deleting.uuid)}
            >
              Delete
            </Button>
          </>
        }
      >
        <FormError error={remove.error} />
      </Modal>
    </>
  );
}

// -------------------------------------------------------------- availability

function AvailabilityTab({
  listingUuid,
  options,
}: {
  listingUuid: string;
  options?: SpecialistOptions;
}) {
  const queryClient = useQueryClient();
  const key = ["specialist-availability", listingUuid];

  const availability = useQuery({
    queryKey: key,
    queryFn: () =>
      apiGet<Envelope<SpecialistAvailability>>(
        `/seller/specialist/${listingUuid}/availability`,
      ).then((r) => r.data),
  });

  const [draft, setDraft] = useState<{ weekday: number; start_time: string; end_time: string }[] | null>(
    null,
  );

  const rows = draft ?? (availability.data?.hours ?? []).map((window) => ({
    weekday: window.weekday,
    start_time: window.start_time,
    end_time: window.end_time,
  }));

  const save = useMutation({
    mutationFn: () =>
      apiSend(`/seller/specialist/${listingUuid}/availability`, "PUT", { hours: rows }),
    onSuccess: async () => {
      setDraft(null);
      await queryClient.invalidateQueries({ queryKey: key });
    },
  });

  const weekdays = options?.weekdays ?? [];

  return (
    <Card>
      <div className="border-b border-line px-4 py-3">
        <h2 className="text-sm font-semibold text-ink">Working hours</h2>
        <p className="text-xs text-ink-soft">
          Customers can only book inside these windows. Times are in{" "}
          {(availability.data?.timezone ?? "Africa/Dar_es_Salaam").replace(/_/g, " ")}.
        </p>
      </div>

      <div className="space-y-3 p-4">
        <FormError error={save.error} />

        {rows.length === 0 && (
          /*
           * Stated plainly. With no windows the API offers NO slots — it does
           * not fall back to a nine-to-five — so a specialist who has not set
           * hours is genuinely unbookable and needs to know.
           */
          <p className="rounded-[var(--radius-control)] bg-warn-soft px-3 py-2 text-xs text-warn">
            You have no working hours set, so nobody can book you yet. Add at least one window.
          </p>
        )}

        <ul className="space-y-2">
          {rows.map((window, index) => (
            <li key={index} className="flex flex-wrap items-end gap-2">
              <div className="min-w-[140px] flex-1">
                <Select
                  aria-label="Day"
                  value={String(window.weekday)}
                  onChange={(event) => {
                    const next = [...rows];
                    next[index] = { ...window, weekday: Number(event.target.value) };
                    setDraft(next);
                  }}
                >
                  {weekdays.map((day) => (
                    <option key={day.value} value={day.value}>
                      {day.label}
                    </option>
                  ))}
                </Select>
              </div>

              <Input
                aria-label="Start"
                type="time"
                className="w-[120px]"
                value={window.start_time}
                onChange={(event) => {
                  const next = [...rows];
                  next[index] = { ...window, start_time: event.target.value };
                  setDraft(next);
                }}
              />

              <Input
                aria-label="End"
                type="time"
                className="w-[120px]"
                value={window.end_time}
                onChange={(event) => {
                  const next = [...rows];
                  next[index] = { ...window, end_time: event.target.value };
                  setDraft(next);
                }}
              />

              <Button
                size="sm"
                variant="ghost"
                onClick={() => setDraft(rows.filter((_, i) => i !== index))}
              >
                Remove
              </Button>
            </li>
          ))}
        </ul>

        <div className="flex flex-wrap gap-2 pt-1">
          <Button
            variant="secondary"
            size="sm"
            onClick={() =>
              setDraft([...rows, { weekday: 1, start_time: "09:00", end_time: "17:00" }])
            }
          >
            <Plus aria-hidden className="h-4 w-4" />
            Add a window
          </Button>

          <Button
            variant="primary"
            size="sm"
            loading={save.isPending}
            // Only offered once something has changed, so the button is never
            // a no-op the vendor has to guess about.
            disabled={draft === null}
            onClick={() => save.mutate()}
          >
            Save hours
          </Button>
        </div>

        <p className="text-xs text-ink-faint">
          Split a day into two windows to keep a lunch break free — for example 09:00–13:00 and
          14:00–17:00.
        </p>
      </div>
    </Card>
  );
}

// ------------------------------------------------------------------ bookings

function BookingsTab({
  listingUuid,
  options,
}: {
  listingUuid: string;
  options?: SpecialistOptions;
}) {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState("");
  const key = ["specialist-bookings", listingUuid, status];

  const bookings = useQuery({
    queryKey: key,
    queryFn: () =>
      apiGet<{ data: VendorBooking[]; meta: { counts: { pending: number; upcoming: number } } }>(
        `/seller/specialist/${listingUuid}/bookings`,
        { status: status || undefined },
      ),
  });

  const move = useMutation({
    mutationFn: ({ uuid, next }: { uuid: string; next: string }) =>
      apiSend(`/seller/specialist/bookings/${uuid}/transition`, "POST", { status: next }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["specialist-bookings", listingUuid] });
    },
  });

  const rows = bookings.data?.data ?? [];
  const counts = bookings.data?.meta.counts;

  /** What this booking may legally become, straight from the API's own table. */
  const transitionsFor = (booking: VendorBooking): string[] =>
    options?.statuses.find((entry) => entry.value === booking.status)?.allowed_transitions ?? [];

  const labelFor = (value: string): string =>
    options?.statuses.find((entry) => entry.value === value)?.label ?? value;

  return (
    <>
      {counts && (
        <div className="mb-4 grid gap-3 sm:grid-cols-2">
          {/* Real counts from real rows — both are clickable through to the
              list they describe. */}
          <Card>
            <button
              type="button"
              onClick={() => setStatus("pending")}
              className="w-full px-4 py-3 text-left"
            >
              <p className="text-[11px] font-semibold tracking-wide text-ink-soft uppercase">
                Awaiting your reply
              </p>
              <p className="mt-1 text-2xl font-semibold text-ink">{formatCount(counts.pending)}</p>
            </button>
          </Card>
          <Card>
            <button
              type="button"
              onClick={() => setStatus("confirmed")}
              className="w-full px-4 py-3 text-left"
            >
              <p className="text-[11px] font-semibold tracking-wide text-ink-soft uppercase">
                Upcoming confirmed
              </p>
              <p className="mt-1 text-2xl font-semibold text-ink">{formatCount(counts.upcoming)}</p>
            </button>
          </Card>
        </div>
      )}

      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
          <h2 className="text-sm font-semibold text-ink">Appointments</h2>
          <Select
            aria-label="Filter by status"
            className="w-auto"
            value={status}
            onChange={(event) => setStatus(event.target.value)}
          >
            <option value="">All</option>
            {(options?.statuses ?? []).map((entry) => (
              <option key={entry.value} value={entry.value}>
                {entry.label}
              </option>
            ))}
          </Select>
        </div>

        <FormError error={move.error} />

        <ListState
          isLoading={bookings.isPending}
          error={bookings.error}
          isEmpty={rows.length === 0}
          onRetry={() => void bookings.refetch()}
          emptyTitle={status ? "Nothing with that status" : "No bookings yet"}
          emptyDescription={
            status
              ? "Try a different filter."
              : "Once customers book one of your services, their appointments appear here."
          }
        >
          <ul className="divide-y divide-line">
            {rows.map((booking) => (
              <li key={booking.uuid} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-semibold text-ink">
                      {booking.service?.name ?? "Appointment"}
                    </p>
                    <Badge tone={bookingTone(booking.status)}>{booking.status_label}</Badge>
                  </div>

                  <p className="mt-0.5 text-xs text-ink-soft">
                    {new Date(`${booking.scheduled_date}T00:00:00`).toLocaleDateString(undefined, {
                      weekday: "short",
                      day: "numeric",
                      month: "short",
                    })}{" "}
                    · {booking.start_time}–{booking.end_time}
                  </p>

                  {booking.customer && (
                    <p className="mt-1 text-xs text-ink">
                      {booking.customer.name} ·{" "}
                      {/* A tel: link, because the specialist's next action is
                          almost always to ring them. */}
                      <a href={`tel:${booking.customer.phone}`} className="text-brand hover:underline">
                        {booking.customer.phone}
                      </a>
                      {booking.customer.email && ` · ${booking.customer.email}`}
                    </p>
                  )}

                  {booking.customer_note && (
                    <p className="mt-2 rounded-[var(--radius-control)] bg-muted-soft px-3 py-2 text-xs text-ink-soft">
                      {booking.customer_note}
                    </p>
                  )}
                </div>

                <div className="flex shrink-0 flex-wrap gap-2">
                  {/*
                    * Only the moves the API will actually accept.
                    *
                    * Derived from BookingStatus::allowedTransitions(), so a
                    * button is never offered that would return a 409 — the same
                    * approach the admin listing screen takes.
                    */}
                  {transitionsFor(booking).map((next) => (
                    <Button
                      key={next}
                      size="sm"
                      variant={next === "confirmed" ? "primary" : "secondary"}
                      loading={move.isPending && move.variables?.uuid === booking.uuid}
                      onClick={() => move.mutate({ uuid: booking.uuid, next })}
                    >
                      {labelFor(next)}
                    </Button>
                  ))}
                </div>
              </li>
            ))}
          </ul>
        </ListState>
      </Card>
    </>
  );
}
