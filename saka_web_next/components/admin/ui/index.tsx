"use client";

import { AlertCircle, Inbox, Loader2 } from "lucide-react";
import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from "react";

import { ApiError } from "@/lib/admin/api/errors";
import { cn } from "@/lib/admin/cn";
import { formatCount } from "@/lib/admin/format";

/**
 * The admin portal's UI primitives.
 *
 * Everything a screen needs to render the four states every list has — loading,
 * empty, error, populated — lives here, so no screen invents its own. That is
 * the point: the failure mode of an admin tool built page-by-page is that half
 * the screens forget the empty state and show a blank panel that looks broken.
 */

// ------------------------------------------------------------------- button

type ButtonVariant = "primary" | "secondary" | "ghost" | "danger";

const BUTTON_STYLES: Record<ButtonVariant, string> = {
  primary: "bg-brand text-white hover:opacity-90 disabled:opacity-50",
  secondary: "bg-surface text-ink border border-line hover:border-line-strong disabled:opacity-50",
  ghost: "text-ink-soft hover:bg-muted-soft hover:text-ink disabled:opacity-50",
  // Destructive actions are visually distinct from "secondary" so a delete is
  // never one careless click away from a cancel.
  danger: "bg-danger text-white hover:opacity-90 disabled:opacity-50",
};

export function Button({
  variant = "secondary",
  loading = false,
  size = "md",
  className,
  children,
  disabled,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: ButtonVariant;
  loading?: boolean;
  size?: "sm" | "md";
}) {
  return (
    <button
      {...props}
      // A button that stays clickable while its request is in flight is how
      // duplicate approvals and double-charges happen.
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={cn(
        "inline-flex items-center justify-center gap-2 rounded-[var(--radius-control)] font-medium transition-opacity",
        size === "sm" ? "h-8 px-3 text-[13px]" : "h-10 px-4 text-sm",
        BUTTON_STYLES[variant],
        "disabled:cursor-not-allowed",
        className,
      )}
    >
      {loading && <Loader2 aria-hidden className="h-4 w-4 animate-spin" />}
      {children}
    </button>
  );
}

// -------------------------------------------------------------------- badge

export type BadgeTone = "ok" | "warn" | "danger" | "info" | "muted" | "brand";

const BADGE_STYLES: Record<BadgeTone, string> = {
  ok: "bg-ok-soft text-ok",
  warn: "bg-warn-soft text-warn",
  danger: "bg-danger-soft text-danger",
  info: "bg-info-soft text-info",
  muted: "bg-muted-soft text-ink-soft",
  brand: "bg-brand-soft text-brand-ink",
};

export function Badge({ tone = "muted", children }: { tone?: BadgeTone; children: ReactNode }) {
  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap",
        BADGE_STYLES[tone],
      )}
    >
      {children}
    </span>
  );
}

/**
 * Listing status -> badge tone.
 *
 * Centralised so "pending" is the same amber everywhere. Statuses that need a
 * moderator's attention are warm; terminal states are neutral. Colour is never
 * the only signal — the label is always present.
 */
export function statusTone(status: string): BadgeTone {
  switch (status) {
    case "published":
    case "active":
    case "approved":
    case "complete":
      return "ok";
    case "pending_review":
    case "pending":
    case "paused":
      return "warn";
    case "rejected":
    case "banned":
    case "suspended":
    case "failed":
      return "danger";
    case "sold":
      return "info";
    default:
      return "muted";
  }
}

/** `pending_review` -> `Pending review`. */
export function humanise(value: string): string {
  const spaced = value.replace(/[_.]/g, " ");
  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

// ------------------------------------------------------------------- fields

export function Field({
  label,
  hint,
  error,
  children,
  required,
}: {
  label: string;
  hint?: string;
  error?: string;
  children: ReactNode;
  required?: boolean;
}) {
  return (
    <label className="block">
      <span className="mb-1.5 flex items-center gap-1 text-[13px] font-medium text-ink">
        {label}
        {required && (
          <span aria-hidden className="text-danger">
            *
          </span>
        )}
      </span>
      {children}
      {/* Hint is hidden once there is an error: two competing lines of
          guidance under one input is worse than one clear correction. */}
      {error ? (
        <span className="mt-1 block text-xs text-danger">{error}</span>
      ) : hint ? (
        <span className="mt-1 block text-xs text-ink-faint">{hint}</span>
      ) : null}
    </label>
  );
}

const CONTROL =
  "w-full rounded-[var(--radius-control)] border border-line bg-surface px-3 text-sm text-ink " +
  "placeholder:text-ink-faint focus:border-brand focus:outline-none disabled:bg-muted-soft disabled:text-ink-faint";

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} className={cn(CONTROL, "h-10", className)} />;
}

export function Textarea({
  className,
  ...props
}: React.TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea {...props} className={cn(CONTROL, "py-2", className)} />;
}

export function Select({
  className,
  children,
  ...props
}: SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select {...props} className={cn(CONTROL, "h-10", className)}>
      {children}
    </select>
  );
}

export function Checkbox({
  label,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  return (
    <label className="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-ink">
      <input {...props} type="checkbox" className="h-4 w-4 accent-[var(--color-brand)]" />
      {label}
    </label>
  );
}

// ------------------------------------------------------------- list states

/**
 * Skeleton rows, sized to the table they replace.
 *
 * A spinner in place of a table causes the page to jump when data lands.
 * Matching the row height keeps the layout still.
 */
export function TableSkeleton({ rows = 6, columns = 5 }: { rows?: number; columns?: number }) {
  return (
    <div aria-busy className="divide-y divide-line" role="status" aria-label="Loading">
      {Array.from({ length: rows }).map((_, rowIndex) => (
        <div key={rowIndex} className="flex items-center gap-4 px-4 py-3.5">
          {Array.from({ length: columns }).map((_, columnIndex) => (
            <div
              key={columnIndex}
              className="h-3.5 animate-pulse rounded bg-muted-soft"
              // Varying widths so it reads as content rather than a progress bar.
              style={{ width: columnIndex === 0 ? "28%" : `${12 + ((columnIndex * 7) % 15)}%` }}
            />
          ))}
        </div>
      ))}
    </div>
  );
}

/**
 * Nothing to show — which is different from "failed to load".
 *
 * Conflating the two is the most common bug in admin tooling: a broken endpoint
 * renders as "no results", and the operator concludes the data is gone.
 */
export function EmptyState({
  title,
  description,
  action,
}: {
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
      <Inbox aria-hidden className="mb-3 h-8 w-8 text-ink-faint" />
      <p className="text-sm font-medium text-ink">{title}</p>
      {description && <p className="mt-1 max-w-sm text-sm text-ink-soft">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

/**
 * Something went wrong — and it says which something.
 *
 * The request id is surfaced deliberately. It looks like noise until the moment
 * an operator reports a problem, and then it is the only thing that turns "the
 * page is broken" into a log line someone can find.
 */
export function ErrorState({
  error,
  onRetry,
  title = "We couldn't load this",
}: {
  error: unknown;
  onRetry?: () => void;
  title?: string;
}) {
  const apiError = error instanceof ApiError ? error : null;
  const message =
    apiError?.message ??
    (error instanceof Error ? error.message : "An unexpected error occurred.");

  return (
    <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
      <AlertCircle aria-hidden className="mb-3 h-8 w-8 text-danger" />
      <p className="text-sm font-medium text-ink">{title}</p>
      <p className="mt-1 max-w-md text-sm text-ink-soft">{message}</p>
      {apiError?.requestId && (
        <p className="mt-2 font-mono text-[11px] text-ink-faint">Reference: {apiError.requestId}</p>
      )}
      {onRetry && (
        <Button className="mt-4" onClick={onRetry} variant="secondary" size="sm">
          Try again
        </Button>
      )}
    </div>
  );
}

/**
 * The four-state wrapper every list uses.
 *
 * Having one component decide between loading / error / empty / content is what
 * stops a screen from quietly forgetting one of them.
 */
export function ListState({
  isLoading,
  error,
  isEmpty,
  onRetry,
  emptyTitle,
  emptyDescription,
  emptyAction,
  skeletonColumns,
  children,
}: {
  isLoading: boolean;
  error: unknown;
  isEmpty: boolean;
  onRetry?: () => void;
  emptyTitle: string;
  emptyDescription?: string;
  emptyAction?: ReactNode;
  skeletonColumns?: number;
  children: ReactNode;
}) {
  if (isLoading) return <TableSkeleton columns={skeletonColumns} />;
  if (error) return <ErrorState error={error} onRetry={onRetry} />;
  if (isEmpty) {
    return <EmptyState title={emptyTitle} description={emptyDescription} action={emptyAction} />;
  }
  return <>{children}</>;
}

// --------------------------------------------------------------- page parts

export function PageHeader({
  title,
  description,
  actions,
}: {
  title: string;
  description?: string;
  actions?: ReactNode;
}) {
  return (
    <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 className="text-xl font-semibold text-ink">{title}</h1>
        {description && <p className="mt-1 text-sm text-ink-soft">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn("card overflow-hidden", className)}>{children}</div>;
}

// -------------------------------------------------------------- pagination

/**
 * Reports the range as well as the page.
 *
 * "1–25 of 1,431" answers the question an operator actually has — how much is
 * there — which a bare page number does not.
 */
export function Pagination({
  page,
  lastPage,
  total,
  from,
  to,
  onPage,
  disabled,
}: {
  page: number;
  lastPage: number;
  total: number;
  from?: number | null;
  to?: number | null;
  onPage: (page: number) => void;
  disabled?: boolean;
}) {
  if (total === 0) return null;

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3">
      <p className="text-xs text-ink-soft">
        {from ?? 0}–{to ?? 0} of {formatCount(total)}
      </p>

      {lastPage > 1 && (
        <nav aria-label="Pagination" className="flex items-center gap-2">
          <Button
            size="sm"
            variant="secondary"
            disabled={disabled || page <= 1}
            onClick={() => onPage(page - 1)}
          >
            Previous
          </Button>
          <span className="text-xs text-ink-soft">
            Page {page} of {lastPage}
          </span>
          <Button
            size="sm"
            variant="secondary"
            disabled={disabled || page >= lastPage}
            onClick={() => onPage(page + 1)}
          >
            Next
          </Button>
        </nav>
      )}
    </div>
  );
}

// ------------------------------------------------------------------- modal

export function Modal({
  open,
  onClose,
  title,
  description,
  children,
  footer,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  children?: ReactNode;
  footer?: ReactNode;
}) {
  if (!open) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-ink/30 p-4"
      onClick={onClose}
      role="presentation"
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onClick={(event) => event.stopPropagation()}
        className="w-full max-w-lg rounded-[var(--radius-card)] border border-line bg-surface shadow-xl"
      >
        <div className="border-b border-line px-5 py-4">
          <h2 className="text-sm font-semibold text-ink">{title}</h2>
          {description && <p className="mt-1 text-sm text-ink-soft">{description}</p>}
        </div>
        {children && <div className="max-h-[60vh] overflow-y-auto px-5 py-4">{children}</div>}
        {footer && (
          <div className="flex justify-end gap-2 border-t border-line px-5 py-3">{footer}</div>
        )}
      </div>
    </div>
  );
}

/** Inline error line for forms. Renders nothing at rest. */
export function FormError({ error }: { error: unknown }) {
  if (!error) return null;

  const apiError = error instanceof ApiError ? error : null;
  const messages = apiError?.allFieldMessages() ?? [];
  const message =
    messages.length > 0
      ? messages.join(" ")
      : apiError?.message ?? (error instanceof Error ? error.message : "Something went wrong.");

  return (
    <p role="alert" className="rounded-[var(--radius-control)] bg-danger-soft px-3 py-2 text-sm text-danger">
      {message}
    </p>
  );
}
