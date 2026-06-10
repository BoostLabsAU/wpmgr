// Client form — used inside a Dialog for both create and edit flows.
//
// Fields: name (required), company, contact_email, phone, color (preset
// swatches + hex input), notes. Mirrors the destination-form.tsx idioms:
// react-hook-form + zod, <form onSubmit> with noValidate, aria-invalid /
// role="alert" for field errors.

import { useId } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { AgencyClient } from "@wpmgr/api";

import { useCreateClient, useUpdateClient } from "./use-clients";

// ---------------------------------------------------------------------------
// Validation schema
// ---------------------------------------------------------------------------

const schema = z.object({
  name: z.string().min(1, "Name is required").max(200, "Name must be 200 characters or fewer"),
  company: z.string().max(200).default(""),
  contact_email: z
    .string()
    .max(320)
    .refine((v) => v === "" || v.includes("@"), {
      message: "Enter a valid email address",
    })
    .default(""),
  phone: z.string().max(80).default(""),
  color: z
    .string()
    .regex(/^(#[0-9a-fA-F]{6})?$/, "Enter a valid hex color (e.g. #3b82f6)")
    .default(""),
  notes: z.string().max(2000).default(""),
});

type Values = z.infer<typeof schema>;

// ---------------------------------------------------------------------------
// Color presets — a curated palette that reads cleanly on both light and dark
// backgrounds. The user can also type a custom hex code.
// ---------------------------------------------------------------------------

const COLOR_PRESETS = [
  "#3b82f6", // blue-500
  "#8b5cf6", // violet-500
  "#ec4899", // pink-500
  "#f97316", // orange-500
  "#10b981", // emerald-500
  "#06b6d4", // cyan-500
  "#eab308", // yellow-500
  "#6b7280", // gray-500
  "#ef4444", // red-500
  "#84cc16", // lime-500
];

// ---------------------------------------------------------------------------
// Props
// ---------------------------------------------------------------------------

export interface ClientFormProps {
  /** When set, renders in edit mode pre-populated with this client. */
  initial?: AgencyClient | null;
  onSaved?: (client: AgencyClient) => void;
  onCancel?: () => void;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export function ClientForm({ initial, onSaved, onCancel }: ClientFormProps) {
  const uid = useId();
  const editing = !!initial;

  const create = useCreateClient();
  const update = useUpdateClient();
  const isPending = create.isPending || update.isPending;

  const {
    register,
    handleSubmit,
    control,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<Values>({
    resolver: zodResolver(schema) as never,
    defaultValues: {
      name: initial?.name ?? "",
      company: initial?.company ?? "",
      contact_email: initial?.contact_email ?? "",
      phone: initial?.phone ?? "",
      color: initial?.color ?? "",
      notes: initial?.notes ?? "",
    },
  });

  const watchedColor = useWatch({ control, name: "color" as never }) as string | undefined;

  const mutationError = editing ? update.error : create.error;

  return (
    <form
      id={`${uid}-form`}
      onSubmit={(e) =>
        void handleSubmit(async (values) => {
          const body = {
            name: values.name,
            company: values.company || undefined,
            contact_email: values.contact_email || undefined,
            phone: values.phone || undefined,
            color: values.color || undefined,
            notes: values.notes || undefined,
          };
          if (editing && initial) {
            const result = await update.mutateAsync({
              clientId: initial.id,
              body,
            });
            onSaved?.(result);
          } else {
            const result = await create.mutateAsync(body);
            onSaved?.(result);
          }
        })(e)
      }
      noValidate
      className="space-y-5"
      aria-label={editing ? "Edit client" : "Add client"}
    >
      {/* Name ----------------------------------------------------------------*/}
      <div className="space-y-1.5">
        <Label htmlFor={`${uid}-name`}>
          Name
          <span aria-hidden="true" className="ml-0.5 text-[var(--color-destructive)]">
            *
          </span>
        </Label>
        <Input
          id={`${uid}-name`}
          autoFocus={!editing}
          aria-invalid={!!errors.name}
          aria-describedby={errors.name ? `${uid}-name-err` : undefined}
          {...register("name")}
        />
        {errors.name ? (
          <p id={`${uid}-name-err`} role="alert" className="text-sm text-[var(--color-destructive)]">
            {errors.name.message}
          </p>
        ) : null}
      </div>

      {/* Company + contact email (2-col) ------------------------------------*/}
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor={`${uid}-company`}>Company</Label>
          <Input
            id={`${uid}-company`}
            placeholder="Acme Corp"
            aria-invalid={!!errors.company}
            {...register("company")}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={`${uid}-email`}>Contact email</Label>
          <Input
            id={`${uid}-email`}
            type="email"
            placeholder="billing@example.com"
            aria-invalid={!!errors.contact_email}
            aria-describedby={errors.contact_email ? `${uid}-email-err` : undefined}
            {...register("contact_email")}
          />
          {errors.contact_email ? (
            <p id={`${uid}-email-err`} role="alert" className="text-sm text-[var(--color-destructive)]">
              {errors.contact_email.message}
            </p>
          ) : null}
        </div>
      </div>

      {/* Phone ---------------------------------------------------------------*/}
      <div className="space-y-1.5">
        <Label htmlFor={`${uid}-phone`}>Phone</Label>
        <Input
          id={`${uid}-phone`}
          type="tel"
          placeholder="+1 555 000 0000"
          {...register("phone")}
        />
      </div>

      {/* Color ---------------------------------------------------------------*/}
      <div className="space-y-2">
        <Label>Color</Label>
        <div className="flex flex-wrap items-center gap-2">
          {COLOR_PRESETS.map((hex) => (
            <button
              key={hex}
              type="button"
              aria-label={`Set color to ${hex}`}
              aria-pressed={watchedColor === hex}
              onClick={() => setValue("color", hex)}
              style={{ backgroundColor: hex }}
              className={
                "size-6 rounded-full border-2 transition-shadow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 " +
                (watchedColor === hex
                  ? "border-[var(--color-foreground)] shadow-md"
                  : "border-transparent hover:border-[var(--color-muted-foreground)]")
              }
            />
          ))}
          {/* Custom hex input */}
          <div className="relative flex items-center gap-1.5">
            {watchedColor && !COLOR_PRESETS.includes(watchedColor) ? (
              <span
                aria-hidden="true"
                className="size-5 rounded-full border border-[var(--color-border)]"
                style={{ backgroundColor: watchedColor }}
              />
            ) : null}
            <Input
              id={`${uid}-color`}
              placeholder="#6b7280"
              className="h-8 w-28 font-mono text-xs"
              aria-invalid={!!errors.color}
              aria-describedby={errors.color ? `${uid}-color-err` : undefined}
              {...register("color")}
            />
          </div>
        </div>
        {errors.color ? (
          <p id={`${uid}-color-err`} role="alert" className="text-sm text-[var(--color-destructive)]">
            {errors.color.message}
          </p>
        ) : null}
      </div>

      {/* Notes ---------------------------------------------------------------*/}
      <div className="space-y-1.5">
        <Label htmlFor={`${uid}-notes`}>Notes</Label>
        <textarea
          id={`${uid}-notes`}
          rows={3}
          placeholder="Internal notes about this client"
          aria-invalid={!!errors.notes}
          className="w-full resize-y rounded-md border border-[var(--color-border)] bg-[var(--color-background)] px-3 py-2 text-sm placeholder:text-[var(--color-muted-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:opacity-50"
          {...register("notes")}
        />
      </div>

      {/* Mutation error ------------------------------------------------------*/}
      {mutationError ? (
        <p role="alert" className="text-sm text-[var(--color-destructive)]">
          {mutationError.message}
        </p>
      ) : null}

      {/* Actions -------------------------------------------------------------*/}
      <div className="flex flex-wrap items-center justify-end gap-2 pt-1">
        {onCancel ? (
          <Button type="button" variant="ghost" onClick={onCancel}>
            Cancel
          </Button>
        ) : null}
        <Button
          type="submit"
          disabled={isSubmitting || isPending}
        >
          {isSubmitting || isPending
            ? editing
              ? "Saving…"
              : "Adding…"
            : editing
              ? "Save changes"
              : "Add client"}
        </Button>
      </div>
    </form>
  );
}
