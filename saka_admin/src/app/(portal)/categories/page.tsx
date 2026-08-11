"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ChevronDown, ChevronRight, Plus } from "lucide-react";
import { useState } from "react";

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
  Textarea,
} from "@/components/ui";
import { apiGet, apiSend } from "@/lib/api/browser";
import type { Category, Envelope } from "@/lib/api/types";
import { formatCount } from "@/lib/format";

type Draft = {
  slug?: string;
  name: string;
  parent_id?: number | null;
  icon: string;
  description: string;
  position: string;
  is_active: boolean;
};

const EMPTY: Draft = { name: "", icon: "", description: "", position: "0", is_active: true };

/**
 * The category tree.
 *
 * Rendered as an expandable two-level tree rather than a flat table, because
 * the hierarchy is the thing being managed — a flat list of 72 rows makes
 * "which vertical is this under?" a lookup rather than something you can see.
 *
 * Reparenting is deliberately not offered: it would invalidate the materialised
 * path of every descendant, which is a data migration rather than an edit. The
 * API ignores `parent_id` on update for the same reason.
 */
export default function CategoriesPage() {
  const queryClient = useQueryClient();

  const [expanded, setExpanded] = useState<string[]>([]);
  const [editing, setEditing] = useState<Draft | null>(null);
  const [deleting, setDeleting] = useState<Category | null>(null);

  const query = useQuery({
    queryKey: ["categories"],
    // The public tree endpoint: categories are public data, and there is no
    // separate admin read. It carries listing_count, which is what a curator
    // needs to know before deleting anything.
    queryFn: () => apiGet<Envelope<Category[]>>("/categories").then((r) => r.data),
  });

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ["categories"] });
    await queryClient.invalidateQueries({ queryKey: ["stats"] });
  };

  const save = useMutation({
    mutationFn: (draft: Draft) => {
      const body = {
        name: draft.name,
        icon: draft.icon || null,
        description: draft.description || null,
        position: Number(draft.position) || 0,
        is_active: draft.is_active,
        // Only sent on create — the API ignores it on update anyway.
        ...(draft.slug ? {} : { parent_id: draft.parent_id ?? null }),
      };

      return draft.slug
        ? apiSend(`/admin/categories/${draft.slug}`, "PATCH", body)
        : apiSend("/admin/categories", "POST", body);
    },
    onSuccess: async () => {
      setEditing(null);
      await invalidate();
    },
  });

  const remove = useMutation({
    mutationFn: (slug: string) => apiSend(`/admin/categories/${slug}`, "DELETE"),
    onSuccess: async () => {
      setDeleting(null);
      await invalidate();
    },
  });

  const roots = query.data ?? [];

  return (
    <>
      <PageHeader
        title="Categories"
        description="The marketplace taxonomy. Listings attach to leaf categories only."
        actions={
          <Button variant="primary" onClick={() => setEditing({ ...EMPTY })}>
            <Plus aria-hidden className="h-4 w-4" />
            New category
          </Button>
        }
      />

      <Card>
        <ListState
          isLoading={query.isPending}
          error={query.error}
          isEmpty={roots.length === 0}
          onRetry={() => void query.refetch()}
          emptyTitle="No categories yet"
          emptyDescription="Create a top-level vertical to get started."
        >
          <ul className="divide-y divide-line">
            {roots.map((root) => {
              const open = expanded.includes(root.slug);

              return (
                <li key={root.slug}>
                  <div className="flex items-center gap-3 px-4 py-3">
                    <button
                      type="button"
                      aria-expanded={open}
                      aria-label={open ? `Collapse ${root.name}` : `Expand ${root.name}`}
                      onClick={() =>
                        setExpanded((current) =>
                          open
                            ? current.filter((slug) => slug !== root.slug)
                            : [...current, root.slug],
                        )
                      }
                      className="text-ink-faint hover:text-ink"
                    >
                      {open ? (
                        <ChevronDown className="h-4 w-4" />
                      ) : (
                        <ChevronRight className="h-4 w-4" />
                      )}
                    </button>

                    <span aria-hidden className="text-lg">
                      {root.icon ?? "•"}
                    </span>

                    <div className="min-w-0 flex-1">
                      <p className="font-medium text-ink">{root.name}</p>
                      <p className="text-xs text-ink-faint">
                        {root.children?.length ?? 0} subcategories ·{" "}
                        {formatCount(root.listing_count)} listings
                      </p>
                    </div>

                    {!root.is_leaf && <Badge tone="muted">Vertical</Badge>}
                    {!root.image_url && <Badge tone="warn">No image</Badge>}

                    <div className="flex gap-1.5">
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                          setEditing({
                            slug: root.slug,
                            name: root.name,
                            icon: root.icon ?? "",
                            description: root.description ?? "",
                            position: "0",
                            is_active: true,
                          })
                        }
                      >
                        Edit
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                          setEditing({
                            ...EMPTY,
                            parent_id: (root as Category & { id?: number }).id ?? null,
                            name: "",
                          })
                        }
                      >
                        Add child
                      </Button>
                    </div>
                  </div>

                  {open && (
                    <ul className="border-t border-line bg-canvas">
                      {(root.children ?? []).map((child) => (
                        <li
                          key={child.slug}
                          className="flex items-center gap-3 border-b border-line px-4 py-2.5 pl-14 last:border-b-0"
                        >
                          <div className="min-w-0 flex-1">
                            <p className="text-sm text-ink">{child.name}</p>
                            <p className="text-xs text-ink-faint">
                              {formatCount(child.listing_count)} listings · {child.slug}
                            </p>
                          </div>

                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                              setEditing({
                                slug: child.slug,
                                name: child.name,
                                icon: child.icon ?? "",
                                description: child.description ?? "",
                                position: "0",
                                is_active: true,
                              })
                            }
                          >
                            Edit
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => setDeleting(child)}>
                            Delete
                          </Button>
                        </li>
                      ))}

                      {(root.children ?? []).length === 0 && (
                        <li className="px-4 py-3 pl-14 text-sm text-ink-faint">
                          No subcategories.
                        </li>
                      )}
                    </ul>
                  )}
                </li>
              );
            })}
          </ul>
        </ListState>
      </Card>

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing?.slug ? "Edit category" : "New category"}
        description={
          editing?.slug
            ? "The slug is a public URL segment and cannot be changed. Reparenting is not supported — it would invalidate every descendant's path."
            : undefined
        }
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditing(null)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              loading={save.isPending}
              disabled={!editing?.name.trim()}
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

            <Field label="Icon" hint="A single emoji, shown in the category browser.">
              <Input
                value={editing.icon}
                onChange={(event) => setEditing({ ...editing, icon: event.target.value })}
                maxLength={4}
              />
            </Field>

            <Field label="Description">
              <Textarea
                rows={3}
                value={editing.description}
                onChange={(event) => setEditing({ ...editing, description: event.target.value })}
              />
            </Field>

            <Field label="Position" hint="Lower numbers sort first.">
              <Input
                type="number"
                value={editing.position}
                onChange={(event) => setEditing({ ...editing, position: event.target.value })}
              />
            </Field>

            <Checkbox
              label="Active"
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
        description="Categories holding listings or subcategories cannot be deleted — deactivate them instead."
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
        {deleting && deleting.listing_count > 0 && (
          <p className="rounded-[var(--radius-control)] bg-warn-soft px-3 py-2 text-sm text-warn">
            This category holds {formatCount(deleting.listing_count)} listings. The API will
            refuse — deactivate it instead.
          </p>
        )}
        <FormError error={remove.error} />
      </Modal>
    </>
  );
}
