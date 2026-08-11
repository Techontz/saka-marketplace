"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import { useState } from "react";
import { CheckCircle2, Flag, Loader2, X } from "lucide-react";

import { ApiError } from "@/lib/api/errors";
import { apiGet, apiSend } from "@/lib/api/browser";
import { useAuth } from "@/providers/AuthProvider";

/**
 * "Report this listing".
 *
 * Open to guests, because the person best placed to report a scam is the one
 * who just replied to it, and making them register first is how a marketplace
 * stops hearing about its worst listings. The API rate-limits and de-duplicates
 * instead — see ListingReportController.
 *
 * The reason list comes from `/listing-report-reasons` rather than living here:
 * moderation vocabulary changes with policy, and a hardcoded copy would drift
 * from what the API will actually accept and start 422-ing silently.
 */
export function ReportListingButton({
  slug,
  className,
}: {
  slug: string;
  className?: string;
}) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  const [details, setDetails] = useState("");
  const [email, setEmail] = useState("");

  const { user, isAuthenticated } = useAuth();

  const reasons = useQuery({
    queryKey: ["listing-report-reasons"],
    queryFn: () => apiGet<{ data: { value: string; label: string }[] }>("/listing-report-reasons"),
    // Policy vocabulary; it does not change between page views.
    staleTime: 60 * 60 * 1000,
    enabled: open,
  });

  const send = useMutation({
    mutationFn: () =>
      apiSend(`/listings/${slug}/report`, "POST", {
        reason,
        details: details || undefined,
        contact_email: isAuthenticated ? undefined : email || undefined,
      }),
  });

  const close = () => {
    setOpen(false);
    // Reset only after a successful send, so a validation failure does not
    // throw away what the reporter typed.
    if (send.isSuccess) {
      send.reset();
      setReason("");
      setDetails("");
      setEmail("");
    }
  };

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className={
          className ??
          "inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-muted-foreground transition hover:text-orange"
        }
      >
        <Flag className="h-4 w-4" />
        Report
      </button>

      {open && (
        <div
          className="fixed inset-0 z-[70] flex items-end justify-center bg-navy/50 p-0 sm:items-center sm:p-6"
          role="dialog"
          aria-modal="true"
          aria-label="Report this listing"
          onClick={close}
        >
          <div
            className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-t-2xl bg-white p-6 shadow-2xl sm:rounded-2xl"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="mb-4 flex items-start justify-between gap-4">
              <div>
                <h2 className="text-xl font-extrabold text-navy">Report this listing</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                  Our moderation team reads every report. The seller is never told who reported them.
                </p>
              </div>
              <button
                type="button"
                onClick={close}
                aria-label="Close"
                className="shrink-0 text-muted-foreground transition hover:text-navy"
              >
                <X className="h-5 w-5" />
              </button>
            </div>

            {send.isSuccess ? (
              <div className="py-8 text-center">
                <CheckCircle2 className="mx-auto mb-3 h-12 w-12 text-teal" />
                <p className="text-lg font-bold text-navy">Thank you</p>
                <p className="mt-1 text-sm text-muted-foreground">
                  We will review this listing. You will not normally hear back unless we need more
                  detail.
                </p>
                <button
                  type="button"
                  onClick={close}
                  className="mt-5 rounded-full bg-teal px-6 py-2.5 text-sm font-semibold text-white transition hover:opacity-90"
                >
                  Close
                </button>
              </div>
            ) : (
              <form
                onSubmit={(event) => {
                  event.preventDefault();
                  send.mutate();
                }}
                className="space-y-4"
              >
                <fieldset>
                  <legend className="mb-2 text-sm font-semibold text-navy">
                    What is wrong with it?
                  </legend>

                  {reasons.isPending ? (
                    <p className="flex items-center gap-2 py-4 text-sm text-muted-foreground">
                      <Loader2 className="h-4 w-4 animate-spin text-teal" />
                      Loading reasons…
                    </p>
                  ) : (
                    <div className="space-y-2">
                      {(reasons.data?.data ?? []).map((option) => (
                        <label
                          key={option.value}
                          className="flex cursor-pointer items-center gap-3 rounded-lg border border-border px-3 py-2.5 text-sm text-navy transition hover:border-teal"
                        >
                          <input
                            type="radio"
                            name="reason"
                            value={option.value}
                            checked={reason === option.value}
                            onChange={() => setReason(option.value)}
                            className="h-4 w-4 accent-teal"
                            required
                          />
                          {option.label}
                        </label>
                      ))}
                    </div>
                  )}
                </fieldset>

                <div>
                  <label htmlFor="report-details" className="mb-1.5 block text-sm font-semibold text-navy">
                    Anything else? <span className="font-normal text-muted-foreground">(optional)</span>
                  </label>
                  <textarea
                    id="report-details"
                    value={details}
                    onChange={(event) => setDetails(event.target.value)}
                    rows={3}
                    maxLength={1000}
                    placeholder="What made you report this? Anything specific helps us act faster."
                    className="w-full rounded-lg border border-border px-3 py-2 text-sm outline-none focus:border-teal"
                  />
                </div>

                {!isAuthenticated && (
                  <div>
                    <label htmlFor="report-email" className="mb-1.5 block text-sm font-semibold text-navy">
                      Your email <span className="font-normal text-muted-foreground">(optional)</span>
                    </label>
                    <input
                      id="report-email"
                      type="email"
                      value={email}
                      onChange={(event) => setEmail(event.target.value)}
                      placeholder="Only so we can come back to you if we need more detail"
                      className="w-full rounded-lg border border-border px-3 py-2 text-sm outline-none focus:border-teal"
                    />
                  </div>
                )}

                {isAuthenticated && user?.email && (
                  <p className="text-xs text-muted-foreground">
                    Filed as {user.email}. The seller will not see this.
                  </p>
                )}

                {send.error && (
                  <p className="rounded-lg bg-orange/10 px-3 py-2 text-sm text-orange">
                    {send.error instanceof ApiError
                      ? send.error.message
                      : "Could not send that report. Please try again."}
                  </p>
                )}

                <div className="flex gap-3 pt-1">
                  <button
                    type="button"
                    onClick={close}
                    className="flex-1 rounded-full border-2 border-border px-5 py-2.5 text-sm font-semibold text-navy transition hover:border-teal hover:text-teal"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={!reason || send.isPending}
                    className="flex flex-1 items-center justify-center gap-2 rounded-full bg-orange px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-50"
                  >
                    {send.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                    Send report
                  </button>
                </div>
              </form>
            )}
          </div>
        </div>
      )}
    </>
  );
}
