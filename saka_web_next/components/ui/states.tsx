"use client";

import { AlertTriangle, Loader2, SearchX } from "lucide-react";
import type { ReactNode } from "react";

import { ApiError } from "@/lib/api/errors";
import { Logo } from "@/components/ui/Logo";

/**
 * Loading, empty and error, in the marketplace's own visual language.
 *
 * Every screen in this milestone uses these three, so the behaviour is
 * consistent: skeletons that match the shape of what is loading, empty states
 * that say what to do next, and errors that surface `request_id` — the only
 * thing that makes a customer's bug report joinable to a server log.
 */

export function CardSkeleton({ count = 8, height = 435 }: { count?: number; height?: number }) {
  return (
    <>
      {Array.from({ length: count }).map((_, index) => (
        <div
          key={index}
          className="animate-pulse rounded-[8px] border border-[#DCE6EF] bg-white"
          style={{ height }}
        >
          <div className="h-[205px] rounded-t-[8px] bg-[#EEF4FF]" />
          <div className="space-y-3 p-4">
            <div className="h-4 w-4/5 rounded bg-[#EEF4FF]" />
            <div className="h-4 w-3/5 rounded bg-[#EEF4FF]" />
            <div className="h-4 w-2/5 rounded bg-[#EEF4FF]" />
          </div>
        </div>
      ))}
    </>
  );
}

export function RowSkeleton({ count = 4 }: { count?: number }) {
  return (
    <>
      {Array.from({ length: count }).map((_, index) => (
        <div key={index} className="animate-pulse rounded-xl border border-border bg-white p-5">
          <div className="h-4 w-1/3 rounded bg-[#EEF4FF]" />
          <div className="mt-3 h-4 w-2/3 rounded bg-[#EEF4FF]" />
        </div>
      ))}
    </>
  );
}

export function Spinner({ label }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-2 py-12 text-muted-foreground">
      <Loader2 className="h-5 w-5 animate-spin text-teal" />
      {label && <span className="text-sm">{label}</span>}
    </div>
  );
}

/**
 * A whole-screen wait, with the brand mark.
 *
 * Used where the page has nothing else on it yet — the account area while the
 * session resolves. A bare spinner on an empty viewport reads as a broken page;
 * the mark says which application you are looking at while it loads.
 *
 * Deliberately NOT used for in-page waits. A logo above every loading list
 * would be noise, and `Spinner` remains the right thing there.
 */
export function BrandedLoader({ label }: { label?: string }) {
  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center gap-4 px-6">
      <Logo size="xl" />
      <span className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin text-teal" />
        {label ?? "Loading…"}
      </span>
    </div>
  );
}

export function EmptyState({
  title,
  description,
  action,
  icon,
}: {
  title: string;
  description?: string;
  action?: ReactNode;
  icon?: ReactNode;
}) {
  return (
    <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border bg-white px-6 py-16 text-center">
      <span className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-teal/10 text-teal">
        {icon ?? <SearchX className="h-6 w-6" />}
      </span>
      <h3 className="text-lg font-bold text-navy">{title}</h3>
      {description && <p className="mt-2 max-w-md text-sm text-muted-foreground">{description}</p>}
      {action && <div className="mt-6">{action}</div>}
    </div>
  );
}

export function ErrorState({
  error,
  onRetry,
  title,
}: {
  error: unknown;
  onRetry?: () => void;
  title?: string;
}) {
  const apiError = error instanceof ApiError ? error : null;
  const message =
    apiError?.message ??
    (error instanceof Error ? error.message : "Something went wrong. Please try again.");

  return (
    <div className="flex flex-col items-center justify-center rounded-2xl border border-destructive/20 bg-destructive/5 px-6 py-14 text-center">
      <span className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-destructive/10 text-destructive">
        <AlertTriangle className="h-6 w-6" />
      </span>
      <h3 className="text-lg font-bold text-navy">{title ?? "This didn't load"}</h3>
      <p className="mt-2 max-w-md text-sm text-muted-foreground">{message}</p>

      {apiError?.requestId && (
        <p className="mt-2 font-mono text-[11px] text-muted-foreground">
          Reference: {apiError.requestId}
        </p>
      )}

      {onRetry && (
        <button
          type="button"
          onClick={onRetry}
          className="mt-6 inline-flex items-center justify-center rounded-full bg-teal px-5 py-2 font-semibold text-white transition hover:opacity-90"
        >
          Try again
        </button>
      )}
    </div>
  );
}

/** One component for the three states a list can be in. */
export function ListState({
  isLoading,
  error,
  isEmpty,
  onRetry,
  skeleton,
  emptyTitle,
  emptyDescription,
  emptyAction,
  children,
}: {
  isLoading: boolean;
  error: unknown;
  isEmpty: boolean;
  onRetry?: () => void;
  skeleton?: ReactNode;
  emptyTitle: string;
  emptyDescription?: string;
  emptyAction?: ReactNode;
  children: ReactNode;
}) {
  if (isLoading) return <>{skeleton ?? <Spinner />}</>;
  if (error) return <ErrorState error={error} onRetry={onRetry} />;
  if (isEmpty) {
    return <EmptyState title={emptyTitle} description={emptyDescription} action={emptyAction} />;
  }

  return <>{children}</>;
}
