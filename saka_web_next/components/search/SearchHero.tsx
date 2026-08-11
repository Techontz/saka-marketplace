import Link from "next/link";

/**
 * The banner at the top of a browse page.
 *
 * A server component on purpose: it carries the page's h1 and breadcrumb, and
 * those are exactly the parts that must exist in the HTML rather than appearing
 * after hydration.
 *
 * The background is the selected category's own image when the API has one.
 * Because that image is a photograph rather than a flat colour, the scrim is a
 * dark GRADIENT and the type is white — a white wash over an arbitrary photo
 * cannot guarantee contrast, and the heading is the one thing on the page that
 * must always be readable.
 *
 * `key={image}` on the layer is what makes a category change fade rather than
 * cut: React tears down the old <img> and mounts a new one, so the entry
 * animation runs again on every navigation.
 */

/** Used whenever the API has no image for what is being browsed. */
export const DEFAULT_HERO_IMAGE =
  "https://images.unsplash.com/photo-1499856871958-5b9627545d1a?auto=format&fit=crop&w=1600&q=80";

export function SearchHero({
  title,
  trail,
  image,
  description,
}: {
  title: string;
  trail: string[];
  /** Falsy values fall back to the default, so callers can pass through nullable API fields. */
  image?: string | null;
  description?: string;
}) {
  const background = image || DEFAULT_HERO_IMAGE;

  return (
    <section className="relative isolate overflow-hidden bg-navy">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        key={background}
        src={background}
        alt=""
        aria-hidden="true"
        className="absolute inset-0 -z-10 h-full w-full object-cover animate-fade-in-soft"
      />

      <div
        aria-hidden="true"
        className="absolute inset-0 -z-10 bg-gradient-to-r from-navy/90 via-navy/75 to-navy/45"
      />

      <div className="relative mx-auto max-w-7xl px-4 py-16">
        <h1 className="text-4xl font-extrabold text-white md:text-5xl">{title}</h1>

        <p className="mt-3 text-sm text-white/70">
          <Link
            href="/"
            /* Inline-flex + min-h so the breadcrumb is a real tap target; the
               paragraph around it keeps the same baseline. */
            className="inline-flex min-h-11 items-center transition-colors hover:text-teal"
          >
            Home
          </Link>
          {trail.map((crumb) => (
            /* `inline-flex min-h-11` so the crumbs share the Home link's line
               box; the last crumb is the current page and stays unlinked. */
            <span key={crumb} className="inline-flex min-h-11 items-center">
              <span className="mx-2">›</span>
              {crumb}
            </span>
          ))}
        </p>

        {/*
          Rendered ALONGSIDE the breadcrumb, not instead of it. Passing a
          description used to replace the trail, which silently removed the
          breadcrumb from every page that had one.
        */}
        {description && <p className="mt-4 max-w-2xl text-white/80">{description}</p>}
      </div>
    </section>
  );
}
