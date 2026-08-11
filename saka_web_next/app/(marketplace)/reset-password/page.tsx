import type { Metadata } from "next";
import { Suspense } from "react";

import { AuthPage } from "@/components/auth/AuthPage";

export const metadata: Metadata = { title: "Choose a new password" };

export default function Page() {
  return (
    <Suspense>
      <AuthPage mode="reset" />
    </Suspense>
  );
}
