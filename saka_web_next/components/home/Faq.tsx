"use client";

import { useState } from "react";
import { Minus, Plus } from "lucide-react";

/** The FAQ accordion, ported unchanged. Content now comes from the CMS. */
export function Faq({ faqs }: { faqs: { question: string; answer: string }[] }) {
  const [open, setOpen] = useState(0);

  if (faqs.length === 0) return null;

  return (
    <section className="bg-page py-20">
      <div className="mx-auto max-w-5xl px-6">
        <div className="text-center mb-12">
          <h2 className="text-4xl md:text-5xl font-extrabold text-navy">Frequently Asked Questions</h2>
          <p className="mt-4 text-muted-foreground">
            Find answers to common questions about our property listings, rental process, and services.
          </p>
        </div>

        <div className="space-y-4">
          {faqs.map((faq, index) => (
            <div key={index} className="bg-white border border-border rounded-2xl">
              <button
                className="w-full flex items-center justify-between px-6 py-5 text-left"
                onClick={() => setOpen(open === index ? -1 : index)}
                aria-expanded={open === index}
              >
                <span className="font-bold text-navy text-lg">{faq.question}</span>
                {open === index ? (
                  <Minus className="h-5 w-5 text-teal" />
                ) : (
                  <Plus className="h-5 w-5 text-teal" />
                )}
              </button>
              {open === index && (
                <div className="px-6 pb-6 text-muted-foreground leading-relaxed">{faq.answer}</div>
              )}
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
