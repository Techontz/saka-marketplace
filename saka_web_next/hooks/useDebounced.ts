"use client";

import { useEffect, useState } from "react";

/**
 * The value, but only after it has stopped changing.
 *
 * Used by anything that turns keystrokes into requests. Without it a search
 * input sends one request per letter, which burns the endpoint's per-IP
 * throttle inside a single word and makes the results list strobe as
 * out-of-order responses land.
 *
 * The timer is cleared on every change AND on unmount, so a component that
 * disappears mid-type — a filter drawer being closed — leaves nothing behind
 * to fire a state update into a component that is gone.
 */
export function useDebounced<T>(value: T, delay = 300): T {
  const [settled, setSettled] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setSettled(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);

  return settled;
}
