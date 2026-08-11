import type { ReactNode } from "react";

import { Logo } from "@/components/ui/Logo";

/** The centred card every unauthenticated screen sits in. */
export function AuthShell({
  title,
  description,
  children,
  footer,
}: {
  title: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-canvas p-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 text-center">
          <span className="inline-flex items-center gap-2">
            <Logo size="md" priority alt="SAKA" />
            <span className="text-base font-semibold tracking-tight text-ink-faint">Admin</span>
          </span>
        </div>

        <div className="card p-6">
          <h1 className="text-base font-semibold text-ink">{title}</h1>
          {description && <p className="mt-1 mb-5 text-sm text-ink-soft">{description}</p>}
          <div className={description ? "" : "mt-5"}>{children}</div>
        </div>

        {footer && <div className="mt-4 text-center text-sm text-ink-soft">{footer}</div>}
      </div>
    </div>
  );
}
