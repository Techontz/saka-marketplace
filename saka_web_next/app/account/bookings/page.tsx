"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CalendarDays } from "lucide-react";
import Link from "next/link";
import { useState } from "react";

import { apiGet, apiSend } from "@/lib/api/browser";
import type { ApiBooking, Paginated } from "@/lib/types";

/**
 * The customer's own appointments.
 *
 * CANCEL is the only action here, deliberately. Confirming, completing and
 * marking a no-show are the specialist's calls — a customer who could confirm
 * would be marking a professional's diary on their behalf, and the API has no
 * endpoint that would let them.
 */
export default function AccountBookingsPage() {
  const queryClient = useQueryClient();
  const [cancelling, setCancelling] = useState<string | null>(null);

  const bookings = useQuery({
    queryKey: ["account-bookings"],
    queryFn: () => apiGet<Paginated<ApiBooking>>("/account/bookings"),
  });

  const cancel = useMutation({
    mutationFn: (uuid: string) => apiSend(`/account/bookings/${uuid}/cancel`, "POST"),
    onSuccess: async () => {
      setCancelling(null);
      await queryClient.invalidateQueries({ queryKey: ["account-bookings"] });
    },
  });

  const rows = bookings.data?.data ?? [];

  return (
    <div>
      <h1 className="text-2xl font-extrabold text-navy">My bookings</h1>
      <p className="mt-1 text-sm text-muted-foreground">
        Appointments you have requested with specialists on SAKA.
      </p>

      <div className="mt-6">
        {bookings.isPending ? (
          <div className="space-y-3">
            {[0, 1, 2].map((row) => (
              <div key={row} className="h-24 animate-pulse rounded-[8px] bg-white" />
            ))}
          </div>
        ) : bookings.error ? (
          <div className="rounded-[8px] border border-border bg-white px-5 py-10 text-center">
            <p className="text-sm text-muted-foreground">We could not load your bookings.</p>
            <button
              type="button"
              onClick={() => void bookings.refetch()}
              className="mt-3 h-11 rounded-[5px] border border-teal px-4 text-sm font-semibold text-teal"
            >
              Try again
            </button>
          </div>
        ) : rows.length === 0 ? (
          <div className="rounded-[8px] border border-border bg-white px-5 py-12 text-center">
            <CalendarDays aria-hidden className="mx-auto mb-3 h-8 w-8 text-muted-foreground/60" />
            <p className="text-sm font-medium text-navy">No bookings yet</p>
            <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
              Book a lawyer, tutor, engineer or other professional and your appointments will
              appear here.
            </p>
            <Link
              href="/specialists"
              className="mt-4 inline-flex h-11 items-center justify-center rounded-[5px] bg-teal px-5 text-sm font-semibold text-white"
            >
              Find a specialist
            </Link>
          </div>
        ) : (
          <ul className="space-y-3">
            {rows.map((booking) => (
              <li
                key={booking.uuid}
                className="rounded-[8px] border border-[#DCE6EF] bg-white p-4"
              >
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="text-[15px] font-bold text-[#17233C]">
                        {booking.service?.name ?? "Appointment"}
                      </p>
                      <StatusBadge booking={booking} />
                    </div>

                    {booking.specialist && (
                      <Link
                        href={`/listings/${booking.specialist.slug}`}
                        className="mt-0.5 block text-[13px] text-teal hover:underline"
                      >
                        {booking.specialist.title}
                      </Link>
                    )}

                    <p className="mt-1 text-[13px] text-[#6B7280]">
                      {new Date(`${booking.scheduled_date}T00:00:00`).toLocaleDateString(undefined, {
                        weekday: "long",
                        day: "numeric",
                        month: "long",
                      })}{" "}
                      at {booking.start_time} – {booking.end_time}
                    </p>

                    {/* Only when it differs from the customer's own zone, so
                        the common case is not cluttered. */}
                    <p className="mt-0.5 text-[11px] text-[#8B95A7]">
                      {booking.timezone.replace(/_/g, " ")}
                    </p>

                    {booking.specialist_note && (
                      <p className="mt-2 rounded-[5px] bg-[#F3F7FB] px-3 py-2 text-[12px] text-[#17233C]">
                        {booking.specialist_note}
                      </p>
                    )}
                  </div>

                  {booking.is_cancellable && (
                    <button
                      type="button"
                      onClick={() => setCancelling(booking.uuid)}
                      className="h-11 shrink-0 rounded-[5px] border border-[#DCE6EF] px-4 text-[13px] font-semibold text-[#6B7280] transition hover:border-[#B42318] hover:text-[#B42318]"
                    >
                      Cancel
                    </button>
                  )}
                </div>

                {cancelling === booking.uuid && (
                  <div className="mt-3 rounded-[5px] bg-[#FEF3F2] p-3">
                    <p className="text-[13px] text-[#B42318]">
                      Cancel this appointment? The specialist will be told.
                    </p>
                    <div className="mt-2 flex gap-2">
                      <button
                        type="button"
                        disabled={cancel.isPending}
                        onClick={() => cancel.mutate(booking.uuid)}
                        className="h-11 rounded-[5px] bg-[#B42318] px-4 text-[13px] font-semibold text-white disabled:opacity-60"
                      >
                        Yes, cancel
                      </button>
                      <button
                        type="button"
                        onClick={() => setCancelling(null)}
                        className="h-11 rounded-[5px] border border-[#DCE6EF] px-4 text-[13px] font-semibold text-[#6B7280]"
                      >
                        Keep it
                      </button>
                    </div>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

/**
 * The status, in the customer's language.
 *
 * "Awaiting confirmation" rather than "Pending", because the customer's
 * question is whether the specialist has said yes — and green is reserved for
 * an appointment that is genuinely agreed.
 */
function StatusBadge({ booking }: { booking: ApiBooking }) {
  const tone =
    booking.status === "confirmed"
      ? "bg-teal/10 text-teal"
      : booking.status === "pending"
        ? "bg-orange/10 text-orange"
        : booking.status === "declined" || booking.status === "cancelled"
          ? "bg-[#FEF3F2] text-[#B42318]"
          : "bg-muted text-muted-foreground";

  return (
    <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${tone}`}>
      {booking.status_label}
    </span>
  );
}
