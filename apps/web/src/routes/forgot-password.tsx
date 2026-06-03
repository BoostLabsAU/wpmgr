import { createFileRoute } from "@tanstack/react-router";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AlertCircle, CheckCircle2 } from "lucide-react";
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
import { useForgotPassword } from "@/features/auth/use-auth";

// Unauthenticated — NOT under _authed, no beforeLoad guard.
export const Route = createFileRoute("/forgot-password")({
  component: ForgotPasswordPage,
});

const forgotSchema = z.object({
  email: z.email("Enter a valid email address"),
});

type ForgotValues = z.infer<typeof forgotSchema>;

function ForgotPasswordPage() {
  const forgotMutation = useForgotPassword();
  const [submitted, setSubmitted] = useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<ForgotValues>({
    resolver: zodResolver(forgotSchema),
    defaultValues: { email: "" },
  });

  const onSubmit = handleSubmit(async (values) => {
    await forgotMutation.mutateAsync(values, {
      // Swallow errors — we show the neutral confirmation regardless.
      onError: () => {},
    });
    // Always show confirmation (prevents email enumeration on the UI side too).
    setSubmitted(true);
  });

  return (
    <AuthLayout>
      <Card className="w-full max-w-sm">
        {submitted ? (
          <>
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
                If an account exists for that address, we've sent a reset link.
                It may take a minute to arrive.
              </CardDescription>
            </CardHeader>
          </>
        ) : (
          <>
            <CardHeader className="space-y-1">
              <CardTitle asChild>
                <h1>Forgot password?</h1>
              </CardTitle>
              <CardDescription>
                Enter your email and we'll send a reset link if an account
                exists.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form
                onSubmit={(e) => void onSubmit(e)}
                noValidate
                className="space-y-4"
              >
                <div className="space-y-2">
                  <Label htmlFor="fp-email">Email</Label>
                  <Input
                    id="fp-email"
                    type="email"
                    autoComplete="email"
                    aria-invalid={errors.email ? true : undefined}
                    aria-describedby={errors.email ? "fp-email-error" : undefined}
                    {...register("email")}
                  />
                  {errors.email ? (
                    <p
                      id="fp-email-error"
                      role="alert"
                      className="flex items-center gap-1.5 text-sm text-[var(--color-destructive)]"
                    >
                      <AlertCircle
                        aria-hidden="true"
                        className="size-3.5 shrink-0"
                      />
                      {errors.email.message}
                    </p>
                  ) : null}
                </div>

                <Button
                  type="submit"
                  className="w-full"
                  disabled={isSubmitting || forgotMutation.isPending}
                >
                  Send reset link
                </Button>
              </form>
            </CardContent>
          </>
        )}
      </Card>
    </AuthLayout>
  );
}
