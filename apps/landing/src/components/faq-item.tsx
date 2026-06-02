import { useId, useState } from "react";
import { Icon } from "@/components/icon";

/** Accordion row. The answer reveals via a grid-template-rows transition
 *  (0fr to 1fr), which animates without touching height as a layout property,
 *  and collapses to truly hidden so it stays keyboard and screen-reader sane. */
export function FAQItem({ q, a }: { q: string; a: string }) {
  const [open, setOpen] = useState(false);
  const id = useId();

  return (
    <div className="border-b border-border last:border-b-0">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        aria-controls={id}
        className="flex w-full cursor-pointer items-center justify-between gap-4 py-5 text-left transition-colors duration-[var(--duration-fast)] hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)]"
      >
        <span className="text-base font-medium text-foreground">{q}</span>
        <Icon
          name="ChevronDown"
          size={18}
          className={`shrink-0 text-muted-foreground transition-transform duration-[var(--duration-base)] ease-[var(--ease-out-quint)] ${
            open ? "rotate-180" : ""
          }`}
        />
      </button>
      <div
        id={id}
        className="grid transition-[grid-template-rows] duration-[var(--duration-base)] ease-[var(--ease-out-quint)]"
        style={{ gridTemplateRows: open ? "1fr" : "0fr" }}
      >
        <div className="overflow-hidden">
          <p className="pb-5 text-sm leading-relaxed text-muted-foreground">{a}</p>
        </div>
      </div>
    </div>
  );
}
