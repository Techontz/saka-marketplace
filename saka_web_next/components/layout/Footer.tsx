import Link from "next/link";
import { ArrowUpRight, Facebook, Instagram, Linkedin, Twitter } from "lucide-react";

import { BUILD_YEAR, DEVELOPER, SHOW_DEVELOPER_CREDIT } from "@/lib/build-info";
import { Logo } from "@/components/ui/Logo";
import { getPublicSettings, settingText } from "@/lib/api/public";

/**
 * The site footer, ported unchanged from the original.
 *
 * Terms and Privacy remain unpublished, so both links point where they always
 * did rather than to pages that do not exist.
 *
 * The contact block was three hardcoded strings; it now reads `contact.*` from
 * `GET /settings/public`, so operations can correct the phone number from the
 * admin portal instead of asking for a deploy. The old literals survive as the
 * fallback, which keeps the footer correct if the endpoint is unreachable.
 */
export async function Footer() {
  const settings = await getPublicSettings()
    .then((response) => response.data)
    .catch(() => ({}));

  /*
   * NO HARDCODED FALLBACKS.
   *
   * These previously fell back to "info@saka.com" and "+255 123 456 789" — a
   * fake address and a number whose prefix is not even a valid Tanzanian
   * mobile. A footer is on every page of the site, so a placeholder there is
   * published contact information: customers write to it and ring it, and the
   * day the number resolves to somebody's real line it becomes their problem.
   *
   * Absent now means the row is not rendered. An operator sets these in
   * Settings and they appear. A missing phone number is honest; a wrong one is
   * not.
   */
  const email = settingText(settings, "contact.email");
  const phone = settingText(settings, "contact.phone");
  const address = settingText(settings, "contact.address");

  const socials = [
    { key: "social.facebook", label: "Facebook", Icon: Facebook },
    { key: "social.linkedin", label: "LinkedIn", Icon: Linkedin },
    { key: "social.instagram", label: "Instagram", Icon: Instagram },
    { key: "social.x", label: "X", Icon: Twitter },
  ]
    .map((social) => ({ ...social, href: settingText(settings, social.key) }))
    .filter((social): social is typeof social & { href: string } => Boolean(social.href));

  return (
    <footer className="bg-navy text-white">
      <div className="mx-auto max-w-7xl px-6 py-16 grid grid-cols-1 md:grid-cols-3 gap-12">
        <div>
          {/* `light` because the footer is navy: the mark is dark ink and
              needs a white plate to stay legible. See components/ui/Logo.tsx. */}
          <Logo size="md" variant="light" className="mb-6" />
          <p className="text-white/70 max-w-sm mb-8">
            Partner up with SAKA and have your ideas crafted with care and compassion.
          </p>
          <Link
            href="/contact"
            className="inline-flex items-center gap-2 rounded-full bg-teal pl-5 pr-2 py-2 text-white font-semibold hover:opacity-90 transition"
          >
            Free Consultation
            <span className="ml-1 flex h-8 w-8 items-center justify-center rounded-full bg-white text-teal">
              <ArrowUpRight className="h-4 w-4" />
            </span>
          </Link>
        </div>
        <div>
          <h4 className="text-white text-lg font-bold mb-6">Quick Links</h4>
          {/*
            * `min-h-11` on the link, and the gap reduced to compensate.
            *
            * These were 19px-tall text links — the height of the text itself —
            * stacked with a 16px gap. Each is now a 44px target and the list is
            * only a few pixels taller overall, so the footer rhythm is
            * unchanged while the links became tappable.
            */}
          <ul className="space-y-1 text-white/70">
            <li>
              <Link href="/about" className="flex min-h-11 items-center hover:text-white">
                Terms &amp; Conditions
              </Link>
            </li>
            <li>
              <Link href="/about" className="flex min-h-11 items-center hover:text-white">
                Privacy Policy
              </Link>
            </li>
            <li>
              <Link href="/contact" className="flex min-h-11 items-center hover:text-white">
                Contact Us
              </Link>
            </li>
          </ul>
        </div>
        <div>
          <h4 className="text-white text-lg font-bold mb-6">Contact Us</h4>
          <ul className="space-y-1 text-white/70">
            {email && (
              <li>
                <a
                  href={`mailto:${email}`}
                  className="flex min-h-11 items-center gap-2 hover:text-white"
                >
                  <span aria-hidden="true">✉</span>
                  {email}
                </a>
              </li>
            )}
            {phone && (
              <li>
                <a
                  href={`tel:${phone.replace(/\s+/g, "")}`}
                  className="flex min-h-11 items-center gap-2 hover:text-white"
                >
                  <span aria-hidden="true">📞</span>
                  {phone}
                </a>
              </li>
            )}
            {address && (
              <li className="flex min-h-11 items-center gap-2">
                <span aria-hidden="true">📍</span>
                {address}
              </li>
            )}
            {/* Every channel unset is a real state on a fresh install. */}
            {!email && !phone && !address && (
              <li className="text-white/50">Contact details have not been published yet.</li>
            )}
          </ul>
        </div>
      </div>
      <div className="border-t border-white/10">
        <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-4 px-6 py-6 md:flex-row">
          <div className="flex flex-col items-center gap-1 md:items-start">
            <p className="text-sm text-white/60">
              © {BUILD_YEAR} SAKA. All rights reserved.
            </p>

            {/*
              The developer credit.

              Deliberately the smallest type on the page, in the legal row
              rather than the brand column, and one line long. SAKA belongs to
              the client and stays the only brand anyone reads; this is an
              attribution, not a placement. Removable with one env var.
            */}
            {SHOW_DEVELOPER_CREDIT && (
              <p className="text-xs text-white/40">
                Technology by{" "}
                <a
                  href={DEVELOPER.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex min-h-11 items-center underline decoration-white/20 underline-offset-2 transition hover:text-white/70"
                >
                  {DEVELOPER.name}
                </a>
              </p>
            )}
          </div>

          {/*
            Only the networks that have a URL.

            These three were `href="#"` — icons on every page of the site that
            did nothing when tapped. They now read `social.*` from
            GET /settings/public, so an operator adds a profile in the admin
            portal and its icon appears. A network with no URL renders no icon
            rather than a dead one.
          */}
          {socials.length > 0 && (
            <div className="flex items-center gap-4 text-white/60">
              {socials.map((social) => (
                <a
                  key={social.label}
                  href={social.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  aria-label={social.label}
                  className="transition hover:text-white"
                >
                  <social.Icon className="h-5 w-5" />
                </a>
              ))}
            </div>
          )}
        </div>
      </div>
    </footer>
  );
}
