"use client";

import { LogOut } from "lucide-react";
import { useState, type ReactNode } from "react";

import { Button } from "@/components/admin/ui";
import { useAuth } from "@/providers/admin/AuthProvider";
import { Logo } from "@/components/admin/ui/Logo";

/** The signed-in bar: who you are, and how to stop being them. */
export function Topbar({ navButton }: { onOpenNav?: () => void; navButton?: ReactNode }) {
  const { user, logout } = useAuth();
  const [signingOut, setSigningOut] = useState(false);

  return (
    <header className="flex h-14 shrink-0 items-center justify-between gap-3 border-b border-line bg-surface px-4 sm:px-6">
      <div className="flex items-center gap-3">
        {navButton}
        <span className="flex items-center gap-2 lg:hidden">
          <Logo size="sm" alt="SAKA" />
          <span className="text-sm font-semibold text-ink-faint">Admin</span>
        </span>
      </div>

      <div className="flex items-center gap-3">
        <div className="hidden text-right sm:block">
          <p className="text-[13px] leading-tight font-medium text-ink">{user?.full_name}</p>
          {/* The role is shown because "why can't I see Settings?" is the most
              common question an operator has, and the answer is usually this. */}
          <p className="text-[11px] leading-tight text-ink-faint">
            {user?.roles.map((role) => role.replace(/_/g, " ")).join(", ")}
          </p>
        </div>

        <Button
          size="sm"
          variant="ghost"
          loading={signingOut}
          onClick={() => {
            setSigningOut(true);
            void logout();
          }}
        >
          <LogOut aria-hidden className="h-4 w-4" />
          <span className="hidden sm:inline">Sign out</span>
        </Button>
      </div>
    </header>
  );
}
