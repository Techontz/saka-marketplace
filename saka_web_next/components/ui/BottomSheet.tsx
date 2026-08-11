"use client";

import { useEffect, useRef } from "react";
import { X } from "lucide-react";

/**
 * A sheet that rises from the bottom of the screen.
 *
 * Used for the mobile filter panel. On a phone the filter sidebar was a column
 * of boxes pushed above the results, so choosing a price meant scrolling past
 * every other filter and then scrolling back to see what changed. A sheet keeps
 * the results one tap away and the controls in thumb reach.
 *
 * WHY NOT A FULL-SCREEN PAGE
 * --------------------------
 * A route would be simpler, and it would lose the results underneath: the point
 * of filtering is watching the count change. The sheet leaves 10% of the page
 * visible and the footer holds a live "Show N results" button.
 *
 * BODY SCROLL is locked while open, otherwise dragging inside the sheet scrolls
 * the page behind it — the single most common way a mobile sheet feels broken.
 * FOCUS is moved into the sheet and returned on close, and Tab is trapped, so
 * it behaves as a dialog for keyboard and screen-reader users rather than
 * merely looking like one.
 */
export function BottomSheet({
  open,
  onClose,
  title,
  description,
  footer,
  children,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  footer?: React.ReactNode;
  children: React.ReactNode;
}) {
  const panelRef = useRef<HTMLDivElement>(null);
  const returnFocusTo = useRef<HTMLElement | null>(null);

  useEffect(() => {
    if (!open) return;

    returnFocusTo.current = document.activeElement as HTMLElement | null;

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    // Focus the panel itself rather than the first control: landing on the
    // location input would pop the on-screen keyboard over the sheet before
    // anyone has asked for it.
    panelRef.current?.focus();

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        onClose();
        return;
      }

      if (event.key !== "Tab") return;

      const focusable = panelRef.current?.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
      );

      if (!focusable || focusable.length === 0) return;

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener("keydown", onKeyDown);

    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener("keydown", onKeyDown);
      returnFocusTo.current?.focus?.();
    };
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[70] flex flex-col justify-end lg:hidden">
      <button
        type="button"
        aria-label="Close filters"
        onClick={onClose}
        className="absolute inset-0 bg-navy/50 animate-fade-in-soft"
        tabIndex={-1}
      />

      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        tabIndex={-1}
        className="relative flex max-h-[92vh] flex-col rounded-t-2xl bg-page shadow-2xl outline-none animate-slide-up"
      >
        {/* The grab handle is decorative but load-bearing: it is what tells
            someone this panel is a sheet and can be dismissed. */}
        <div aria-hidden="true" className="mx-auto mt-3 h-1.5 w-12 rounded-full bg-border" />

        <div className="flex items-start justify-between gap-4 px-5 pb-3 pt-3">
          <div>
            <h2 className="text-lg font-extrabold text-navy">{title}</h2>
            {description && <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>}
          </div>

          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-border bg-white text-navy transition hover:border-teal hover:text-teal"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        {/* `overscroll-contain` stops a flick at the end of this list from
            continuing into the page behind the sheet. */}
        <div className="flex-1 overflow-y-auto overscroll-contain px-5 pb-4">{children}</div>

        {footer && (
          <div className="border-t border-border bg-white px-5 pb-[max(1rem,env(safe-area-inset-bottom))] pt-4">
            {footer}
          </div>
        )}
      </div>
    </div>
  );
}
