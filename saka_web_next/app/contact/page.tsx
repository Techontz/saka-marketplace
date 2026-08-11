import type { Metadata } from "next";

import { ContactForm } from "@/components/ContactForm";
import { getPublicSettings, settingText } from "@/lib/api/public";

export const metadata: Metadata = {
  /*
   * Canonical URL.
   *
   * `metadataBase` in the root layout supplies the origin, so a relative path
   * is enough. Without this, every filtered and sorted variant of a listing
   * page — ?category=…&sort=…&page=2 — is a separate indexable URL competing
   * with the same content, which is how a catalogue site dilutes its own
   * ranking.
   */
  alternates: { canonical: "/contact" },
  title: "Contact Us",
  description: "Get in touch with the SAKA team.",
};

export default async function ContactPage() {
  /*
   * Fetched on the server and handed down. The form is a Client Component, but
   * these three values are identical for every visitor and cacheable, so there
   * is no reason to spend a browser round trip on them.
   */
  const settings = await getPublicSettings()
    .then((response) => response.data)
    .catch(() => ({}));

  return (
    <ContactForm
      email={settingText(settings, "contact.email") ?? "info@saka.com"}
      phone={settingText(settings, "contact.phone") ?? "+255 123 456 789"}
      address={
        settingText(settings, "contact.address") ?? "SAMORA AVE, ILALA, DAR ES SALAAM, TANZANIA"
      }
    />
  );
}
