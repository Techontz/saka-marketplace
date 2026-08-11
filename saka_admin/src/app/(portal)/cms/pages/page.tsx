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
  Textarea,
} from "@/components/ui";
import { Table, TBody, TD, TH, THead, TR } from "@/components/ui/Table";
import { apiGet, apiSend } from "@/lib/api/browser";
import type { Envelope, Faq, Page } from "@/lib/api/types";

type PageDraft = {
  existing?: string;
  slug: string;
  title: string;
  body: string;
  meta_title: string;
  meta_description: string;
};

type FaqDraft = { id?: number; question: string; answer: string; group: string; is_active: boolean };

/** CMS pages and FAQs. */
export default function ContentPage() {
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<"pages" | "faqs">("pages");

  return (
    <>
      <PageHeader title="Pages & FAQs" description="Editorial content served to the marketplace." />

      <div className="mb-4 flex gap-1 rounded-[var(--radius-control)] border border-line bg-surface p-1">
        {(["pages", "faqs"] as const).map((value) => (
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
            {value === "pages" ? "Pages" : "FAQs"}
          </button>
        ))}
      </div>

      {tab === "pages" ? <PagesTable client={queryClient} /> : <FaqsTable client={queryClient} />}
    </>
  );
}

function PagesTable({ client }: { client: ReturnType<typeof useQueryClient> }) {
  const [editing, setEditing] = useState<PageDraft | null>(null);

  const query = useQuery({
    queryKey: ["cms-pages"],
    queryFn: () => apiGet<Envelope<Page[]>>("/admin/pages").then((r) => r.data),
  });

  const save = useMutation({
    mutationFn: (draft: PageDraft) => {
      const body = {
        title: draft.title,
        body: draft.body || null,
        meta_title: draft.meta_title || null,
        meta_description: draft.meta_description || null,
      };

      return draft.existing
        ? apiSend(`/admin/pages/${draft.existing}`, "PATCH", body)
        : apiSend("/admin/pages", "POST", { ...body, slug: draft.slug });
    },
    onSuccess: async () => {
      setEditing(null);
      await client.invalidateQueries({ queryKey: ["cms-pages"] });
    },
  });

  const publish = useMutation({
    mutationFn: ({ slug, published }: { slug: string; published: boolean }) =>
      apiSend(`/admin/pages/${slug}/publish`, "POST", { published }),
    onSuccess: () => client.invalidateQueries({ queryKey: ["cms-pages"] }),
  });

  const rows = query.data ?? [];

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button
          variant="primary"
          onClick={() =>
            setEditing({ slug: "", title: "", body: "", meta_title: "", meta_description: "" })
          }
        >
          <Plus aria-hidden className="h-4 w-4" />
          New page
        </Button>
      </div>

      <Card>
        <ListState
          isLoading={query.isPending}
          error={query.error}
          isEmpty={rows.length === 0}
          onRetry={() => void query.refetch()}
          emptyTitle="No pages"
        >
          <Table>
            <THead>
              <TH>Page</TH>
              <TH>Slug</TH>
              <TH>State</TH>
              <TH />
            </THead>
            <TBody>
              {rows.map((page) => (
                <TR key={page.slug}>
                  <TD>
                    <p className="font-medium text-ink">{page.title}</p>
                    {!page.body && <p className="text-xs text-warn">No content yet</p>}
                  </TD>
                  <TD>
                    <code className="font-mono text-xs text-ink-faint">/{page.slug}</code>
                  </TD>
                  <TD>
                    {page.is_published ? (
                      <Badge tone="ok">Published</Badge>
                    ) : (
                      <Badge tone="muted">Draft</Badge>
                    )}
                  </TD>
                  <TD align="right">
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() =>
                        setEditing({
                          existing: page.slug,
                          slug: page.slug,
                          title: page.title,
                          body: page.body ?? "",
                          meta_title: page.meta_title ?? "",
                          meta_description: page.meta_description ?? "",
                        })
                      }
                    >
                      Edit
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      loading={publish.isPending && publish.variables?.slug === page.slug}
                      // Publishing is explicit and separate from saving, so a
                      // draft cannot go live by accident. The API also refuses
                      // to publish a page with an empty body.
                      onClick={() =>
                        publish.mutate({ slug: page.slug, published: !page.is_published })
                      }
                    >
                      {page.is_published ? "Unpublish" : "Publish"}
                    </Button>
                  </TD>
                </TR>
              ))}
            </TBody>
          </Table>
        </ListState>
        <FormError error={publish.error} />
      </Card>

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing?.existing ? `Edit ${editing.existing}` : "New page"}
        description={
          editing?.existing
            ? "The slug is the public URL and cannot be changed."
            : "Created as a draft — publishing is a separate, explicit step."
        }
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditing(null)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              loading={save.isPending}
              disabled={!editing?.title.trim() || (!editing.existing && !editing.slug.trim())}
              onClick={() => editing && save.mutate(editing)}
            >
              Save
            </Button>
          </>
        }
      >
        {editing && (
          <div className="space-y-4">
            {!editing.existing && (
              <Field label="Slug" required hint="Lowercase letters, digits and hyphens.">
                <Input
                  value={editing.slug}
                  onChange={(event) =>
                    setEditing({ ...editing, slug: event.target.value.toLowerCase() })
                  }
                />
              </Field>
            )}

            <Field label="Title" required>
              <Input
                value={editing.title}
                onChange={(event) => setEditing({ ...editing, title: event.target.value })}
              />
            </Field>

            <Field label="Body" hint="HTML is allowed and rendered as-is on the marketplace.">
              <Textarea
                rows={10}
                value={editing.body}
                onChange={(event) => setEditing({ ...editing, body: event.target.value })}
              />
            </Field>

            <Field label="Meta title">
              <Input
                value={editing.meta_title}
                onChange={(event) => setEditing({ ...editing, meta_title: event.target.value })}
              />
            </Field>

            <Field label="Meta description">
              <Textarea
                rows={2}
                value={editing.meta_description}
                onChange={(event) =>
                  setEditing({ ...editing, meta_description: event.target.value })
                }
              />
            </Field>

            <FormError error={save.error} />
          </div>
        )}
      </Modal>
    </>
  );
}

function FaqsTable({ client }: { client: ReturnType<typeof useQueryClient> }) {
  const [editing, setEditing] = useState<FaqDraft | null>(null);
  const [deleting, setDeleting] = useState<Faq | null>(null);

  const query = useQuery({
    queryKey: ["cms-faqs"],
    queryFn: () => apiGet<Envelope<Faq[]>>("/admin/faqs").then((r) => r.data),
  });

  const save = useMutation({
    mutationFn: (draft: FaqDraft) => {
      const body = {
        question: draft.question,
        answer: draft.answer,
        group: draft.group || null,
        is_active: draft.is_active,
      };

      return draft.id
        ? apiSend(`/admin/faqs/${draft.id}`, "PATCH", body)
        : apiSend("/admin/faqs", "POST", body);
    },
    onSuccess: async () => {
      setEditing(null);
      await client.invalidateQueries({ queryKey: ["cms-faqs"] });
    },
  });

  const remove = useMutation({
    mutationFn: (id: number) => apiSend(`/admin/faqs/${id}`, "DELETE"),
    onSuccess: async () => {
      setDeleting(null);
      await client.invalidateQueries({ queryKey: ["cms-faqs"] });
    },
  });

  const rows = query.data ?? [];

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button
          variant="primary"
          onClick={() =>
            setEditing({ question: "", answer: "", group: "general", is_active: true })
          }
        >
          <Plus aria-hidden className="h-4 w-4" />
          New FAQ
        </Button>
      </div>

      <Card>
        <ListState
          isLoading={query.isPending}
          error={query.error}
          isEmpty={rows.length === 0}
          onRetry={() => void query.refetch()}
          emptyTitle="No FAQs"
        >
          <ul className="divide-y divide-line">
            {rows.map((faq) => (
              <li key={faq.id} className="flex items-start gap-4 px-5 py-4">
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-ink">{faq.question}</p>
                  <p className="mt-0.5 line-clamp-2 text-sm text-ink-soft">{faq.answer}</p>
                  <div className="mt-1.5 flex gap-1.5">
                    {faq.group && <Badge tone="muted">{faq.group}</Badge>}
                    {!faq.is_active && <Badge tone="warn">Hidden</Badge>}
                  </div>
                </div>

                <div className="flex shrink-0 gap-1.5">
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                      setEditing({
                        id: faq.id,
                        question: faq.question,
                        answer: faq.answer,
                        group: faq.group ?? "",
                        is_active: faq.is_active,
                      })
                    }
                  >
                    Edit
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setDeleting(faq)}>
                    Delete
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        </ListState>
      </Card>

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing?.id ? "Edit FAQ" : "New FAQ"}
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditing(null)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              loading={save.isPending}
              disabled={!editing?.question.trim() || !editing?.answer.trim()}
              onClick={() => editing && save.mutate(editing)}
            >
              Save
            </Button>
          </>
        }
      >
        {editing && (
          <div className="space-y-4">
            <Field label="Question" required>
              <Input
                value={editing.question}
                onChange={(event) => setEditing({ ...editing, question: event.target.value })}
                autoFocus
              />
            </Field>
            <Field label="Answer" required>
              <Textarea
                rows={5}
                value={editing.answer}
                onChange={(event) => setEditing({ ...editing, answer: event.target.value })}
              />
            </Field>
            <Field label="Group" hint="Groups FAQs on the marketplace, e.g. general, payments.">
              <Input
                value={editing.group}
                onChange={(event) => setEditing({ ...editing, group: event.target.value })}
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
        title="Delete this FAQ?"
        description={deleting?.question}
        footer={
          <>
            <Button variant="ghost" onClick={() => setDeleting(null)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              loading={remove.isPending}
              onClick={() => deleting && remove.mutate(deleting.id)}
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
