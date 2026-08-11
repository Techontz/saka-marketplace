import type { Metadata } from "next";
import { Suspense } from "react";

import { AuthPage } from "@/components/auth/AuthPage";

/*
 * `noindex` on purpose: a sign-in form is not a search result, and
 * indexing it wastes crawl budget on a page with no content.
 */
export const metadata: Metadata = {
  title: "Create an account",
  alternates: { canonical: "/register" },
  robots: { index: false, follow: true },
};

export default function Page() {
  return (
    <Suspense>
      <AuthPage mode="register" />
    </Suspense>
  );
}
