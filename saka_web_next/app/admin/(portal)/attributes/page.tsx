"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
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
  Select,
  humanise,
} from "@/components/admin/ui";
import { Table, TBody, TD, TH, THead, TR } from "@/components/admin/ui/Table";
import { apiGet, apiSend } from "@/lib/admin/api/browser";
import type { Attribute, Envelope, TaxonomyTerm } from "@/lib/admin/api/types";

const INPUT_TYPES = ["text", "number", "select", "multiselect", "boolean", "date"];
const DATA_TYPES = ["string", "integer", "decimal", "boolean", "date"];

type Draft = {
  existing?: string;
  code: string;
  name: string;
  input_type: string;
  data_type: string;
  unit: string;
  is_filterable: boolean;
};

const EMPTY: Draft = {
  code: "",
  name: "",
  input_type: "text",
  data_type: "string",
  unit: "",
  is_filterable: true,
};

/**
 * Dynamic attributes, amenities and facilities — the EAV vocabulary.
 *
 * All three on one screen because they are the same job (curating the words a
 * listing can be described with) and none is big enough to justify its own
 * page.
 */
export default function AttributesPage() {
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<"attributes" | "amenities" | "facilities">("attributes");

  return (
    <>
      <PageHeader
        title="Attributes & taxonomy"
        description="The vocabulary listings are described with."
      />

      <div className="mb-4 flex gap-1 rounded-[var(--radius-control)] border border-line bg-surface p-1">
        {(["attributes", "amenities", "facilities"] as const).map((value) => (
          <button
            key={value}
            type="button"
            onClick={() => setTab(value)}
            aria-current={tab === value ? "true" : undefined}
            className={
              "flex-1 rounded-[calc(var(--radius-control)-2px)] px-3 py-1.5 text-sm font-medium transition-colors " +
              (tab === value ? "bg-brand-soft text-brand-ink" : "text-ink-soft hover:text-ink")
            }
          >
            {humanise(value)}
          </button>
        ))}
      </div>

      {tab === "attributes" ? (
        <AttributesTable queryClient={queryClient} />
      ) : (
        <TermsTable type={tab} queryClient={queryClient} />
      )}
    </>
  );
}

function AttributesTable({ queryClient }: { queryClient: ReturnType<typeof useQueryClient> }) {
  const [editing, setEditing] = useState<Draft | null>(null);
  const [deleting, setDeleting] = useState<Attribute | null>(null);

  const query = useQuery({
    queryKey: ["admin-attributes"],
    queryFn: () => apiGet<Envelope<Attribute[]>>("/admin/attributes").then((r) => r.data),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["admin-attributes"] });

  const save = useMutation({
    mutationFn: (draft: Draft) => {
      const body = {
        name: draft.name,
        input_type: draft.input_type,
        data_type: draft.data_type,
        unit: draft.unit || null,
        is_filterable: draft.is_filterable,
        // `code` is PROHIBITED on update — it is a public filter key
        // (?attributes[beds]=3), so changing it breaks every saved search and
        // bookmarked URL. Only sent on create.
        ...(draft.existing ? {} : { code: draft.code }),
      };

      return draft.existing
        ? apiSend(`/admin/attributes/${draft.existing}`, "PATCH", body)
        : apiSend("/admin/attributes", "POST", body);
    },
    onSuccess: async () => {
      setEditing(null);
      await invalidate();
    },
  });

  const remove = useMutation({
    mutationFn: (code: string) => apiSend(`/admin/attributes/${code}`, "DELETE"),
    onSuccess: async () => {
      setDeleting(null);
      await invalidate();
    },
  });

  const rows = query.data ?? [];

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button variant="primary" onClick={() => setEditing({ ...EMPTY })}>
          <Plus aria-hidden className="h-4 w-4" />
          New attribute
        </Button>
      </div>

      <Card>
        <ListState
          isLoading={query.isPending}
          error={query.error}
          isEmpty={rows.length === 0}
          onRetry={() => void query.refetch()}
          emptyTitle="No attributes defined"
          emptyDescription="Attributes are what let a vehicle have mileage and a property have bedrooms."
        >
          <Table>
            <THead>
              <TH>Code</TH>
              <TH>Name</TH>
              <TH>Input</TH>
              <TH>Stored as</TH>
              <TH>Options</TH>
              <TH />
            </THead>
            <TBody>
              {rows.map((attribute) => (
                <TR key={attribute.code}>
                  <TD>
                    <code className="font-mono text-xs text-ink">{attribute.code}</code>
                  </TD>
                  <TD>
                    {attribute.name}
                    {attribute.unit && (
                      <span className="ml-1 text-xs text-ink-faint">({attribute.unit})</span>
                    )}
                  </TD>
                  <TD>
                    <Badge tone="muted">{humanise(attribute.input_type)}</Badge>
                    {attribute.is_filterable && <Badge tone="info">Filterable</Badge>}
                  </TD>
                  <TD className="text-ink-soft">{humanise(attribute.data_type)}</TD>
                  <TD className="text-ink-soft">{attribute.options?.length ?? 0}</TD>
                  <TD align="right">
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() =>
                        setEditing({
                          existing: attribute.code,
                          code: attribute.code,
                          name: attribute.name,
                          input_type: attribute.input_type,
                          data_type: attribute.data_type,
                          unit: attribute.unit ?? "",
                          is_filterable: attribute.is_filterable,
                        })
                      }
                    >
                      Edit
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setDeleting(attribute)}>
                      Delete
                    </Button>
                  </TD>
                </TR>
              ))}
            </TBody>
          </Table>
        </ListState>
      </Card>

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing?.existing ? `Edit ${editing.existing}` : "New attribute"}
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditing(null)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              loading={save.isPending}
              disabled={!editing?.name.trim() || (!editing.existing && !editing.code.trim())}
              onClick={() => editing && save.mutate(editing)}
            >
              Save
            </Button>
          </>
        }
      >
        {editing && (
          <div className="space-y-4">
            <Field
              label="Code"
              required={!editing.existing}
              hint={
                editing.existing
                  ? "Immutable — this is a public filter key, and changing it would break every saved search."
                  : "Lowercase letters, digits and underscores. Cannot be changed later."
              }
            >
              <Input
                value={editing.code}
                disabled={Boolean(editing.existing)}
                onChange={(event) =>
                  setEditing({ ...editing, code: event.target.value.toLowerCase() })
                }
              />
            </Field>

            <Field label="Name" required>
              <Input
                value={editing.name}
                onChange={(event) => setEditing({ ...editing, name: event.target.value })}
              />
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Input type">
                <Select
                  value={editing.input_type}
                  onChange={(event) => setEditing({ ...editing, input_type: event.target.value })}
                >
                  {INPUT_TYPES.map((type) => (
                    <option key={type} value={type}>
                      {humanise(type)}
                    </option>
                  ))}
                </Select>
              </Field>

              <Field label="Stored as" hint="Decides which typed column holds the value.">
                <Select
                  value={editing.data_type}
                  onChange={(event) => setEditing({ ...editing, data_type: event.target.value })}
                >
                  {DATA_TYPES.map((type) => (
                    <option key={type} value={type}>
                      {humanise(type)}
                    </option>
                  ))}
                </Select>
              </Field>
            </div>

            <Field label="Unit" hint="e.g. km, sqft, GB. Shown after the value.">
              <Input
                value={editing.unit}
                onChange={(event) => setEditing({ ...editing, unit: event.target.value })}
              />
            </Field>

            <Checkbox
              label="Filterable — appears in search filters"
              checked={editing.is_filterable}
              onChange={(event) => setEditing({ ...editing, is_filterable: event.target.checked })}
            />

            <FormError error={save.error} />
          </div>
        )}
      </Modal>

      <Modal
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title={`Delete ${deleting?.name}?`}
        description="Attributes still holding values on listings cannot be deleted."
        footer={
          <>
            <Button variant="ghost" onClick={() => setDeleting(null)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              loading={remove.isPending}
              onClick={() => deleting && remove.mutate(deleting.code)}
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

function TermsTable({
  type,
  queryClient,
}: {
  type: "amenities" | "facilities";
  queryClient: ReturnType<typeof useQueryClient>;
}) {
  const [editing, setEditing] = useState<{ slug?: string; name: string; icon: string } | null>(null);
  const [deleting, setDeleting] = useState<TaxonomyTerm | null>(null);

  const query = useQuery({
    queryKey: ["taxonomy", type],
    // Public read; there is no admin index for these.
    queryFn: () => apiGet<Envelope<TaxonomyTerm[]>>(`/${type}`).then((r) => r.data),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["taxonomy", type] });

  const save = useMutation({
    mutationFn: (draft: { slug?: string; name: string; icon: string }) =>
      draft.slug
        ? apiSend(`/admin/taxonomy/${type}/${draft.slug}`, "PATCH", {
            name: draft.name,
            icon: draft.icon || null,
          })
        : apiSend(`/admin/taxonomy/${type}`, "POST", {
            name: draft.name,
            icon: draft.icon || null,
          }),
    onSuccess: async () => {
      setEditing(null);
      await invalidate();
    },
  });

  const remove = useMutation({
    mutationFn: (slug: string) => apiSend(`/admin/taxonomy/${type}/${slug}`, "DELETE"),
    onSuccess: async () => {
      setDeleting(null);
      await invalidate();
    },
  });

  const rows = query.data ?? [];

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button variant="primary" onClick={() => setEditing({ name: "", icon: "" })}>
          <Plus aria-hidden className="h-4 w-4" />
          New {type === "amenities" ? "amenity" : "facility"}
        </Button>
      </div>

      <Card>
        <ListState
          isLoading={query.isPending}
          error={query.error}
          isEmpty={rows.length === 0}
          onRetry={() => void query.refetch()}
          emptyTitle={`No ${type} defined`}
        >
          <Table>
            <THead>
              <TH>Name</TH>
              <TH>Slug</TH>
              <TH />
            </THead>
            <TBody>
              {rows.map((term) => (
                <TR key={term.slug}>
                  <TD>
                    <span aria-hidden className="mr-2">
                      {term.icon ?? ""}
                    </span>
                    {term.name}
                  </TD>
                  <TD>
                    <code className="font-mono text-xs text-ink-faint">{term.slug}</code>
                  </TD>
                  <TD align="right">
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() =>
                        setEditing({ slug: term.slug, name: term.name, icon: term.icon ?? "" })
                      }
                    >
                      Edit
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setDeleting(term)}>
                      Delete
                    </Button>
                  </TD>
                </TR>
              ))}
            </TBody>
          </Table>
        </ListState>
      </Card>

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing?.slug ? "Edit term" : "New term"}
        description={editing?.slug ? "The slug is a public filter value and is immutable." : undefined}
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
            <Field label="Icon">
              <Input
                value={editing.icon}
                onChange={(event) => setEditing({ ...editing, icon: event.target.value })}
                maxLength={4}
              />
            </Field>
            <FormError error={save.error} />
          </div>
        )}
      </Modal>

      <Modal
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title={`Delete ${deleting?.name}?`}
        description="Terms still referenced by listings cannot be deleted — deactivate instead."
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
