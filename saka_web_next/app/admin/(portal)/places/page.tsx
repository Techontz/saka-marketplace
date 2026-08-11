"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { Suspense, useEffect, useState } from "react";

import {
  Badge,
  Button,
  Card,
  Checkbox,
  Field,
  FormError,
  Input,
  ListState,
  Modal,
  PageHeader,
  Pagination,
  Select,
  Textarea,
} from "@/components/admin/ui";
import { Table, TBody, TD, TH, THead, TR } from "@/components/admin/ui/Table";
import { apiGet, apiSend } from "@/lib/admin/api/browser";
import { useDebounced, useUrlFilters } from "@/lib/admin/hooks";
import type { AdminPlace, Envelope, Paginated, PlaceCategory } from "@/lib/admin/api/types";

type Draft = {
  slug?: string;
  name: string;
  public_place_category_id: string;
  address_line: string;
  phone: string;
  website: string;
  opening_hours: string;
  description: string;
  is_active: boolean;
};

const EMPTY: Draft = {
  name: "",
  public_place_category_id: "",
  address_line: "",
  phone: "",
  website: "",
  opening_hours: "",
  description: "",
  is_active: true,
};

/** The public-place directory: hospitals, banks, schools near a listing. */
export default function PlacesPage() {
  return (
    <Suspense fallback={null}>
      <PlacesView />
    </Suspense>
  );
}

function PlacesView() {
  const queryClient = useQueryClient();
  const { filters, setFilters } = useUrlFilters({ q: "", category: "", page: "1" });

  const [search, setSearch] = useState(filters.q);
  const debouncedSearch = useDebounced(search);

  useEffect(() => {
    if (debouncedSearch !== filters.q) setFilters({ q: debouncedSearch || null });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch]);

  const [editing, setEditing] = useState<Draft | null>(null);
  const [deleting, setDeleting] = useState<AdminPlace | null>(null);

  const categories = useQuery({
    queryKey: ["place-categories"],
    queryFn: () => apiGet<Envelope<PlaceCategory[]>>("/admin/place-categories").then((r) => r.data),
  });

  const places = useQuery({
    queryKey: ["places", filters],
    queryFn: () =>
      apiGet<Paginated<AdminPlace>>("/admin/places", {
        q: filters.q || undefined,
        category: filters.category || undefined,
        page: filters.page,
        per_page: 25,
      }),
  });

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ["places"] });
    // The category's place_count is recomputed server-side on every write.
    await queryClient.invalidateQueries({ queryKey: ["place-categories"] });
  };

  const save = useMutation({
    mutationFn: (draft: Draft) => {
      const body = {
        name: draft.name,
        public_place_category_id: Number(draft.public_place_category_id),
        address_line: draft.address_line || null,
        phone: draft.phone || null,
        website: draft.website || null,
        opening_hours: draft.opening_hours || null,
        description: draft.description || null,
        is_active: draft.is_active,
      };

      return draft.slug
        ? apiSend(`/admin/places/${draft.slug}`, "PATCH", body)
        : apiSend("/admin/places", "POST", body);
    },
    onSuccess: async () => {
      setEditing(null);
      await invalidate();
    },
  });

  const remove = useMutation({
    mutationFn: (slug: string) => apiSend(`/admin/places/${slug}`, "DELETE"),
    onSuccess: async () => {
      setDeleting(null);
      await invalidate();
    },
  });

  const rows = places.data?.data ?? [];
  const meta = places.data?.meta;
  const categoryList = categories.data ?? [];

  return (
    <>
      <PageHeader
        title="Public places"
        description="The nearby-amenities directory shown alongside listings."
        actions={
          <Button
            variant="primary"
            disabled={categoryList.length === 0}
            onClick={() =>
              setEditing({
                ...EMPTY,
                public_place_category_id: String(categoryList[0]?.id ?? ""),
              })
            }
          >
            <Plus aria-hidden className="h-4 w-4" />
            New place
          </Button>
        }
      />

      <Card className="mb-4">
        <div className="flex flex-wrap items-end gap-3 p-4">
          <div className="min-w-[220px] flex-1">
            <label htmlFor="place-search" className="mb-1.5 block text-[13px] font-medium text-ink">
              Search
            </label>
            <Input
              id="place-search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Place name"
            />
          </div>

          <div className="w-[200px]">
            <label htmlFor="place-category" className="mb-1.5 block text-[13px] font-medium text-ink">
              Category
            </label>
            <Select
              id="place-category"
              value={filters.category}
              onChange={(event) => setFilters({ category: event.target.value || null })}
            >
              <option value="">All categories</option>
              {categoryList.map((category) => (
                <option key={category.slug} value={category.slug}>
                  {category.name} ({category.place_count})
                </option>
              ))}
            </Select>
          </div>
        </div>
      </Card>

      <Card>
        <ListState
          isLoading={places.isPending}
          error={places.error}
          isEmpty={rows.length === 0}
          onRetry={() => void places.refetch()}
          emptyTitle="No places match these filters"
          emptyDescription="Add a hospital, bank or school to the directory."
        >
          <Table>
            <THead>
              <TH>Place</TH>
              <TH>Category</TH>
              <TH>Location</TH>
              <TH>Contact</TH>
              <TH />
            </THead>
            <TBody>
              {rows.map((place) => (
                <TR key={place.uuid}>
                  <TD>
                    <p className="font-medium text-ink">{place.name}</p>
                    {!place.is_active && <Badge tone="muted">Hidden</Badge>}
                  </TD>
                  <TD className="text-ink-soft">
                    {place.category ? (
                      <>
                        <span aria-hidden className="mr-1">
                          {place.category.icon}
                        </span>
                        {place.category.name}
                      </>
                    ) : (
                      "—"
                    )}
                  </TD>
                  <TD className="text-ink-soft">
                    {place.address_line ?? "—"}
                    {place.region && (
                      <span className="block text-xs text-ink-faint">{place.region}</span>
                    )}
                  </TD>
                  <TD className="text-xs text-ink-soft">
                    {place.phone ?? "—"}
                    {place.website && (
                      <a
                        href={place.website}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="block text-brand hover:underline"
                      >
                        Website
                      </a>
                    )}
                  </TD>
                  <TD align="right">
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() =>
                        setEditing({
                          slug: place.slug,
                          name: place.name,
                          public_place_category_id: String(place.category?.id ?? ""),
                          address_line: place.address_line ?? "",
                          phone: place.phone ?? "",
                          website: place.website ?? "",
                          opening_hours: place.opening_hours ?? "",
                          description: place.description ?? "",
                          is_active: place.is_active,
                        })
                      }
                    >
                      Edit
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setDeleting(place)}>
                      Delete
                    </Button>
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
            disabled={places.isFetching}
            onPage={(page) => setFilters({ page }, { resetPage: false })}
          />
        )}
      </Card>

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing?.slug ? "Edit place" : "New place"}
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditing(null)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              loading={save.isPending}
              disabled={!editing?.name.trim() || !editing?.public_place_category_id}
              onClick={() => editing && save.mutate(editing)}
            >
              Save
            </Button>
          </>
        }
      >
        {editing && (
          <div className="space-y-4">
            <Field label="Name" required>
              <Input
                value={editing.name}
                onChange={(event) => setEditing({ ...editing, name: event.target.value })}
                autoFocus
              />
            </Field>

            <Field label="Category" required>
              <Select
                value={editing.public_place_category_id}
                onChange={(event) =>
                  setEditing({ ...editing, public_place_category_id: event.target.value })
                }
              >
                <option value="">Choose a category…</option>
                {categoryList.map((category) => (
                  <option key={category.id} value={category.id}>
                    {category.name}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label="Address">
              <Input
                value={editing.address_line}
                onChange={(event) => setEditing({ ...editing, address_line: event.target.value })}
              />
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Phone">
                <Input
                  value={editing.phone}
                  onChange={(event) => setEditing({ ...editing, phone: event.target.value })}
                />
              </Field>
              <Field label="Website" hint="Must start with http:// or https://.">
                <Input
                  type="url"
                  value={editing.website}
                  onChange={(event) => setEditing({ ...editing, website: event.target.value })}
                  placeholder="https://"
                />
              </Field>
            </div>

            <Field label="Opening hours">
              <Input
                value={editing.opening_hours}
                onChange={(event) => setEditing({ ...editing, opening_hours: event.target.value })}
                placeholder="Mon–Fri 08:00–17:00"
              />
            </Field>

            <Field label="Description">
              <Textarea
                rows={3}
                value={editing.description}
                onChange={(event) => setEditing({ ...editing, description: event.target.value })}
              />
            </Field>

            <Checkbox
              label="Visible on the marketplace"
              checked={editing.is_active}
              onChange={(event) => setEditing({ ...editing, is_active: event.target.checked })}
            />

            <FormError error={save.error} />
          </div>
        )}
      </Modal>

      <Modal
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title={`Delete ${deleting?.name}?`}
        footer={
          <>
            <Button variant="ghost" onClick={() => setDeleting(null)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              loading={remove.isPending}
              onClick={() => deleting && remove.mutate(deleting.slug)}
            >
              Delete
            </Button>
          </>
        }
      >
        <FormError error={remove.error} />
      </Modal>
    </>
  );
}
