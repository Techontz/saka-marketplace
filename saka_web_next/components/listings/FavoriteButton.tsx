"use client";

import { useState } from "react";
import { Heart } from "lucide-react";

import { useAuth } from "@/providers/AuthProvider";
import { useAuthDialog } from "@/providers/AuthDialogProvider";
import { useFavorites } from "@/providers/FavoritesProvider";

/**
 * The heart, on a listing card or a business card.
 *
 * Signed out it does not pretend to work: tapping opens the sign-in dialog with
 * the reason stated, and the listing is saved as soon as the account exists.
 * A heart that fills and then silently loses the listing on refresh is worse
 * than one that asks.
 */
export function FavoriteButton({
  kind,
  slug,
  className,
  label,
  withLabel = false,
}: {
  kind: "listing" | "business";
  slug: string;
  className: string;
  label?: string;
  /** Show "Save"/"Saved" beside the heart — for an action bar rather than a card. */
  withLabel?: boolean;
}) {
  const { isAuthenticated } = useAuth();
  const authDialog = useAuthDialog();
  const favorites = useFavorites();
  const [pending, setPending] = useState(false);

  const saved =
    kind === "listing" ? favorites.isListingSaved(slug) : favorites.isBusinessSaved(slug);

  const toggle = async (event: React.MouseEvent) => {
    event.preventDefault();
    event.stopPropagation();

    if (!isAuthenticated) {
      authDialog.open("login", "Sign in to save this and find it again later.");
      return;
    }

    setPending(true);

    try {
      await (kind === "listing" ? favorites.toggleListing(slug) : favorites.toggleBusiness(slug));
    } finally {
      setPending(false);
    }
  };

  return (
    <button
      type="button"
      onClick={toggle}
      disabled={pending}
      aria-pressed={saved}
      aria-label={saved ? `Remove ${label ?? "this"} from saved` : `Save ${label ?? "this"}`}
      title={saved ? "Saved" : "Save"}
      className={className}
    >
      <Heart className={`h-4 w-4 ${saved ? "fill-current" : ""}`} />
      {withLabel && (saved ? "Saved" : "Save")}
    </button>
  );
}
