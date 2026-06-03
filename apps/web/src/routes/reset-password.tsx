import { createFileRoute, Link, useNavigate } from "@tanstack/react-router";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AlertCircle, AlertTriangle } from "lucide-react";
import { useState } from "react";

import { AuthLayout } from "@/components/layout/auth-layout";
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
import { useResetPassword } from "@/features/auth/use-auth";
import { toast } from "@/components/toast";

// Unauthenticated — NOT under _authed, no beforeLoad guard.
const searchSchema = z.object({
  token: z.string().optional(),
});

export const Route = createFileRoute("/reset-password")({
  validateSearch: searchSchema,
  component: ResetPasswordPage,
});

const resetSchema = z
  .object({
    password: z
      .string()
      .min(12, "Password must be at least 12 characters"),
    confirm: z.string().min(1, "Please confirm your password"),
  })
  .refine((data) => data.password === data.confirm, {
    message: "Passwords do not match",
    path: ["confirm"],
  });

type ResetValues = z.infer<typeof resetSchema>;

// ---------------------------------------------------------------------------
// Status-specific error messages
// ---------------------------------------------------------------------------

type ResetErrorStatus = 400 | 410 | 429;

function StatusErrorCard({ status }: { status: ResetErrorStatus }) {
  const message =
    status === 429
      ? "Too many reset attempts. Please wait a few minutes before trying again."
      : "This reset link is invalid or has expired.";

  return (
    <AuthLayout>
      <Card className="w-full max-w-sm">
        <CardHeader className="space-y-1">
          <CardTitle asChild>
            <h1>Reset link unavailable</h1>
          </CardTitle>
          <CardDescription>{message}</CardDescription>
        </CardHeader>
        {status !== 429 ? (
          <CardContent>
            <p className="text-sm text-[var(--color-muted-foreground)]">
              <Link
                to="/forgot-password"
                className="text-[var(--color-foreground)] underline underline-offset-4"
              >
                Request a new reset link
              </Link>
            </p>
          </CardContent>
        ) : null}
      </Card>
    </AuthLayout>
  );
}

// ---------------------------------------------------------------------------
// Page component
// ---------------------------------------------------------------------------

function ResetPasswordPage() {
  const search = Route.useSearch();
  const token = search.token;
  const navigate = useNavigate();
  const resetMutation = useResetPassword();
  const [errorStatus, setErrorStatus] = useState<ResetErrorStatus | null>(null);

  // No token in URL — show missing-token error immediately (mirrors accept.tsx).
  if (!token) {
    return (
      <AuthLayout>
        <Card className="w-full max-w-sm">
          <CardHeader className="space-y-1">
            <CardTitle asChild>
              <h1>Invalid reset link</h1>
            </CardTitle>
            <CardDescription>
              This link is missing the required token. Make sure you copied the
              full link from your email.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-sm text-[var(--color-muted-foreground)]">
              <Link
                to="/forgot-password"
                className="text-[var(--color-foreground)] underline underline-offset-4"
              >
                Request a new reset link
              </Link>
            </p>
          </CardContent>
        </Card>
      </AuthLayout>
    );
  }

  // Backend returned a token-specific error status — show dedicated card.
  if (errorStatus !== null) {
    return <StatusErrorCard status={errorStatus} />;
  }

  return <ResetForm token={token} onErrorStatus={setErrorStatus} resetMutation={resetMutation} navigate={navigate} />;
}

// ---------------------------------------------------------------------------
// Form — extracted so the outer component can render error states cleanly
// without conditional hooks.
// ---------------------------------------------------------------------------

interface ResetFormProps {
  token: string;
  onErrorStatus: (status: ResetErrorStatus) => void;
  resetMutation: ReturnType<typeof useResetPassword>;
  navigate: ReturnType<typeof useNavigate>;
}

function ResetForm({ token, onErrorStatus, resetMutation, navigate }: ResetFormProps) {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<ResetValues>({
    resolver: zodResolver(resetSchema),
    defaultValues: { password: "", confirm: "" },
  });

  const onSubmit = handleSubmit(async (values) => {
    await resetMutation.mutateAsync(
      { token, password: values.password },
      {
        onSuccess: (result) => {
          if (result.status === 200) {
            toast.success("Password reset — please sign in with your new password.");
            void navigate({ to: "/login" });
            return;
          }
          // 400 / 410 / 429 — non-exceptional but need different UI.
          onErrorStatus(result.status);
        },
        onError: () => {
          // Unexpected transport/server error — stay on the form; mutation
          // state surfaces the message below.
        },
      },
    );
  });

  const serverError = resetMutation.isError
    ? (resetMutation.error?.message ?? "Something went wrong. Please try again.")
    : null;

  return (
    <AuthLayout>
      <Card className="w-full max-w-sm">
        <CardHeader className="space-y-1">
          <CardTitle asChild>
            <h1>Reset password</h1>
          </CardTitle>
          <CardDescription>
            Choose a new password for your account. Minimum 12 characters.
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

            <div className="space-y-2">
              <Label htmlFor="rp-password">New password</Label>
              <Input
                id="rp-password"
                type="password"
                autoComplete="new-password"
                aria-invalid={errors.password ? true : undefined}
                aria-describedby={errors.password ? "rp-password-error" : undefined}
                {...register("password")}
              />
              {errors.password ? (
                <p
                  id="rp-password-error"
                  role="alert"
                  className="flex items-center gap-1.5 text-sm text-[var(--color-destructive)]"
                >
                  <AlertCircle aria-hidden="true" className="size-3.5 shrink-0" />
                  {errors.password.message}
                </p>
              ) : null}
            </div>

            <div className="space-y-2">
              <Label htmlFor="rp-confirm">Confirm new password</Label>
              <Input
                id="rp-confirm"
                type="password"
                autoComplete="new-password"
                aria-invalid={errors.confirm ? true : undefined}
                aria-describedby={errors.confirm ? "rp-confirm-error" : undefined}
                {...register("confirm")}
              />
              {errors.confirm ? (
                <p
                  id="rp-confirm-error"
                  role="alert"
                  className="flex items-center gap-1.5 text-sm text-[var(--color-destructive)]"
                >
                  <AlertCircle aria-hidden="true" className="size-3.5 shrink-0" />
                  {errors.confirm.message}
                </p>
              ) : null}
            </div>

            <Button
              type="submit"
              className="w-full"
              disabled={isSubmitting || resetMutation.isPending}
            >
              Set new password
            </Button>
          </form>
        </CardContent>
      </Card>
    </AuthLayout>
  );
}
