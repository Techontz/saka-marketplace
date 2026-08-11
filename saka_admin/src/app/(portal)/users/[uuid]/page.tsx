"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import {
  Badge,
  Button,
  Card,
  Checkbox,
  ErrorState,
  Field,
  FormError,
  Input,
  Modal,
  PageHeader,
  TableSkeleton,
  humanise,
  statusTone,
} from "@/components/ui";
import { apiGet, apiSend } from "@/lib/api/browser";
import type { AdminUser, AuditEntry, Envelope, Paginated, Role } from "@/lib/api/types";
import { useAuth } from "@/providers/AuthProvider";

const STATUS_ACTIONS = [
  { value: "active", label: "Activate", tone: "primary" as const },
  { value: "suspended", label: "Suspend", tone: "danger" as const },
  { value: "banned", label: "Ban", tone: "danger" as const },
];

/**
 * One user: their roles, their status, and what they have done.
 *
 * Two guards the API also enforces are mirrored here so the buttons are honest
 * rather than failing on click:
 *
 *   - you cannot act on YOURSELF (no self-suspension, no self-demotion) — that
 *     is how an organisation locks itself out of its own platform;
 *   - you cannot act on a SUPER ADMIN through the portal at all.
 */
export default function UserDetailPage() {
  const params = useParams<{ uuid: string }>();
  const uuid = params.uuid;

  const queryClient = useQueryClient();
  const { user: me, can } = useAuth();

  const [statusTarget, setStatusTarget] = useState<string | null>(null);
  const [statusReason, setStatusReason] = useState("");
  const [rolesOpen, setRolesOpen] = useState(false);
  const [draftRoles, setDraftRoles] = useState<string[]>([]);
  const [resetSent, setResetSent] = useState(false);

  const query = useQuery({
    queryKey: ["admin-user", uuid],
    queryFn: () => apiGet<Envelope<AdminUser>>(`/admin/users/${uuid}`).then((r) => r.data),
  });

  const roles = useQuery({
    queryKey: ["admin-roles"],
    queryFn: () => apiGet<Envelope<Role[]>>("/admin/roles").then((r) => r.data),
    enabled: can("user.assign_role"),
  });

  const activity = useQuery({
    queryKey: ["admin-user-activity", uuid],
    queryFn: () =>
      apiGet<Paginated<AuditEntry>>(`/admin/users/${uuid}/activity`, { per_page: 20 }).then(
        (r) => r.data,
      ),
    enabled: can("activity_log.view"),
  });

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ["admin-user", uuid] });
    await queryClient.invalidateQueries({ queryKey: ["admin-users"] });
    await queryClient.invalidateQueries({ queryKey: ["admin-user-activity", uuid] });
  };

  const updateStatus = useMutation({
    mutationFn: (input: { status: string; reason?: string }) =>
      apiSend(`/admin/users/${uuid}/status`, "PATCH", input),
    onSuccess: async () => {
      setStatusTarget(null);
      setStatusReason("");
      await invalidate();
    },
  });

  const updateRoles = useMutation({
    mutationFn: (next: string[]) => apiSend(`/admin/users/${uuid}/roles`, "PATCH", { roles: next }),
    onSuccess: async () => {
      setRolesOpen(false);
      await invalidate();
    },
  });

  const sendReset = useMutation({
    mutationFn: () => apiSend(`/admin/users/${uuid}/password-reset`, "POST"),
    onSuccess: () => setResetSent(true),
  });

  if (query.isPending) {
    return (
      <Card>
        <TableSkeleton rows={6} />
      </Card>
    );
  }

  if (query.error) {
    return (
      <Card>
        <ErrorState
          error={query.error}
          onRetry={() => void query.refetch()}
          title="We couldn't load this user"
        />
      </Card>
    );
  }

  const user = query.data!;
  const isSelf = me?.uuid === user.uuid;
  const isSuperAdmin = user.roles?.includes("super_admin") ?? false;
  const locked = isSelf || isSuperAdmin;

  const assignableRoles = (roles.data ?? []).filter((role) => role.is_assignable);

  return (
    <>
      <Link
        href="/users"
        className="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-soft hover:text-ink"
      >
        <ArrowLeft aria-hidden className="h-4 w-4" />
        Back to users
      </Link>

      <PageHeader
        title={[user.first_name, user.last_name].filter(Boolean).join(" ")}
        description={user.email}
        actions={
          !locked && (
            <div className="flex flex-wrap gap-2">
              {STATUS_ACTIONS.filter((entry) => entry.value !== user.status).map((entry) => (
                <Button
                  key={entry.value}
                  variant={entry.tone === "danger" ? "danger" : "primary"}
                  onClick={() => setStatusTarget(entry.value)}
                >
                  {entry.label}
                </Button>
              ))}

              {can("user.assign_role") && (
                <Button
                  variant="secondary"
                  onClick={() => {
                    setDraftRoles(user.roles ?? []);
                    setRolesOpen(true);
                  }}
                >
                  Change roles
                </Button>
              )}

              {can("user.update") && (
                <Button
                  variant="secondary"
                  loading={sendReset.isPending}
                  onClick={() => sendReset.mutate()}
                >
                  Send password reset
                </Button>
              )}
            </div>
          )
        }
      />

      {locked && (
        <div className="mb-4 rounded-[var(--radius-card)] border border-line bg-muted-soft px-4 py-3">
          <p className="text-sm font-medium text-ink">
            {isSelf ? "This is your own account" : "Super administrator"}
          </p>
          <p className="mt-0.5 text-xs text-ink-soft">
            {isSelf
              ? "You cannot change your own status or roles — that is how an organisation locks itself out of its own platform. Ask another administrator."
              : "Super administrators cannot be modified through the portal. Their roles are assignable only by the seeder or directly in the database."}
          </p>
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Card>
            <div className="border-b border-line px-5 py-3">
              <h2 className="text-sm font-semibold text-ink">Account</h2>
            </div>
            <dl className="grid grid-cols-1 gap-x-6 gap-y-3 px-5 py-4 sm:grid-cols-2">
              <Detail label="Status">
                <Badge tone={statusTone(user.status)}>{humanise(user.status)}</Badge>
              </Detail>
              <Detail label="Roles">
                {user.roles?.length
                  ? user.roles.map((role) => (
                      <Badge key={role} tone={role.includes("admin") ? "brand" : "muted"}>
                        {humanise(role)}
                      </Badge>
                    ))
                  : "—"}
              </Detail>
              <Detail label="Email verified">
                {user.email_verified ? <Badge tone="ok">Yes</Badge> : <Badge tone="muted">No</Badge>}
              </Detail>
              <Detail label="Phone verified">
                {user.phone_verified ? <Badge tone="ok">Yes</Badge> : <Badge tone="muted">No</Badge>}
              </Detail>
              <Detail label="Phone">{user.phone ?? "—"}</Detail>
              <Detail label="Listings">{user.listings_count ?? 0}</Detail>
              <Detail label="Joined">
                {user.created_at ? new Date(user.created_at).toLocaleDateString() : "—"}
              </Detail>
              <Detail label="Last seen">
                {user.last_login_at ? new Date(user.last_login_at).toLocaleString() : "Never"}
              </Detail>
            </dl>

            {user.seller_profile && (
              <div className="border-t border-line px-5 py-4">
                <p className="mb-2 text-[11px] font-semibold tracking-wide text-ink-soft uppercase">
                  Vendor profile
                </p>
                <div className="flex flex-wrap items-center gap-2 text-sm text-ink">
                  <span className="font-medium">{user.seller_profile.display_name}</span>
                  {user.seller_profile.is_verified ? (
                    <Badge tone="ok">Verified · {humanise(user.seller_profile.verification_level)}</Badge>
                  ) : (
                    <Badge tone="muted">Unverified</Badge>
                  )}
                  {user.seller_profile.rating_avg !== null && (
                    <span className="text-ink-soft">★ {user.seller_profile.rating_avg}</span>
                  )}
                </div>
              </div>
            )}
          </Card>

          {can("activity_log.view") && (
            <Card>
              <div className="border-b border-line px-5 py-3">
                <h2 className="text-sm font-semibold text-ink">Activity</h2>
                <p className="text-xs text-ink-soft">
                  Actions this user performed, and actions taken on their account.
                </p>
              </div>

              {activity.isPending ? (
                <TableSkeleton rows={4} columns={3} />
              ) : activity.error ? (
                <ErrorState error={activity.error} onRetry={() => void activity.refetch()} />
              ) : (activity.data?.length ?? 0) === 0 ? (
                <p className="px-5 py-10 text-center text-sm text-ink-soft">
                  No recorded activity for this account.
                </p>
              ) : (
                <ul className="divide-y divide-line">
                  {activity.data!.map((entry) => (
                    <li key={entry.id} className="flex items-start gap-3 px-5 py-3">
                      <Badge tone={entry.direction === "performed" ? "info" : "muted"}>
                        {entry.direction === "performed" ? "Did" : "Received"}
                      </Badge>
                      <div className="min-w-0 flex-1">
                        <p className="text-sm text-ink">{humanise(entry.action)}</p>
                        <p className="text-xs text-ink-faint">
                          {entry.actor_label ?? "system"}
                          {entry.ip_address ? ` · ${entry.ip_address}` : ""}
                        </p>
                      </div>
                      <time className="shrink-0 text-[11px] text-ink-faint">
                        {new Date(entry.created_at).toLocaleString(undefined, {
                          day: "numeric",
                          month: "short",
                          hour: "2-digit",
                          minute: "2-digit",
                        })}
                      </time>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          )}
        </div>

        <Card className="h-fit">
          <div className="border-b border-line px-5 py-3">
            <h2 className="text-sm font-semibold text-ink">Password</h2>
          </div>
          <div className="px-5 py-4">
            <p className="text-sm text-ink-soft">
              An administrator can send a reset link, but never choose a password for someone
              else — an account whose password you know is one you can sign in as, and no audit
              trail can tell that apart from the real person.
            </p>
            {resetSent && (
              <p className="mt-3 rounded-[var(--radius-control)] bg-ok-soft px-3 py-2 text-sm text-ok">
                Reset link sent to {user.email}.
              </p>
            )}
            <FormError error={sendReset.error} />
          </div>
        </Card>
      </div>

      <Modal
        open={statusTarget !== null}
        onClose={() => setStatusTarget(null)}
        title={`${STATUS_ACTIONS.find((a) => a.value === statusTarget)?.label ?? "Update"} this account?`}
        description={
          statusTarget === "active"
            ? "The user will be able to sign in again."
            : "Every one of their active sessions is revoked immediately."
        }
        footer={
          <>
            <Button variant="ghost" onClick={() => setStatusTarget(null)}>
              Cancel
            </Button>
            <Button
              variant={statusTarget === "active" ? "primary" : "danger"}
              loading={updateStatus.isPending}
              onClick={() =>
                statusTarget &&
                updateStatus.mutate({
                  status: statusTarget,
                  reason: statusReason.trim() || undefined,
                })
              }
            >
              Confirm
            </Button>
          </>
        }
      >
        <Field label="Reason" hint="Recorded in the audit trail.">
          <Input value={statusReason} onChange={(event) => setStatusReason(event.target.value)} />
        </Field>
        <FormError error={updateStatus.error} />
      </Modal>

      <Modal
        open={rolesOpen}
        onClose={() => setRolesOpen(false)}
        title="Change roles"
        description="The set is replaced, not merged. Super administrator cannot be granted here."
        footer={
          <>
            <Button variant="ghost" onClick={() => setRolesOpen(false)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              loading={updateRoles.isPending}
              onClick={() => updateRoles.mutate(draftRoles)}
            >
              Save roles
            </Button>
          </>
        }
      >
        <div className="space-y-2">
          {assignableRoles.map((role) => (
            <div key={role.name} className="rounded-[var(--radius-control)] border border-line p-3">
              <Checkbox
                label={role.label}
                checked={draftRoles.includes(role.name)}
                onChange={(event) =>
                  setDraftRoles((current) =>
                    event.target.checked
                      ? [...current, role.name]
                      : current.filter((name) => name !== role.name),
                  )
                }
              />
              {role.description && (
                <p className="mt-1 ml-6 text-xs text-ink-soft">{role.description}</p>
              )}
              <p className="mt-0.5 ml-6 text-[11px] text-ink-faint">
                {role.permissions.length} permissions
              </p>
            </div>
          ))}
        </div>
        <FormError error={updateRoles.error} />
      </Modal>
    </>
  );
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-[11px] font-semibold tracking-wide text-ink-faint uppercase">{label}</dt>
      <dd className="mt-0.5 flex flex-wrap items-center gap-1.5 text-sm text-ink">{children}</dd>
    </div>
  );
}
