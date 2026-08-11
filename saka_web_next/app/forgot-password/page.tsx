import type { Metadata } from "next";
import { Suspense } from "react";

import { AuthPage } from "@/components/auth/AuthPage";

export const metadata: Metadata = { title: "Reset your password" };

export default function Page() {
  return (
    <Suspense>
      <AuthPage mode="forgot" />
    </Suspense>
  );
}
