"use client";

import { useState } from "react";
import { Check, Share2 } from "lucide-react";

/**
 * Share this page.
 *
 * Uses the native share sheet where the browser has one — on a phone that is
 * the whole point, because it offers WhatsApp, which is how listings actually
 * circulate here. Everywhere else it copies the link and says so, rather than
 * opening a row of social buttons nobody presses.
 *
 * The URL is read at click time from `location.href` so it carries whatever
 * filters or tab the visitor is actually looking at.
 */
export function ShareButton({
  title,
  text,
  className = "",
}: {
  title: string;
  text?: string;
  className?: string;
}) {
  const [copied, setCopied] = useState(false);

  const share = async () => {
    const url = window.location.href;

    if (navigator.share) {
      try {
        await navigator.share({ title, text, url });
        return;
      } catch {
        // Cancelling the sheet rejects; fall through to copying rather than
        // reporting an error the visitor caused on purpose.
      }
    }

    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard blocked (insecure origin, or permission denied) — there is
      // nothing useful left to try, and the address bar already has the URL.
    }
  };

  return (
    <button type="button" onClick={share} className={className}>
      {copied ? <Check className="h-4 w-4" /> : <Share2 className="h-4 w-4" />}
      {copied ? "Link copied" : "Share"}
    </button>
  );
}
