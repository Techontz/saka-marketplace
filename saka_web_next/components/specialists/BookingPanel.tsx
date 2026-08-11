"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import { CalendarDays, Check, ChevronLeft, Clock, Loader2 } from "lucide-react";
import { useState } from "react";

import { apiGet, apiSend } from "@/lib/api/browser";
import { useAuth } from "@/providers/AuthProvider";
import type { ApiBooking, ApiSlotDay, ApiSpecialistService } from "@/lib/types";

/**
 * Booking a specialist.
 *
 * Four steps in the SAKA card idiom — the same white panel, #DCE6EF border and
 * teal accent as the listing sidebar, because this sits alongside them.
 *
 * Two rules shape the whole component:
 *
 *   NOTHING IS CONFIRMED UNTIL THE SERVER SAYS SO. The success state is
 *   rendered from the created booking's own status, never optimistically. A
 *   customer told "confirmed" before a specialist has agreed is the single most
 *   damaging thing this flow could do — and a booking arrives PENDING.
 *
 *   NO SLOT IS INVENTED. Every time offered comes from the API, which derives
 *   it from the specialist's real working hours, real blocked periods and real
 *   existing bookings. A specialist who has configured nothing offers nothing,
 *   and this says so rather than showing a default nine-to-five.
 */

type Step = "service" | "date" | "details" | "done";

export function BookingPanel({
  slug,
  services,
  timezone,
}: {
  slug: string;
  services: ApiSpecialistService[];
  timezone: string;
}) {
  const { user } = useAuth();

  const [step, setStep] = useState<Step>("service");
  const [service, setService] = useState<ApiSpecialistService | null>(null);
  const [day, setDay] = useState<ApiSlotDay | null>(null);
  const [slot, setSlot] = useState<string | null>(null);

  const [name, setName] = useState(user?.full_name ?? "");
  const [phone, setPhone] = useState(user?.phone ?? "");
  const [email, setEmail] = useState(user?.email ?? "");
  const [note, setNote] = useState("");

  const [booking, setBooking] = useState<ApiBooking | null>(null);

  /*
   * Slots are fetched only once a service is chosen, and never cached.
   *
   * `staleTime: 0` plus a refetch on focus: a slot taken while the customer was
   * filling in their name must disappear before they submit, not after.
   */
  const calendar = useQuery({
    queryKey: ["specialist-slots", slug, service?.uuid],
    enabled: service !== null,
    staleTime: 0,
    refetchOnWindowFocus: true,
    queryFn: () =>
      apiGet<{ data: ApiSlotDay[]; meta: { has_availability: boolean; timezone: string } }>(
        `/specialists/${slug}/services/${service!.uuid}/slots`,
        { days: 14 },
      ),
  });

  const create = useMutation({
    mutationFn: () =>
      apiSend<{ data: ApiBooking }>("/bookings", "POST", {
        service_uuid: service?.uuid,
        date: day?.date,
        start_time: slot,
        customer_name: name.trim(),
        customer_phone: phone.trim(),
        customer_email: email.trim() || null,
        note: note.trim() || null,
      }).then((response) => response.data),
    onSuccess: (created) => {
      setBooking(created);
      setStep("done");
    },
  });

  const days = (calendar.data?.data ?? []).filter((entry) => entry.slots.length > 0);
  const hasAvailability = calendar.data?.meta.has_availability ?? false;

  if (services.length === 0) {
    return (
      <div className="rounded-[8px] border border-[#DCE6EF] bg-white p-5">
        <h3 className="text-[16px] font-bold text-[#17233C]">Booking</h3>
        <p className="mt-2 text-[13px] text-[#6B7280]">
          This specialist has not listed any bookable services yet. Use the contact details to get
          in touch directly.
        </p>
      </div>
    );
  }

  return (
    <div className="rounded-[8px] border border-[#DCE6EF] bg-white">
      <div className="flex items-center justify-between border-b border-[#DCE6EF] px-5 py-3.5">
        <h3 className="flex items-center gap-2 text-[16px] font-bold text-[#17233C]">
          <CalendarDays className="h-4 w-4 text-[#0B8E95]" />
          Book an appointment
        </h3>

        {step !== "service" && step !== "done" && (
          <button
            type="button"
            onClick={() => setStep(step === "details" ? "date" : "service")}
            className="flex items-center gap-1 text-[13px] font-semibold text-[#0B8E95] hover:underline"
          >
            <ChevronLeft className="h-3.5 w-3.5" />
            Back
          </button>
        )}
      </div>

      <div className="p-5">
        {/* ---- 1. service ---- */}
        {step === "service" && (
          <ul className="space-y-2">
            {services.map((option) => (
              <li key={option.uuid}>
                <button
                  type="button"
                  onClick={() => {
                    setService(option);
                    setDay(null);
                    setSlot(null);
                    setStep("date");
                  }}
                  className="w-full rounded-[5px] border border-[#DCE6EF] p-3 text-left transition hover:border-[#0B8E95] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0B8E95]/40"
                >
                  <span className="block text-[14px] font-semibold text-[#17233C]">
                    {option.name}
                  </span>
                  {option.description && (
                    <span className="mt-0.5 block text-[12px] text-[#6B7280]">
                      {option.description}
                    </span>
                  )}
                  <span className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-[#6B7280]">
                    <span className="flex items-center gap-1">
                      <Clock className="h-3.5 w-3.5 text-[#0B8E95]" />
                      {option.duration_minutes} min
                    </span>
                    <span>{option.mode_label}</span>
                    <span className="font-semibold text-[#0B8E95]">
                      {/* Null is "on enquiry", never "free". */}
                      {option.price
                        ? `${option.price.currency} ${option.price.amount.toLocaleString()}`
                        : "Price on enquiry"}
                    </span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}

        {/* ---- 2. date and time ---- */}
        {step === "date" && (
          <div>
            {calendar.isPending ? (
              <p className="flex items-center gap-2 text-[13px] text-[#6B7280]">
                <Loader2 className="h-4 w-4 animate-spin" />
                Checking availability…
              </p>
            ) : calendar.error ? (
              <p className="text-[13px] text-[#B42318]">
                We could not load availability. Please try again.
              </p>
            ) : !hasAvailability || days.length === 0 ? (
              /*
               * An honest empty state. The specialist has genuinely published
               * no availability for the next fortnight — inventing a grid here
               * would produce bookings they never agreed to keep.
               */
              <p className="text-[13px] text-[#6B7280]">
                No times are available in the next two weeks. Use the contact details to arrange
                something directly.
              </p>
            ) : (
              <>
                <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-3">
                  {days.map((entry) => {
                    const date = new Date(`${entry.date}T00:00:00`);
                    const selected = day?.date === entry.date;

                    return (
                      <button
                        key={entry.date}
                        type="button"
                        onClick={() => {
                          setDay(entry);
                          setSlot(null);
                        }}
                        className={`flex min-w-[64px] shrink-0 flex-col items-center rounded-[5px] border px-3 py-2 transition ${
                          selected
                            ? "border-[#0B8E95] bg-[#0B8E95]/5"
                            : "border-[#DCE6EF] hover:border-[#0B8E95]/50"
                        }`}
                      >
                        <span className="text-[11px] uppercase text-[#6B7280]">
                          {date.toLocaleDateString(undefined, { weekday: "short" })}
                        </span>
                        <span className="text-[16px] font-bold text-[#17233C]">
                          {date.getDate()}
                        </span>
                        <span className="text-[11px] text-[#6B7280]">
                          {date.toLocaleDateString(undefined, { month: "short" })}
                        </span>
                      </button>
                    );
                  })}
                </div>

                {day && (
                  <div className="mt-1 grid grid-cols-3 gap-2 sm:grid-cols-4">
                    {day.slots.map((entry) => (
                      <button
                        key={entry.start}
                        type="button"
                        onClick={() => {
                          setSlot(entry.start);
                          setStep("details");
                        }}
                        /* 44px minimum touch target. */
                        className={`h-11 rounded-[5px] border text-[13px] font-semibold transition ${
                          slot === entry.start
                            ? "border-[#0B8E95] bg-[#0B8E95] text-white"
                            : "border-[#DCE6EF] text-[#17233C] hover:border-[#0B8E95]"
                        }`}
                      >
                        {entry.start}
                      </button>
                    ))}
                  </div>
                )}

                <p className="mt-3 text-[11px] text-[#8B95A7]">
                  Times shown in {timezone.replace(/_/g, " ")}.
                </p>
              </>
            )}
          </div>
        )}

        {/* ---- 3. details ---- */}
        {step === "details" && (
          <form
            onSubmit={(event) => {
              event.preventDefault();
              create.mutate();
            }}
            className="space-y-3"
          >
            <p className="rounded-[5px] bg-[#F3F7FB] px-3 py-2 text-[12px] text-[#17233C]">
              <strong>{service?.name}</strong> · {day?.date} at {slot} · {service?.duration_minutes}{" "}
              min
            </p>

            <label className="block">
              <span className="mb-1 block text-[13px] font-medium text-[#17233C]">Your name</span>
              <input
                required
                minLength={2}
                maxLength={120}
                value={name}
                onChange={(event) => setName(event.target.value)}
                className="h-11 w-full rounded-[5px] border border-[#DCE6EF] px-3 text-[14px] focus:border-[#0B8E95] focus:outline-none"
              />
            </label>

            <label className="block">
              <span className="mb-1 block text-[13px] font-medium text-[#17233C]">
                Phone number
              </span>
              <input
                required
                type="tel"
                inputMode="tel"
                value={phone}
                onChange={(event) => setPhone(event.target.value)}
                placeholder="+255 7xx xxx xxx"
                className="h-11 w-full rounded-[5px] border border-[#DCE6EF] px-3 text-[14px] focus:border-[#0B8E95] focus:outline-none"
              />
              {/* Phone is required and email is not: a specialist rings the
                  customer back, and many people here read no email. */}
              <span className="mt-1 block text-[11px] text-[#8B95A7]">
                The specialist will call or message you on this number.
              </span>
            </label>

            <label className="block">
              <span className="mb-1 block text-[13px] font-medium text-[#17233C]">
                Email <span className="font-normal text-[#8B95A7]">(optional)</span>
              </span>
              <input
                type="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                className="h-11 w-full rounded-[5px] border border-[#DCE6EF] px-3 text-[14px] focus:border-[#0B8E95] focus:outline-none"
              />
            </label>

            <label className="block">
              <span className="mb-1 block text-[13px] font-medium text-[#17233C]">
                What do you need help with?{" "}
                <span className="font-normal text-[#8B95A7]">(optional)</span>
              </span>
              <textarea
                rows={3}
                maxLength={1000}
                value={note}
                onChange={(event) => setNote(event.target.value)}
                className="w-full rounded-[5px] border border-[#DCE6EF] px-3 py-2 text-[14px] focus:border-[#0B8E95] focus:outline-none"
              />
            </label>

            {create.error != null && (
              <p role="alert" className="rounded-[5px] bg-[#FEF3F2] px-3 py-2 text-[13px] text-[#B42318]">
                {/*
                  * The API's own message, which for the common failure is
                  * "That time is no longer available. Please choose another."
                  * — actionable, and free of any implementation detail.
                  */}
                {create.error instanceof Error
                  ? create.error.message
                  : "Something went wrong. Please try again."}
              </p>
            )}

            <button
              type="submit"
              disabled={create.isPending}
              className="flex h-11 w-full items-center justify-center gap-2 rounded-[5px] bg-[#0B8E95] text-[14px] font-semibold text-white transition hover:bg-[#0a7d83] disabled:opacity-60"
            >
              {create.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
              Request booking
            </button>

            {/* Said before they commit, not after. */}
            <p className="text-center text-[11px] text-[#8B95A7]">
              Nothing is charged now. The specialist confirms your appointment first.
            </p>
          </form>
        )}

        {/* ---- 4. done ---- */}
        {step === "done" && booking && (
          <div className="text-center">
            <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-[#0B8E95]/10 text-[#0B8E95]">
              <Check className="h-6 w-6" />
            </span>

            {/*
              * Rendered from the SERVER's status, never optimistically. A
              * booking arrives pending, so this says "requested" — telling a
              * customer it is confirmed before a specialist agreed would be a
              * promise SAKA cannot keep.
              */}
            <h4 className="mt-3 text-[16px] font-bold text-[#17233C]">
              {booking.awaits_specialist ? "Booking requested" : booking.status_label}
            </h4>

            <p className="mt-1 text-[13px] text-[#6B7280]">
              {booking.scheduled_date} at {booking.start_time} · {booking.service?.name}
            </p>

            <p className="mt-3 text-[12px] text-[#6B7280]">
              {booking.awaits_specialist
                ? "You will hear back once the specialist confirms."
                : "Your appointment is set."}
            </p>

            {user ? (
              <a
                href="/account/bookings"
                className="mt-4 inline-flex h-11 items-center justify-center rounded-[5px] border border-[#0B8E95] px-4 text-[13px] font-semibold text-[#0B8E95] transition hover:bg-[#0B8E95] hover:text-white"
              >
                View my bookings
              </a>
            ) : (
              /* A guest has no account to look it up in, so they are told how
                 they will actually hear back rather than sent to a sign-in. */
              <p className="mt-4 text-[12px] text-[#8B95A7]">
                We have sent your request. Keep an eye on {phone} for their reply.
              </p>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
