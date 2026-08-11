"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { Suspense, useEffect, useState } from "react";

import {
  Badge,
  Card,
  Input,
  ListState,
  PageHeader,
  Pagination,
  Select,
  humanise,
  statusTone,
} from "@/components/ui";
import { Table, TBody, TD, TH, THead, TR } from "@/components/ui/Table";
import { apiGet } from "@/lib/api/browser";
import { useDebounced, useUrlFilters } from "@/lib/hooks";
import type { AdminUser, Paginated } from "@/lib/api/types";

const ROLES = ["buyer", "seller", "agent", "moderator", "admin", "super_admin"];
const STATUSES = ["active", "pending", "suspended", "banned"];

/**
 * Everyone on the platform, filterable by role — which is how "manage admins /
 * moderators / vendors / buyers" is actually done: one list, four filters,
 * rather than four near-identical screens that drift apart.
 */
export default function UsersPage() {
  return (
    <Suspense fallback={null}>
      <UsersView />
    </Suspense>
  );
}

function UsersView() {
  const { filters, setFilters } = useUrlFilters({
    q: "",
    role: "",
    status: "",
    page: "1",
  });

  const [search, setSearch] = useState(filters.q);
  const debouncedSearch = useDebounced(search);

  useEffect(() => {
    if (debouncedSearch !== filters.q) setFilters({ q: debouncedSearch || null });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch]);

  const query = useQuery({
    queryKey: ["admin-users", filters],
    queryFn: () =>
      apiGet<Paginated<AdminUser>>("/admin/users", {
        q: filters.q || undefined,
        role: filters.role || undefined,
        status: filters.status || undefined,
        page: filters.page,
        per_page: 25,
      }),
  });

  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <>
      <PageHeader title="Users" description="Admins, moderators, vendors and buyers." />

      <Card className="mb-4">
        <div className="flex flex-wrap items-end gap-3 p-4">
          <div className="min-w-[220px] flex-1">
            <label htmlFor="user-search" className="mb-1.5 block text-[13px] font-medium text-ink">
              Search
            </label>
            <Input
              id="user-search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Name, email or phone"
            />
          </div>

          <div className="w-[170px]">
            <label htmlFor="user-role" className="mb-1.5 block text-[13px] font-medium text-ink">
              Role
            </label>
            <Select
              id="user-role"
              value={filters.role}
              onChange={(event) => setFilters({ role: event.target.value || null })}
            >
              <option value="">All roles</option>
              {ROLES.map((role) => (
                <option key={role} value={role}>
                  {humanise(role)}
                </option>
              ))}
            </Select>
          </div>

          <div className="w-[170px]">
            <label htmlFor="user-status" className="mb-1.5 block text-[13px] font-medium text-ink">
              Status
            </label>
            <Select
              id="user-status"
              value={filters.status}
              onChange={(event) => setFilters({ status: event.target.value || null })}
            >
              <option value="">All statuses</option>
              {STATUSES.map((status) => (
                <option key={status} value={status}>
                  {humanise(status)}
                </option>
              ))}
            </Select>
          </div>
        </div>
      </Card>

      <Card>
        <ListState
          isLoading={query.isPending}
          error={query.error}
          isEmpty={rows.length === 0}
          onRetry={() => void query.refetch()}
          emptyTitle="No users match these filters"
          emptyDescription="Try a different role or clear the search."
        >
          <Table>
            <THead>
              <TH>User</TH>
              <TH>Roles</TH>
              <TH>Status</TH>
              <TH>Verified</TH>
              <TH align="right">Listings</TH>
              <TH />
            </THead>
            <TBody>
              {rows.map((user) => (
                <TR key={user.uuid}>
                  <TD>
                    <Link
                      href={`/users/${user.uuid}`}
                      className="font-medium text-ink hover:text-brand"
                    >
                      {[user.first_name, user.last_name].filter(Boolean).join(" ")}
                    </Link>
                    <p className="text-xs text-ink-faint">{user.email}</p>
                  </TD>

                  <TD>
                    <div className="flex flex-wrap gap-1">
                      {user.roles?.length ? (
                        user.roles.map((role) => (
                          <Badge key={role} tone={role.includes("admin") ? "brand" : "muted"}>
                            {humanise(role)}
                          </Badge>
                        ))
                      ) : (
                        <span className="text-xs text-ink-faint">—</span>
                      )}
                    </div>
                  </TD>

                  <TD>
                    <Badge tone={statusTone(user.status)}>{humanise(user.status)}</Badge>
                  </TD>

                  <TD>
                    <div className="flex gap-1">
                      {user.email_verified && <Badge tone="ok">Email</Badge>}
                      {user.phone_verified && <Badge tone="ok">Phone</Badge>}
                      {!user.email_verified && !user.phone_verified && (
                        <span className="text-xs text-ink-faint">—</span>
                      )}
                    </div>
                  </TD>

                  <TD align="right" className="text-ink-soft">
                    {user.listings_count ?? 0}
                  </TD>

                  <TD align="right">
                    <Link
                      href={`/users/${user.uuid}`}
                      className="text-xs font-medium text-brand hover:underline"
                    >
                      Manage
                    </Link>
                  </TD>
                </TR>
              ))}
            </TBody>
          </Table>
        </ListState>

        {meta && (
          <Pagination
            page={meta.current_page}
            lastPage={meta.last_page}
            total={meta.total}
            from={meta.from}
            to={meta.to}
            disabled={query.isFetching}
            onPage={(page) => setFilters({ page }, { resetPage: false })}
          />
        )}
      </Card>
    </>
  );
}
