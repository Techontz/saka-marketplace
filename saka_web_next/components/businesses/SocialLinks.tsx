import { Facebook, Instagram, Linkedin, Youtube } from "lucide-react";

/**
 * A business's social profiles, as a row of icons.
 *
 * ONLY populated links are rendered. The API normalises `social_links` on read
 * — dropping blanks, unknown networks and links filed under a network they do
 * not belong to — so anything reaching this component is a canonical URL on the
 * right host. There is nothing here that renders a placeholder, a `#` href, or
 * a greyed-out icon for a network the vendor left empty.
 *
 * The order is fixed by the API (it iterates the enum, not the vendor's input),
 * so every business shows its icons the same way round.
 */

/** Networks SAKA renders, and how. Keyed to `SocialNetwork` in the API. */
const NETWORKS: Record<
  string,
  { label: string; icon: React.ComponentType<{ className?: string }> }
> = {
  facebook: { label: "Facebook", icon: Facebook },
  instagram: { label: "Instagram", icon: Instagram },
  x: { label: "X", icon: XIcon },
  linkedin: { label: "LinkedIn", icon: Linkedin },
  tiktok: { label: "TikTok", icon: TikTokIcon },
  youtube: { label: "YouTube", icon: Youtube },
};

export function SocialLinks({
  links,
  businessName,
  className = "",
}: {
  links: Record<string, string> | null | undefined;
  /** Used in the accessible label, so "Instagram" is not the whole announcement. */
  businessName: string;
  className?: string;
}) {
  const entries = Object.entries(links ?? {}).filter(
    ([network, url]) => NETWORKS[network] !== undefined && typeof url === "string" && url !== "",
  );

  // Renders NOTHING when there is nothing — not a heading over an empty row.
  if (entries.length === 0) return null;

  return (
    <ul className={`flex flex-wrap items-center gap-2 ${className}`}>
      {entries.map(([network, url]) => {
        const meta = NETWORKS[network];
        const Icon = meta.icon;

        return (
          <li key={network}>
            <a
              href={url}
              target="_blank"
              /*
               * `noopener` is the one that matters: without it the destination
               * gets a handle on this page through `window.opener` and can
               * navigate it somewhere else. `noreferrer` also keeps SAKA's URL
               * out of the destination's analytics.
               */
              rel="noopener noreferrer"
              /*
               * The business name is in the label because a screen-reader user
               * tabbing a row of these otherwise hears "Instagram, link.
               * Facebook, link." with no idea whose.
               */
              aria-label={`${businessName} on ${meta.label}`}
              title={meta.label}
              /* 44px, not 36 — this is the minimum touch target, and a row of
                 six small icons on a phone is exactly where that matters. */
              className="flex h-11 w-11 items-center justify-center rounded-full border border-[#DCE6EF] bg-white text-[#17233C] transition-colors duration-200 hover:border-[#0B8E95] hover:text-[#0B8E95] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0B8E95]/40"
            >
              <Icon className="h-[18px] w-[18px]" />
            </a>
          </li>
        );
      })}
    </ul>
  );
}

/**
 * X and TikTok are not in lucide, so they are drawn here.
 *
 * Inline SVG rather than an image: these sit next to lucide icons and have to
 * inherit `currentColor` on hover along with the rest of the row, which an
 * <img> cannot do.
 */
function XIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" className={className}>
      <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
    </svg>
  );
}

function TikTokIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" className={className}>
      <path d="M16.6 5.82A4.28 4.28 0 0 1 15.54 3h-3.09v12.4a2.59 2.59 0 0 1-2.59 2.5 2.59 2.59 0 1 1 .74-5.07v-3.1a5.68 5.68 0 1 0 4.94 5.63V9.01a7.35 7.35 0 0 0 4.31 1.38V7.3a4.28 4.28 0 0 1-3.25-1.48z" />
    </svg>
  );
}
