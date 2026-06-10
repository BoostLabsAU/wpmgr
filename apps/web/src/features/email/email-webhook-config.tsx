import { useState, useId } from "react";
import { CheckCircle2, Copy, Check, Plus, Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import {
  AlertDialog,
  AlertDialogContent,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogAction,
  AlertDialogCancel,
} from "@/components/ui/alert-dialog";
import {
  TooltipProvider,
  TooltipRoot,
  TooltipTrigger,
  TooltipContent,
} from "@/components/ui/tooltip";
import type { SiteEmailConfig, EmailWebhookConfigResponse } from "@wpmgr/api";
import {
  usePutSiteEmailWebhookConfig,
  usePutOrgEmailWebhookConfig,
} from "./use-email";

// ---------------------------------------------------------------------------
// Provider metadata
// ---------------------------------------------------------------------------

const API_PROVIDERS = new Set(["ses", "sendgrid", "mailgun", "postmark"]);

function isApiProvider(provider: string): boolean {
  return API_PROVIDERS.has(provider);
}

// Per-provider signing key label and setup hint
const PROVIDER_META: Record<
  string,
  { keyLabel: string; hasSigningKey: boolean; setupHint: string }
> = {
  sendgrid: {
    keyLabel: "Event Webhook verification key",
    hasSigningKey: true,
    setupHint:
      "In your SendGrid dashboard: Settings > Mail Settings > Event Webhook. Paste the URL above and copy the Signed Event Webhook Verification Key into the field below.",
  },
  mailgun: {
    keyLabel: "HTTP webhook signing key",
    hasSigningKey: true,
    setupHint:
      "In your Mailgun control panel: Sending > Webhooks. Add the URL above as a webhook endpoint. Retrieve the HTTP webhook signing key from Webhooks > Manage Webhooks.",
  },
  postmark: {
    keyLabel: "Webhook secret",
    hasSigningKey: true,
    setupHint:
      "In your Postmark account: Servers > select server > Webhooks. Create a new webhook, paste the URL above, and set a webhook secret to match the field below.",
  },
  ses: {
    keyLabel: "",
    hasSigningKey: false,
    setupHint:
      "In AWS SNS: subscribe the URL above as an HTTPS endpoint to a topic that forwards SES bounce and complaint notifications. Add the SNS TopicArn to the allowlist below so only events from trusted topics are accepted.",
  },
};

function getProviderMeta(provider: string) {
  return (
    PROVIDER_META[provider] ?? {
      keyLabel: "Webhook signing key",
      hasSigningKey: true,
      setupHint: "Paste the webhook URL above into your provider's webhook settings.",
    }
  );
}

// ---------------------------------------------------------------------------
// Copy button (standalone, for URL fields without CopyableMono styling)
// ---------------------------------------------------------------------------

function CopyButton({ value, label }: { value: string; label?: string }) {
  const [copied, setCopied] = useState(false);

  const onCopy = () => {
    if (typeof navigator === "undefined" || !navigator.clipboard) return;
    void navigator.clipboard.writeText(value).then(() => {
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    });
  };

  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      onClick={onCopy}
      aria-label={label ?? "Copy to clipboard"}
      className="shrink-0"
    >
      {copied ? (
        <>
          <Check aria-hidden="true" className="size-3.5" />
          Copied
        </>
      ) : (
        <>
          <Copy aria-hidden="true" className="size-3.5" />
          Copy
        </>
      )}
    </Button>
  );
}

// ---------------------------------------------------------------------------
// One-time token callout — shown immediately after a rotate
// ---------------------------------------------------------------------------

interface OneTimeTokenCalloutProps {
  webhookUrl: string;
  token: string;
}

function OneTimeTokenCallout({ webhookUrl, token }: OneTimeTokenCalloutProps) {
  return (
    <div
      role="status"
      aria-live="polite"
      className="rounded-lg border border-[var(--color-warning,theme(colors.amber.400))] bg-amber-50 p-4 dark:bg-amber-950/40 space-y-3"
    >
      <p className="text-sm font-semibold text-amber-900 dark:text-amber-200">
        Copy this now
      </p>
      <p className="text-xs text-amber-800 dark:text-amber-300">
        This is the only time the full token will be shown. The webhook URL
        below already contains it. Copy the URL or the token before closing.
      </p>
      <div className="space-y-2">
        <div>
          <p className="text-xs font-medium text-amber-800 dark:text-amber-300 mb-1">
            Webhook URL
          </p>
          <div className="flex items-center gap-2">
            <code className="min-w-0 flex-1 break-all rounded bg-amber-100 px-2 py-1 font-mono text-xs text-amber-900 dark:bg-amber-900 dark:text-amber-100">
              {webhookUrl}
            </code>
            <CopyButton value={webhookUrl} label="Copy webhook URL" />
          </div>
        </div>
        <div>
          <p className="text-xs font-medium text-amber-800 dark:text-amber-300 mb-1">
            Token
          </p>
          <div className="flex items-center gap-2">
            <code className="min-w-0 flex-1 break-all rounded bg-amber-100 px-2 py-1 font-mono text-xs text-amber-900 dark:bg-amber-900 dark:text-amber-100">
              {token}
            </code>
            <CopyButton value={token} label="Copy token" />
          </div>
        </div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// SES TopicArn list editor
// ---------------------------------------------------------------------------

interface SesTopicArnListProps {
  arns: string[];
  onChange: (arns: string[]) => void;
  disabled?: boolean;
}

function SesTopicArnList({ arns, onChange, disabled }: SesTopicArnListProps) {
  const [draftArn, setDraftArn] = useState("");
  const inputId = useId();

  function addArn() {
    const trimmed = draftArn.trim();
    if (!trimmed || arns.includes(trimmed)) return;
    onChange([...arns, trimmed]);
    setDraftArn("");
  }

  function removeArn(arn: string) {
    onChange(arns.filter((a) => a !== arn));
  }

  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={inputId}>SNS TopicArn allowlist</Label>
      <p className="text-xs text-[var(--color-muted-foreground)]">
        Only events delivered from these SNS topics are accepted. Leave empty
        to accept events from any topic.
      </p>

      {arns.length > 0 ? (
        <ul className="space-y-1.5" aria-label="Configured topic ARNs">
          {arns.map((arn) => (
            <li
              key={arn}
              className="flex items-center gap-2 rounded border border-[var(--color-border)] bg-[var(--color-muted)] px-3 py-1.5"
            >
              <code className="min-w-0 flex-1 truncate font-mono text-xs text-[var(--color-foreground)]">
                {arn}
              </code>
              <button
                type="button"
                aria-label={`Remove ${arn}`}
                onClick={() => removeArn(arn)}
                disabled={disabled}
                className="shrink-0 text-[var(--color-muted-foreground)] hover:text-[var(--color-destructive)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] disabled:opacity-50"
              >
                <Trash2 aria-hidden="true" className="size-3.5" />
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-xs text-[var(--color-muted-foreground)] italic">
          No ARNs configured. Events from any SNS topic will be accepted.
        </p>
      )}

      <div className="flex gap-2">
        <Input
          id={inputId}
          type="text"
          value={draftArn}
          onChange={(e) => setDraftArn(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              addArn();
            }
          }}
          placeholder="arn:aws:sns:us-east-1:123456789012:MyTopic"
          disabled={disabled}
          className="flex-1 font-mono text-xs"
          aria-label="New TopicArn"
        />
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={addArn}
          disabled={disabled || !draftArn.trim()}
          aria-label="Add TopicArn"
        >
          <Plus aria-hidden="true" className="size-3.5" />
          Add
        </Button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Signing key field — write-only, masked pattern
// ---------------------------------------------------------------------------

interface SigningKeyFieldProps {
  keyLabel: string;
  keySet: boolean;
  replacing: boolean;
  value: string;
  onChange: (v: string) => void;
  onStartReplace: () => void;
  disabled?: boolean;
}

function SigningKeyField({
  keyLabel,
  keySet,
  replacing,
  value,
  onChange,
  onStartReplace,
  disabled,
}: SigningKeyFieldProps) {
  const id = useId();

  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id}>{keyLabel}</Label>
      {keySet && !replacing ? (
        <div className="flex items-center gap-2">
          <Badge variant="success" className="gap-1">
            <CheckCircle2 aria-hidden="true" className="size-3" />
            Configured
          </Badge>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onStartReplace}
            disabled={disabled}
          >
            Replace
          </Button>
        </div>
      ) : (
        <Input
          id={id}
          type="password"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={`Enter ${keyLabel.toLowerCase()}`}
          autoComplete="new-password"
          disabled={disabled}
          aria-describedby={`${id}-help`}
        />
      )}
      <p id={`${id}-help`} className="text-xs text-[var(--color-muted-foreground)]">
        Write-only. Used to verify the authenticity of incoming webhook
        payloads from the provider.
      </p>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Rotate token confirm dialog
// ---------------------------------------------------------------------------

interface RotateConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
  isPending: boolean;
}

function RotateConfirmDialog({
  open,
  onOpenChange,
  onConfirm,
  isPending,
}: RotateConfirmDialogProps) {
  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Rotate webhook URL?</AlertDialogTitle>
          <AlertDialogDescription>
            A new route token will be generated. The current webhook URL will
            stop working immediately. You must update the URL at your provider
            before events will be received again.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={isPending} />
          <AlertDialogAction
            variant="destructive"
            onClick={onConfirm}
            disabled={isPending}
          >
            {isPending ? "Rotating..." : "Rotate URL"}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}

// ---------------------------------------------------------------------------
// Shared Webhooks card body
// ---------------------------------------------------------------------------

interface WebhooksCardBodyProps {
  provider: string;
  webhookUrl: string | undefined;
  webhookSigningKeySet: boolean;
  sesTopicArns: string[];
  isPending: boolean;
  onRotate: () => void;
  onGenerateUrl: () => void;
  onSaveSigningKey: (key: string) => void;
  onSaveSesTopicArns: (arns: string[]) => void;
  oneTimeResult: EmailWebhookConfigResponse | null;
  onDismissOneTime: () => void;
}

function WebhooksCardBody({
  provider,
  webhookUrl,
  webhookSigningKeySet,
  sesTopicArns,
  isPending,
  onRotate,
  onGenerateUrl,
  onSaveSigningKey,
  onSaveSesTopicArns,
  oneTimeResult,
  onDismissOneTime,
}: WebhooksCardBodyProps) {
  const meta = getProviderMeta(provider);
  const [rotateDialogOpen, setRotateDialogOpen] = useState(false);
  const [signingKey, setSigningKey] = useState("");
  const [replacingKey, setReplacingKey] = useState(false);
  const [localArns, setLocalArns] = useState<string[]>(sesTopicArns);
  const [arnsSaved, setArnsSaved] = useState(false);

  // Sync ARNs when the prop changes (after server invalidation)
  const arnsKey = sesTopicArns.join(",");
  const [prevArnsKey, setPrevArnsKey] = useState(arnsKey);
  if (arnsKey !== prevArnsKey) {
    setLocalArns(sesTopicArns);
    setPrevArnsKey(arnsKey);
  }

  function handleSaveSigningKey() {
    const trimmed = signingKey.trim();
    if (!trimmed) return;
    onSaveSigningKey(trimmed);
    setSigningKey("");
    setReplacingKey(false);
  }

  function handleSaveArns() {
    onSaveSesTopicArns(localArns);
    setArnsSaved(true);
    window.setTimeout(() => setArnsSaved(false), 2000);
  }

  const urlFieldId = useId();

  return (
    <div className="space-y-5">
      {/* Per-provider setup hint */}
      <p className="text-xs text-[var(--color-muted-foreground)]">
        {meta.setupHint}
      </p>

      {/* One-time token callout */}
      {oneTimeResult?.webhook_route_token ? (
        <div className="space-y-2">
          <OneTimeTokenCallout
            webhookUrl={oneTimeResult.webhook_url}
            token={oneTimeResult.webhook_route_token}
          />
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={onDismissOneTime}
            className="text-xs"
          >
            I have copied it, dismiss
          </Button>
        </div>
      ) : null}

      {/* Webhook URL */}
      <div className="flex flex-col gap-1.5">
        <Label htmlFor={urlFieldId}>Webhook URL</Label>
        {webhookUrl ? (
          <div className="space-y-2">
            <div className="flex items-center gap-2">
              <Input
                id={urlFieldId}
                type="text"
                value={webhookUrl}
                readOnly
                className="flex-1 font-mono text-xs"
                aria-label="Webhook URL (read-only)"
              />
              <CopyButton value={webhookUrl} label="Copy webhook URL" />
            </div>
            <div>
              <TooltipProvider>
                <TooltipRoot>
                  <TooltipTrigger asChild>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => setRotateDialogOpen(true)}
                      disabled={isPending}
                    >
                      Rotate URL
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent>
                    Generates a new token and immediately invalidates the
                    current URL
                  </TooltipContent>
                </TooltipRoot>
              </TooltipProvider>
            </div>
          </div>
        ) : (
          <div className="space-y-2">
            <p className="text-xs text-[var(--color-muted-foreground)]">
              No webhook URL generated yet.
            </p>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={onGenerateUrl}
              disabled={isPending}
            >
              Generate webhook URL
            </Button>
          </div>
        )}
      </div>

      {/* Signing key (not for SES) */}
      {meta.hasSigningKey ? (
        <div className="space-y-2">
          <SigningKeyField
            keyLabel={meta.keyLabel}
            keySet={webhookSigningKeySet}
            replacing={replacingKey}
            value={signingKey}
            onChange={setSigningKey}
            onStartReplace={() => setReplacingKey(true)}
            disabled={isPending}
          />
          {(replacingKey || !webhookSigningKeySet) && (
            <Button
              type="button"
              size="sm"
              onClick={handleSaveSigningKey}
              disabled={isPending || !signingKey.trim()}
            >
              {isPending ? "Saving..." : "Save signing key"}
            </Button>
          )}
        </div>
      ) : null}

      {/* SES TopicArn allowlist (SES only) */}
      {provider === "ses" ? (
        <div className="space-y-2">
          <SesTopicArnList
            arns={localArns}
            onChange={setLocalArns}
            disabled={isPending}
          />
          <Button
            type="button"
            size="sm"
            onClick={handleSaveArns}
            disabled={isPending}
          >
            {arnsSaved ? "Saved" : isPending ? "Saving..." : "Save ARN list"}
          </Button>
        </div>
      ) : null}

      {/* Rotate confirm dialog */}
      <RotateConfirmDialog
        open={rotateDialogOpen}
        onOpenChange={setRotateDialogOpen}
        onConfirm={() => {
          setRotateDialogOpen(false);
          onRotate();
        }}
        isPending={isPending}
      />
    </div>
  );
}

// ---------------------------------------------------------------------------
// Per-site Webhooks card
// ---------------------------------------------------------------------------

export interface EmailWebhookConfigCardProps {
  siteId: string;
  config: SiteEmailConfig;
}

export function EmailWebhookConfigCard({
  siteId,
  config,
}: EmailWebhookConfigCardProps) {
  const mutation = usePutSiteEmailWebhookConfig(siteId);
  // Track the one-time token result in local state so we can surface it after
  // rotate and dismiss it once the operator copies it.
  const [oneTimeResult, setOneTimeResult] =
    useState<EmailWebhookConfigResponse | null>(null);

  if (!isApiProvider(config.provider)) {
    return null;
  }

  function mutate(
    body: Parameters<typeof mutation.mutate>[0],
    callbacks?: { onSuccess?: (r: EmailWebhookConfigResponse) => void },
  ) {
    mutation.mutate(body, {
      onSuccess: (result) => {
        if (result.webhook_route_token) {
          setOneTimeResult(result);
        }
        callbacks?.onSuccess?.(result);
      },
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Webhooks</CardTitle>
        <CardDescription>
          Receive bounce and complaint events from {config.provider} directly
          into WPMgr.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <WebhooksCardBody
          provider={config.provider}
          webhookUrl={config.webhook_url ?? undefined}
          webhookSigningKeySet={config.webhook_signing_key_set ?? false}
          sesTopicArns={config.ses_topic_arns ?? []}
          isPending={mutation.isPending}
          onRotate={() => mutate({ rotate_token: true })}
          onGenerateUrl={() => mutate({ rotate_token: true })}
          onSaveSigningKey={(key) => mutate({ webhook_signing_key: key })}
          onSaveSesTopicArns={(arns) => mutate({ ses_topic_arns: arns })}
          oneTimeResult={oneTimeResult}
          onDismissOneTime={() => setOneTimeResult(null)}
        />
      </CardContent>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Org-level Webhooks card
// ---------------------------------------------------------------------------

export interface EmailOrgWebhookConfigCardProps {
  config: SiteEmailConfig;
}

export function EmailOrgWebhookConfigCard({
  config,
}: EmailOrgWebhookConfigCardProps) {
  const mutation = usePutOrgEmailWebhookConfig();
  const [oneTimeResult, setOneTimeResult] =
    useState<EmailWebhookConfigResponse | null>(null);

  if (!isApiProvider(config.provider)) {
    return null;
  }

  function mutate(
    body: Parameters<typeof mutation.mutate>[0],
    callbacks?: { onSuccess?: (r: EmailWebhookConfigResponse) => void },
  ) {
    mutation.mutate(body, {
      onSuccess: (result) => {
        if (result.webhook_route_token) {
          setOneTimeResult(result);
        }
        callbacks?.onSuccess?.(result);
      },
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Webhooks</CardTitle>
        <CardDescription>
          Receive bounce and complaint events from {config.provider} directly
          into WPMgr (org-wide default).
        </CardDescription>
      </CardHeader>
      <CardContent>
        <WebhooksCardBody
          provider={config.provider}
          webhookUrl={config.webhook_url ?? undefined}
          webhookSigningKeySet={config.webhook_signing_key_set ?? false}
          sesTopicArns={config.ses_topic_arns ?? []}
          isPending={mutation.isPending}
          onRotate={() => mutate({ rotate_token: true })}
          onGenerateUrl={() => mutate({ rotate_token: true })}
          onSaveSigningKey={(key) => mutate({ webhook_signing_key: key })}
          onSaveSesTopicArns={(arns) => mutate({ ses_topic_arns: arns })}
          oneTimeResult={oneTimeResult}
          onDismissOneTime={() => setOneTimeResult(null)}
        />
      </CardContent>
    </Card>
  );
}
