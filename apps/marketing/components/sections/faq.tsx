"use client";

import { useState } from "react";
import { Icon } from "@/components/ui/icon";
import { Container, Section, SectionHeading } from "@/components/ui/primitives";
import { cn } from "@/lib/utils";
import { Reveal } from "@/components/motion/reveal";
import type { FaqItem } from "@/lib/content/types";

function FaqItemRow({ q, a }: FaqItem) {
  const [open, setOpen] = useState(false);

  return (
    <div className="border-b border-[var(--border)] last:border-0">
      <button
        type="button"
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        className={cn(
          "flex w-full items-center justify-between gap-4 py-4 text-left",
          "text-base font-medium text-foreground",
          "transition-colors duration-[var(--duration-fast)] hover:text-[var(--primary)]",
          "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)] rounded-sm",
        )}
      >
        <span>{q}</span>
        <Icon
          name="ChevronDown"
          size={18}
          className={cn(
            "shrink-0 text-[var(--muted-foreground)] transition-transform duration-[var(--duration-base)]",
            open && "rotate-180",
          )}
        />
      </button>
      {open && (
        <p className="pb-5 text-sm leading-relaxed text-[var(--muted-foreground)]">{a}</p>
      )}
    </div>
  );
}

export function FAQ({
  eyebrow,
  heading,
  subhead,
  items,
}: {
  eyebrow?: string;
  heading: string;
  subhead?: string;
  items: FaqItem[];
}) {
  return (
    <Section id="faq">
      <Container>
        <Reveal>
          <SectionHeading eyebrow={eyebrow} title={heading} lead={subhead} />
        </Reveal>
        <Reveal delay={0.08}>
          <div className="mx-auto mt-12 max-w-2xl rounded-xl border border-[var(--border)] bg-card px-6 shadow-sm">
            {items.map((item) => (
              <FaqItemRow key={item.q} q={item.q} a={item.a} />
            ))}
          </div>
        </Reveal>
      </Container>
    </Section>
  );
}
