import type { Metadata } from "next";
import { Suspense } from "react";

import { AuthPage } from "@/components/auth/AuthPage";

/*
 * `noindex` on purpose: a sign-in form is not a search result, and
 * indexing it wastes crawl budget on a page with no content.
 */
export const metadata: Metadata = {
  title: "Sign in",
  alternates: { canonical: "/login" },
  robots: { index: false, follow: true },
};

export default function Page() {
  return (
    <Suspense>
      <AuthPage mode="login" />
    </Suspense>
  );
}
