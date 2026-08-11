"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { Bell, Check, Trash2 } from "lucide-react";

import { EmptyState, ErrorState, RowSkeleton } from "@/components/ui/states";
import { apiGet, apiSend } from "@/lib/api/browser";
import type { ApiNotification } from "@/lib/types";

/** The notification centre. */
export default function NotificationsPage() {
  const queryClient = useQueryClient();

  const notifications = useQuery({
    queryKey: ["notifications", "list"],
    queryFn: () =>
      apiGet<{ data: ApiNotification[]; meta: { unread_count: number; total: number } }>(
        "/account/notifications",
        { per_page: 30 },
      ),
  });

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ["notifications"] });
  };

  const markRead = useMutation({
    mutationFn: (id: string) => apiSend(`/account/notifications/${id}/read`, "POST"),
    onSuccess: invalidate,
  });

  const markAll = useMutation({
    mutationFn: () => apiSend("/account/notifications/read-all", "POST"),
    onSuccess: invalidate,
  });

  const dismiss = useMutation({
    mutationFn: (id: string) => apiSend(`/account/notifications/${id}`, "DELETE"),
    onSuccess: invalidate,
  });

  const rows = notifications.data?.data ?? [];
  const unread = notifications.data?.meta.unread_count ?? 0;

  return (
    <>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-2xl font-extrabold text-navy">Notifications</h2>
          <p className="mt-1 text-muted-foreground">
            {unread > 0 ? `${unread} unread` : "You're all caught up."}
          </p>
        </div>

        <div className="flex gap-2">
          {unread > 0 && (
            <button
              onClick={() => markAll.mutate()}
              className="rounded-full border border-border bg-white px-4 py-2 text-sm font-semibold text-navy transition hover:border-teal hover:text-teal"
            >
              Mark all read
            </button>
          )}
          <Link
            href="/account/settings#notifications"
            className="rounded-full border border-border bg-white px-4 py-2 text-sm font-semibold text-navy transition hover:border-teal hover:text-teal"
          >
            Preferences
          </Link>
        </div>
      </div>

      {notifications.isPending ? (
        <div className="space-y-3">
          <RowSkeleton count={4} />
        </div>
      ) : notifications.error ? (
        <ErrorState error={notifications.error} onRetry={() => void notifications.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState
          title="Nothing yet"
          description="We'll tell you when a seller replies, when a saved listing changes price, and when your review is published."
          icon={<Bell className="h-6 w-6" />}
        />
      ) : (
        <ul className="space-y-3">
          {rows.map((notification) => {
            const url = notification.data.url;

            const body = (
              <>
                <p className="font-bold text-navy">{notification.data.title ?? "Update"}</p>
                {notification.data.body && (
                  <p className="mt-1 text-sm text-muted-foreground">{notification.data.body}</p>
                )}
                <p className="mt-2 text-xs text-muted-foreground">
                  {new Date(notification.created_at).toLocaleString()}
                </p>
              </>
            );

            return (
              <li
                key={notification.id}
                className={`flex items-start gap-3 rounded-xl border p-5 transition ${
                  notification.read ? "border-border bg-white" : "border-teal/30 bg-teal/5"
                }`}
              >
                <span className="mt-1 flex h-2 w-2 shrink-0 rounded-full bg-teal" aria-hidden>
                  {notification.read && <span className="hidden" />}
                </span>

                <div className="min-w-0 flex-1">
                  {url ? (
                    <Link
                      href={url}
                      onClick={() => !notification.read && markRead.mutate(notification.id)}
                      className="block"
                    >
                      {body}
                    </Link>
                  ) : (
                    body
                  )}
                </div>

                <div className="flex shrink-0 gap-1">
                  {!notification.read && (
                    <button
                      onClick={() => markRead.mutate(notification.id)}
                      aria-label="Mark as read"
                      title="Mark as read"
                      className="flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition hover:bg-page hover:text-teal"
                    >
                      <Check className="h-4 w-4" />
                    </button>
                  )}
                  <button
                    onClick={() => dismiss.mutate(notification.id)}
                    aria-label="Dismiss"
                    title="Dismiss"
                    className="flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition hover:bg-page hover:text-destructive"
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </>
  );
}
