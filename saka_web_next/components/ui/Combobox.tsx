"use client";

import { useEffect, useId, useMemo, useRef, useState } from "react";
import { Check, ChevronDown, Loader2, Search, X } from "lucide-react";

/**
 * A searchable select.
 *
 * Built rather than reached for, because the two obvious alternatives are both
 * wrong here:
 *
 *   - a native <select> cannot be typed into. Tanzania has 155 districts; a
 *     customer looking for Kinondoni should not be scrolling a list of 155.
 *   - a plain text input cannot constrain to real values. Typing "Kinondni"
 *     into a free-text location filter silently returns nothing, and the
 *     customer concludes the marketplace is empty rather than that they typed
 *     it wrong.
 *
 * Filtering is CLIENT-SIDE over an already-loaded list. Every list this drives
 * is small (31 regions, 155 districts, 70 wards) and cached, so type-ahead is
 * instant and costs no request per keystroke.
 *
 * ── KEYBOARD ──────────────────────────────────────────────────────────────
 * A real `combobox`: ↑ ↓ to move (wrapping), Enter to choose, Escape to close,
 * Home/End to jump, Tab to leave. `aria-activedescendant` points at the
 * highlighted option so a screen reader announces it without focus ever
 * leaving the input.
 *
 * ── MOBILE ────────────────────────────────────────────────────────────────
 * The listbox is a normal block below the field rather than a floating layer,
 * so it cannot end up off-screen; options are 44px tall, which is the minimum
 * comfortable touch target.
 */

export type ComboboxOption = {
  value: string;
  label: string;
  /** Second line — a district's region, a landmark's category. */
  hint?: string;
  /** Right-aligned, e.g. a listing count. Omitted when zero. */
  badge?: string;
  icon?: React.ReactNode;
};

export function Combobox({
  label,
  value,
  options,
  onChange,
  placeholder = "Search…",
  emptyText = "Nothing matches that",
  disabled = false,
  disabledHint,
  loading = false,
  allowClear = true,
  id,
}: {
  label: string;
  /** The selected option's `value`, or "" for none. */
  value: string;
  options: ComboboxOption[];
  onChange: (value: string, option: ComboboxOption | null) => void;
  placeholder?: string;
  emptyText?: string;
  disabled?: boolean;
  /** Shown in place of the field's value when disabled — say WHY, not just that. */
  disabledHint?: string;
  loading?: boolean;
  allowClear?: boolean;
  id?: string;
}) {
  const [open, setOpen] = useState(false);
  const [term, setTerm] = useState("");
  const [active, setActive] = useState(-1);

  const rootRef = useRef<HTMLDivElement>(null);
  const listRef = useRef<HTMLUListElement>(null);
  const generatedId = useId();
  const fieldId = id ?? generatedId;
  const listboxId = `${fieldId}-listbox`;

  const selected = useMemo(
    () => options.find((option) => option.value === value) ?? null,
    [options, value],
  );

  /*
   * While the list is closed the input SHOWS the selection; while it is open
   * it shows what is being typed. Without that split, opening the list to
   * change a choice means first deleting the old label by hand.
   */
  const query = term.trim().toLowerCase();

  const filtered = useMemo(() => {
    if (query === "") return options;

    // Prefix matches first: typing "Mi" should put Mikocheni above
    // "Dar es Salaam Mikocheni Road".
    const scored = options
      .map((option) => {
        const label = option.label.toLowerCase();
        const hint = (option.hint ?? "").toLowerCase();

        if (label.startsWith(query)) return { option, rank: 0 };
        if (label.includes(query)) return { option, rank: 1 };
        if (hint.includes(query)) return { option, rank: 2 };

        return null;
      })
      .filter((entry): entry is { option: ComboboxOption; rank: number } => entry !== null);

    scored.sort((a, b) => a.rank - b.rank);

    return scored.map((entry) => entry.option);
  }, [options, query]);

  // Close on an outside press, and put the selected label back so a half-typed
  // term never lingers as if it were filtering something.
  useEffect(() => {
    if (!open) return;

    const onPointerDown = (event: PointerEvent) => {
      if (rootRef.current?.contains(event.target as Node)) return;
      setOpen(false);
      setActive(-1);
      setTerm("");
    };

    document.addEventListener("pointerdown", onPointerDown);
    return () => document.removeEventListener("pointerdown", onPointerDown);
  }, [open]);

  // Keep the highlighted option in view when moving by keyboard.
  useEffect(() => {
    if (!open || active < 0) return;
    listRef.current?.querySelector(`[data-index="${active}"]`)?.scrollIntoView({ block: "nearest" });
  }, [open, active]);

  const choose = (option: ComboboxOption) => {
    onChange(option.value, option);
    setOpen(false);
    setActive(-1);
    setTerm("");
  };

  const clear = () => {
    onChange("", null);
    setTerm("");
    setActive(-1);
  };

  const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
    if (disabled) return;

    switch (event.key) {
      case "ArrowDown":
      case "ArrowUp": {
        event.preventDefault();

        if (!open) {
          setOpen(true);
          setActive(0);
          return;
        }

        if (filtered.length === 0) return;

        setActive((current) => {
          const next = event.key === "ArrowDown" ? current + 1 : current - 1;
          return (next + filtered.length) % filtered.length;
        });
        return;
      }

      case "Home":
        if (open && filtered.length > 0) {
          event.preventDefault();
          setActive(0);
        }
        return;

      case "End":
        if (open && filtered.length > 0) {
          event.preventDefault();
          setActive(filtered.length - 1);
        }
        return;

      case "Enter":
        if (open && active >= 0 && filtered[active]) {
          event.preventDefault();
          choose(filtered[active]);
        }
        return;

      case "Escape":
        if (open) {
          event.preventDefault();
          setOpen(false);
          setActive(-1);
          setTerm("");
        }
        return;

      case "Tab":
        setOpen(false);
        setActive(-1);
        setTerm("");
    }
  };

  return (
    <div ref={rootRef} className="relative">
      <label htmlFor={fieldId} className="mb-1.5 block text-sm font-medium text-navy">
        {label}
      </label>

      <div className="relative">
        <Search
          aria-hidden="true"
          className={`pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 ${
            disabled ? "text-border" : "text-muted-foreground"
          }`}
        />

        <input
          id={fieldId}
          type="text"
          role="combobox"
          aria-expanded={open}
          aria-controls={listboxId}
          aria-autocomplete="list"
          aria-activedescendant={
            open && active >= 0 && filtered[active] ? `${listboxId}-${active}` : undefined
          }
          autoComplete="off"
          autoCorrect="off"
          spellCheck={false}
          enterKeyHint="search"
          disabled={disabled}
          value={open ? term : (selected?.label ?? "")}
          placeholder={disabled ? (disabledHint ?? placeholder) : placeholder}
          onChange={(event) => {
            setTerm(event.target.value);
            setOpen(true);
            setActive(0);
          }}
          onFocus={() => !disabled && setOpen(true)}
          onKeyDown={onKeyDown}
          className="h-11 w-full rounded-lg border border-border bg-white pl-9 pr-16 text-[15px] outline-none transition focus:border-teal disabled:cursor-not-allowed disabled:bg-page disabled:text-muted-foreground"
        />

        <span className="absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-1">
          {loading && <Loader2 className="h-4 w-4 animate-spin text-teal" />}

          {allowClear && selected && !disabled && (
            <button
              type="button"
              onClick={clear}
              aria-label={`Clear ${label.toLowerCase()}`}
              className="flex h-6 w-6 items-center justify-center rounded-full text-muted-foreground transition hover:bg-page hover:text-navy"
            >
              <X className="h-3.5 w-3.5" />
            </button>
          )}

          <button
            type="button"
            tabIndex={-1}
            aria-hidden="true"
            disabled={disabled}
            onClick={() => !disabled && setOpen((current) => !current)}
            className="flex h-6 w-6 items-center justify-center text-muted-foreground disabled:opacity-40"
          >
            <ChevronDown className={`h-4 w-4 transition-transform ${open ? "rotate-180" : ""}`} />
          </button>
        </span>
      </div>

      {open && !disabled && (
        <ul
          ref={listRef}
          id={listboxId}
          role="listbox"
          aria-label={label}
          className="absolute left-0 right-0 top-full z-40 mt-1 max-h-64 overflow-y-auto overscroll-contain rounded-xl border border-border bg-white py-1 shadow-xl"
        >
          {filtered.length === 0 && (
            <li className="px-4 py-3 text-sm text-muted-foreground">{emptyText}</li>
          )}

          {filtered.map((option, index) => {
            const isSelected = option.value === value;

            return (
              <li key={option.value} id={`${listboxId}-${index}`} role="option" aria-selected={isSelected} data-index={index}>
                <button
                  type="button"
                  // pointerdown, not click: the outside-press handler runs on
                  // pointerdown and would close the list before a click landed.
                  onPointerDown={(event) => {
                    event.preventDefault();
                    choose(option);
                  }}
                  onMouseEnter={() => setActive(index)}
                  className={`flex min-h-11 w-full items-center gap-3 px-4 py-2 text-left transition ${
                    index === active ? "bg-teal/10" : "hover:bg-page"
                  }`}
                >
                  {option.icon && <span className="shrink-0 text-teal">{option.icon}</span>}

                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold text-navy">
                      {option.label}
                    </span>
                    {option.hint && (
                      <span className="block truncate text-xs text-muted-foreground">
                        {option.hint}
                      </span>
                    )}
                  </span>

                  {option.badge && (
                    <span className="shrink-0 text-xs font-semibold text-muted-foreground">
                      {option.badge}
                    </span>
                  )}

                  {isSelected && <Check className="h-4 w-4 shrink-0 text-teal" />}
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
