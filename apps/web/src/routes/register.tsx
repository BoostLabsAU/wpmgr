import { createFileRoute, redirect, useNavigate, Link } from "@tanstack/react-router";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useState } from "react";
import { AlertCircle, AlertTriangle, CheckCircle2, Globe } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { ensureMe, useRegister, useResendVerification } from "@/features/auth/use-auth";

export const Route = createFileRoute("/register")({
  // Already signed in? Nothing to bootstrap — go to the app.
  beforeLoad: async ({ context }) => {
    const me = await ensureMe(context.queryClient);
    if (me) {
      throw redirect({ to: "/sites" });
    }
  },
  component: RegisterPage,
});

const registerSchema = z.object({
  email: z.email("Enter a valid email address"),
  password: z.string().min(12, "Use at least 12 characters"),
  name: z.string().max(200).optional(),
  tenant_name: z.string().max(200).optional(),
  tenant_slug: z
    .string()
    .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/, "Lowercase letters, numbers, and dashes")
    .max(64)
    .optional()
    .or(z.literal("")),
});

type RegisterValues = z.infer<typeof registerSchema>;

function RegisterPage() {
  const navigate = useNavigate();
  const registerMutation = useRegister();
  const resendMutation = useResendVerification();
  // When the self-serve path succeeds we flip to a confirmation view showing
  // the email address that was registered.
  const [pendingEmail, setPendingEmail] = useState<string | null>(null);
  const [resendSent, setResendSent] = useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<RegisterValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: { email: "", password: "", name: "", tenant_name: "", tenant_slug: "" },
  });

  const onSubmit = handleSubmit(async (values) => {
    await registerMutation.mutateAsync(
      {
        email: values.email,
        password: values.password,
        name: values.name || undefined,
        tenant_name: values.tenant_name || undefined,
        tenant_slug: values.tenant_slug || undefined,
      },
      {
        onSuccess: (result) => {
          if (result.pending) {
            // Normal self-serve path: must verify email before logging in.
            setPendingEmail(values.email);
          } else {
            // First-account path: session established, go into the app.
            void navigate({ to: "/sites" });
          }
        },
        onError: () => {},
      },
    );
  });

  // --- Email-verification pending confirmation state ---
  if (pendingEmail !== null) {
    function handleResend() {
      void resendMutation.mutateAsync(
        { email: pendingEmail! },
        { onError: () => {}, onSuccess: () => setResendSent(true) },
      );
    }

    return (
      <main className="flex min-h-dvh flex-col items-center justify-center gap-6 bg-[var(--color-background)] p-4">
        <div className="flex items-center gap-2">
          <Globe aria-hidden="true" className="size-5 text-[var(--color-primary)]" />
          <span className="text-sm font-semibold tracking-tight text-[var(--color-foreground)]">
            WPMgr
          </span>
        </div>

        <Card className="w-full max-w-md">
          <CardHeader className="space-y-1">
            <div className="flex items-center gap-2">
              <CheckCircle2
                aria-hidden="true"
                className="size-5 shrink-0 text-[var(--color-primary)]"
              />
              <CardTitle asChild>
                <h1>Check your email</h1>
              </CardTitle>
            </div>
            <CardDescription>
              We sent a verification link to{" "}
              <span className="font-medium text-[var(--color-foreground)]">
                {pendingEmail}
              </span>
              . Click it to activate your account and sign in.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <p className="text-sm text-[var(--color-muted-foreground)]">
              Didn't receive it? Check your spam folder or{" "}
              {resendSent ? (
                <span className="text-[var(--color-foreground)]">
                  we've resent the link.
                </span>
              ) : (
                <button
                  type="button"
                  className="text-[var(--color-foreground)] underline underline-offset-4 disabled:opacity-50"
                  disabled={resendMutation.isPending}
                  onClick={handleResend}
                >
                  resend the verification email.
                </button>
              )}
            </p>
            <p className="text-center text-xs text-[var(--color-muted-foreground)]">
              Already verified?{" "}
              <Link
                to="/login"
                className="text-[var(--color-foreground)] underline underline-offset-4"
              >
                Sign in
              </Link>
            </p>
          </CardContent>
        </Card>
      </main>
    );
  }

  // --- Registration form ---

  const serverError = registerMutation.isError
    ? registerMutation.error.message
    : null;

  return (
    <main className="flex min-h-dvh flex-col items-center justify-center gap-6 bg-[var(--color-background)] p-4">
      {/* WPMgr wordmark — same Globe + text treatment as the sidebar BrandStrip */}
      <div className="flex items-center gap-2">
        <Globe aria-hidden="true" className="size-5 text-[var(--color-primary)]" />
        <span className="text-sm font-semibold tracking-tight text-[var(--color-foreground)]">
          WPMgr
        </span>
      </div>

      <Card className="w-full max-w-md">
        <CardHeader className="space-y-1">
          <CardTitle asChild>
            <h1>Create an account</h1>
          </CardTitle>
          <CardDescription>
            Sign up for WPMgr. We'll send you a verification email to activate
            your account.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form
            onSubmit={(e) => void onSubmit(e)}
            noValidate
            className="space-y-4"
          >
            {serverError ? (
              <div
                role="alert"
                className="flex items-start gap-2.5 rounded-md border border-[var(--color-destructive)]/30 bg-[var(--color-card)] px-3 py-2.5"
              >
                <AlertTriangle
                  aria-hidden="true"
                  className="mt-0.5 size-4 shrink-0 text-[var(--color-destructive)]"
                />
                <p className="text-sm text-[var(--color-destructive)]">
                  {serverError}
                </p>
              </div>
            ) : null}

            <Field
              id="email"
              label="Email"
              type="email"
              autoComplete="email"
              register={register}
              error={errors.email?.message}
            />
            <Field
              id="password"
              label="Password"
              type="password"
              autoComplete="new-password"
              register={register}
              error={errors.password?.message}
            />
            <Field
              id="name"
              label="Your name (optional)"
              register={register}
              error={errors.name?.message}
            />
            <Field
              id="tenant_name"
              label="Organization name (optional)"
              register={register}
              error={errors.tenant_name?.message}
            />
            <Field
              id="tenant_slug"
              label="Organization slug (optional)"
              register={register}
              error={errors.tenant_slug?.message}
            />

            <Button
              type="submit"
              className="w-full"
              disabled={isSubmitting || registerMutation.isPending}
            >
              Create account
            </Button>
          </form>

          <p className="mt-4 text-center text-xs text-[var(--color-muted-foreground)]">
            Already have an account?{" "}
            <Link
              to="/login"
              className="text-[var(--color-foreground)] underline underline-offset-4"
            >
              Sign in
            </Link>
          </p>
        </CardContent>
      </Card>
    </main>
  );
}

function Field({
  id,
  label,
  type = "text",
  autoComplete,
  register,
  error,
}: {
  id: keyof RegisterValues;
  label: string;
  type?: string;
  autoComplete?: string;
  register: ReturnType<typeof useForm<RegisterValues>>["register"];
  error?: string;
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        type={type}
        autoComplete={autoComplete}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${id}-error` : undefined}
        {...register(id)}
      />
      {error ? (
        <p
          id={`${id}-error`}
          role="alert"
          className="flex items-center gap-1.5 text-sm text-[var(--color-destructive)]"
        >
          <AlertCircle aria-hidden="true" className="size-3.5 shrink-0" />
          {error}
        </p>
      ) : null}
    </div>
  );
}
