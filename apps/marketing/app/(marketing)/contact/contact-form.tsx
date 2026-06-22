"use client";

import { useState, useId } from "react";
import { cn } from "@/lib/utils";

type Topic = "sales" | "support" | "security" | "contributing";
type FormStatus = "idle" | "submitting" | "success" | "error";

type FormValues = {
  name: string;
  email: string;
  company: string;
  topic: Topic | "";
  message: string;
  /** Honeypot: should remain empty for human submissions */
  website: string;
};

const INITIAL: FormValues = {
  name: "",
  email: "",
  company: "",
  topic: "",
  message: "",
  website: "",
};

const TOPIC_OPTIONS: Array<{ value: Topic; label: string }> = [
  { value: "sales", label: "Sales" },
  { value: "support", label: "Support" },
  { value: "security", label: "Security report" },
  { value: "contributing", label: "Contributing" },
];

function validate(values: FormValues): Partial<Record<keyof FormValues, string>> {
  const errors: Partial<Record<keyof FormValues, string>> = {};
  if (!values.name.trim()) errors.name = "Name is required.";
  if (!values.email.trim()) {
    errors.email = "Email is required.";
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) {
    errors.email = "Please enter a valid email address.";
  }
  if (!values.topic) errors.topic = "Please select a topic.";
  if (!values.message.trim()) {
    errors.message = "Message is required.";
  } else if (values.message.trim().length < 20) {
    errors.message = "Please provide a little more detail (at least 20 characters).";
  }
  return errors;
}

type FieldProps = {
  id: string;
  label: string;
  error?: string;
  required?: boolean;
  children: React.ReactNode;
};

function Field({ id, label, error, required, children }: FieldProps) {
  const errorId = `${id}-error`;
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-sm font-medium text-foreground">
        {label}
        {required && (
          <span className="ml-1 text-[var(--destructive)]" aria-hidden>
            *
          </span>
        )}
      </label>
      {children}
      {error && (
        <p id={errorId} role="alert" className="text-xs text-[var(--destructive)]">
          {error}
        </p>
      )}
    </div>
  );
}

const inputClass =
  "h-10 w-full rounded-[var(--radius)] border border-[var(--border)] bg-card px-3 text-sm text-foreground placeholder-[var(--muted-foreground)] transition-colors duration-150 focus:border-[var(--ring)] focus:outline-none focus:ring-2 focus:ring-[var(--ring)]/30 aria-invalid:border-[var(--destructive)] aria-invalid:ring-[var(--destructive)]/30";

export function ContactForm() {
  const uid = useId();
  const [values, setValues] = useState<FormValues>(INITIAL);
  const [errors, setErrors] = useState<Partial<Record<keyof FormValues, string>>>({});
  const [status, setStatus] = useState<FormStatus>("idle");

  function set<K extends keyof FormValues>(key: K, value: FormValues[K]) {
    setValues((prev) => ({ ...prev, [key]: value }));
    if (errors[key]) {
      setErrors((prev) => {
        const next = { ...prev };
        delete next[key];
        return next;
      });
    }
  }

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();

    // Honeypot check: if the hidden website field is filled, silently succeed
    if (values.website) {
      setStatus("success");
      return;
    }

    const fieldErrors = validate(values);
    if (Object.keys(fieldErrors).length > 0) {
      setErrors(fieldErrors);
      return;
    }

    setStatus("submitting");

    try {
      const res = await fetch("/api/contact", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: values.name,
          email: values.email,
          company: values.company,
          topic: values.topic,
          message: values.message,
        }),
      });

      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }

      setStatus("success");
      setValues(INITIAL);
    } catch {
      setStatus("error");
    }
  }

  if (status === "success") {
    return (
      <div className="rounded-xl border border-[var(--border)] bg-card p-8 text-center">
        <div className="mx-auto mb-4 inline-flex h-12 w-12 items-center justify-center rounded-full bg-[var(--success,oklch(55%_0.15_145))]/15 text-[var(--success,oklch(55%_0.15_145))]">
          <svg
            aria-hidden
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            className="h-6 w-6"
          >
            <polyline points="20 6 9 17 4 12" />
          </svg>
        </div>
        <h2 className="text-xl font-semibold text-foreground">Message received</h2>
        <p className="mt-2 text-[var(--muted-foreground)]">
          We will get back to you at the email you provided. Typical response time is one business
          day.
        </p>
        <button
          type="button"
          onClick={() => setStatus("idle")}
          className="mt-6 text-sm font-medium text-[var(--primary)] underline underline-offset-4 hover:opacity-80 transition-opacity focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)] rounded-sm"
        >
          Send another message
        </button>
      </div>
    );
  }

  return (
    <form
      onSubmit={handleSubmit}
      noValidate
      aria-label="Contact form"
      className="rounded-xl border border-[var(--border)] bg-card p-6 sm:p-8"
    >
      <h2 className="mb-6 text-xl font-semibold text-foreground">Send us a message</h2>

      {/* Honeypot field: hidden from real users via CSS, bots fill it */}
      <div
        aria-hidden="true"
        style={{ position: "absolute", left: "-9999px", width: "1px", height: "1px", overflow: "hidden" }}
        tabIndex={-1}
      >
        <label htmlFor={`${uid}-website`}>Website (leave blank)</label>
        <input
          id={`${uid}-website`}
          name="website"
          type="text"
          value={values.website}
          onChange={(e) => set("website", e.target.value)}
          autoComplete="off"
          tabIndex={-1}
        />
      </div>

      <div className="space-y-5">
        <div className="grid gap-5 sm:grid-cols-2">
          <Field
            id={`${uid}-name`}
            label="Name"
            required
            error={errors.name}
          >
            <input
              id={`${uid}-name`}
              type="text"
              name="name"
              autoComplete="name"
              value={values.name}
              onChange={(e) => set("name", e.target.value)}
              aria-invalid={!!errors.name}
              aria-describedby={errors.name ? `${uid}-name-error` : undefined}
              placeholder="Your name"
              className={inputClass}
            />
          </Field>

          <Field
            id={`${uid}-email`}
            label="Email"
            required
            error={errors.email}
          >
            <input
              id={`${uid}-email`}
              type="email"
              name="email"
              autoComplete="email"
              value={values.email}
              onChange={(e) => set("email", e.target.value)}
              aria-invalid={!!errors.email}
              aria-describedby={errors.email ? `${uid}-email-error` : undefined}
              placeholder="you@company.com"
              className={inputClass}
            />
          </Field>
        </div>

        <Field id={`${uid}-company`} label="Company" error={errors.company}>
          <input
            id={`${uid}-company`}
            type="text"
            name="company"
            autoComplete="organization"
            value={values.company}
            onChange={(e) => set("company", e.target.value)}
            placeholder="Your company (optional)"
            className={inputClass}
          />
        </Field>

        <Field
          id={`${uid}-topic`}
          label="Topic"
          required
          error={errors.topic}
        >
          <select
            id={`${uid}-topic`}
            name="topic"
            value={values.topic}
            onChange={(e) => set("topic", e.target.value as Topic)}
            aria-invalid={!!errors.topic}
            aria-describedby={errors.topic ? `${uid}-topic-error` : undefined}
            className={cn(
              inputClass,
              "cursor-pointer",
              !values.topic && "text-[var(--muted-foreground)]",
            )}
          >
            <option value="" disabled>
              Select a topic
            </option>
            {TOPIC_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>
        </Field>

        <Field
          id={`${uid}-message`}
          label="Message"
          required
          error={errors.message}
        >
          <textarea
            id={`${uid}-message`}
            name="message"
            rows={5}
            value={values.message}
            onChange={(e) => set("message", e.target.value)}
            aria-invalid={!!errors.message}
            aria-describedby={errors.message ? `${uid}-message-error` : undefined}
            placeholder="Describe what you need..."
            className={cn(
              "w-full rounded-[var(--radius)] border border-[var(--border)] bg-card px-3 py-2.5 text-sm text-foreground placeholder-[var(--muted-foreground)] transition-colors duration-150 focus:border-[var(--ring)] focus:outline-none focus:ring-2 focus:ring-[var(--ring)]/30 aria-invalid:border-[var(--destructive)] aria-invalid:ring-[var(--destructive)]/30 resize-y min-h-[120px]",
            )}
          />
        </Field>

        {status === "error" && (
          <p role="alert" className="text-sm text-[var(--destructive)]">
            Something went wrong. Please try again or reach out via GitHub.
          </p>
        )}

        <button
          type="submit"
          disabled={status === "submitting"}
          className="w-full inline-flex items-center justify-center rounded-[var(--radius)] bg-primary px-5 py-2.5 text-sm font-medium text-[var(--primary-foreground)] shadow-sm transition-colors hover:bg-[var(--primary-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)] disabled:pointer-events-none disabled:opacity-55"
        >
          {status === "submitting" ? "Sending..." : "Send message"}
        </button>
      </div>
    </form>
  );
}
