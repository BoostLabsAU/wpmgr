import { useState, useId } from "react";
import { Plus, Trash2, Pencil, Loader2, AlertTriangle } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogBody,
  DialogFooter,
} from "@/components/ui/dialog";
import { Skeleton } from "@/components/ui/skeleton";
import { PageError } from "@/components/feedback";
import type {
  EmailConnection,
  PutEmailConnectionRequest,
  PutEmailConfigRequest,
} from "@wpmgr/api";
import {
  useEmailConfig,
  usePutEmailConfig,
  useEmailConnections,
  usePutEmailConnection,
  useDeleteEmailConnection,
  ConnectionReferencedError,
} from "./use-email";
import { useProviders } from "./use-email";
import {
  DynamicField,
  ProviderPicker,
} from "./email-provider-config";

// ---------------------------------------------------------------------------
// Routing & Connections panel (m62 rebuild)
//
// Replaces the Phase-1 stub whose raw-JSON textarea 400'd on every save.
// Two cards:
//   1. Named Connections — list, add, edit, delete per named connection.
//      Per-connection provider form reuses the DynamicField/ProviderPicker
//      machinery from email-provider-config.tsx.
//   2. Routing — default/fallback selects (slug verbatim, 'default' = primary)
//      + per-FROM mapping rows replacing the raw JSON textarea.
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Connection slug validation (mirrors backend: ^[a-z0-9][a-z0-9_-]{0,31}$)
// ---------------------------------------------------------------------------

const CONN_KEY_RE = /^[a-z0-9][a-z0-9_-]{0,31}$/;
function validateConnKey(key: string): string {
  if (!key.trim()) return "Connection key is required.";
  if (key === "default") return "The key 'default' is reserved for the primary provider.";
  if (!CONN_KEY_RE.test(key))
    return "Key must match ^[a-z0-9][a-z0-9_-]{0,31}$ (lowercase, no spaces).";
  return "";
}

// ---------------------------------------------------------------------------
// Connection row
// ---------------------------------------------------------------------------

interface ConnectionRowProps {
  conn: EmailConnection;
  onEdit: () => void;
  onDelete: () => void;
  isDeleting: boolean;
}

function ConnectionRow({ conn, onEdit, onDelete, isDeleting }: ConnectionRowProps) {
  return (
    <div className="flex items-center gap-3 rounded-md border border-[var(--color-border)] bg-[var(--color-card)] px-3 py-2">
      <code className="min-w-[120px] font-mono text-sm font-medium text-[var(--color-foreground)]">
        {conn.connection_key}
      </code>
      <Badge variant="outline" className="text-xs">
        {conn.provider}
      </Badge>
      {conn.from_address ? (
        <span className="truncate text-xs text-[var(--color-muted-foreground)]">
          {conn.from_address}
        </span>
      ) : null}
      {conn.secret_set ? (
        <Badge variant="success" className="ml-auto shrink-0 text-xs">
          Secret set
        </Badge>
      ) : (
        <Badge variant="muted" className="ml-auto shrink-0 text-xs">
          No secret
        </Badge>
      )}
      <button
        type="button"
        aria-label={`Edit connection ${conn.connection_key}`}
        onClick={onEdit}
        className="shrink-0 rounded p-1 text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)]"
      >
        <Pencil aria-hidden="true" className="size-3.5" />
      </button>
      <button
        type="button"
        aria-label={`Delete connection ${conn.connection_key}`}
        onClick={onDelete}
        disabled={isDeleting}
        className="shrink-0 rounded p-1 text-[var(--color-muted-foreground)] hover:text-[var(--color-destructive)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] disabled:opacity-50"
      >
        {isDeleting ? (
          <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
        ) : (
          <Trash2 aria-hidden="true" className="size-3.5" />
        )}
      </button>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Connection editor dialog
// ---------------------------------------------------------------------------

interface ConnectionEditorProps {
  siteId: string;
  /** Existing connection being edited; null when adding new */
  editing: EmailConnection | null;
  open: boolean;
  onClose: () => void;
}

function ConnectionEditor({ siteId, editing, open, onClose }: ConnectionEditorProps) {
  const titleId = useId();
  const providersQuery = useProviders();
  const put = usePutEmailConnection(siteId);

  const [connKey, setConnKey] = useState(editing?.connection_key ?? "");
  const [provider, setProvider] = useState(editing?.provider ?? "");
  const [fromAddress, setFromAddress] = useState(editing?.from_address ?? "");
  const [fromName, setFromName] = useState(editing?.from_name ?? "");
  const [providerConfig, setProviderConfig] = useState<Record<string, unknown>>(
    editing?.config ?? {},
  );
  const [secretValue, setSecretValue] = useState("");
  const [replacingSecret, setReplacingSecret] = useState(!editing?.secret_set);

  // Reset state when the dialog opens with different editing target
  const [lastEditing, setLastEditing] = useState(editing);
  if (editing !== lastEditing) {
    setLastEditing(editing);
    setConnKey(editing?.connection_key ?? "");
    setProvider(editing?.provider ?? "");
    setFromAddress(editing?.from_address ?? "");
    setFromName(editing?.from_name ?? "");
    setProviderConfig(editing?.config ?? {});
    setSecretValue("");
    setReplacingSecret(!editing?.secret_set);
  }

  const keyError = connKey.trim() ? validateConnKey(connKey) : "";
  const isEditing = editing !== null;
  const providers = providersQuery.data?.providers ?? [];
  const currentSpec = providers.find((p) => p.slug === provider);

  function handleProviderChange(slug: string) {
    setProvider(slug);
    setProviderConfig({});
    setSecretValue("");
    setReplacingSecret(true);
  }

  function handleSave() {
    const finalKey = isEditing ? editing.connection_key : connKey.trim();
    if (!finalKey || !provider) return;
    if (!isEditing && keyError) return;

    const body: PutEmailConnectionRequest = {
      provider,
      from_address: fromAddress,
      from_name: fromName,
      config: providerConfig,
    };
    if (secretValue.trim() !== "") {
      body.secret = secretValue;
    }

    put.mutate(
      { connKey: finalKey, body },
      { onSuccess: onClose },
    );
  }

  return (
    <Dialog open={open} onClose={onClose}>
      <DialogContent ariaLabelledBy={titleId} className="max-w-lg">
        <DialogHeader>
          <DialogTitle id={titleId}>
            {isEditing ? `Edit connection: ${editing.connection_key}` : "Add connection"}
          </DialogTitle>
        </DialogHeader>
        <DialogBody>
          {providersQuery.isPending ? (
            <div className="space-y-3">
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
            </div>
          ) : providersQuery.isError ? (
            <p className="text-sm text-[var(--color-destructive)]">
              Could not load provider catalog.
            </p>
          ) : (
            <div className="space-y-4">
              {/* Connection key — only editable on create */}
              {!isEditing ? (
                <ConnectionKeyField
                  value={connKey}
                  onChange={setConnKey}
                  error={keyError}
                  disabled={put.isPending}
                />
              ) : null}

              {/* Provider picker */}
              <ProviderPicker
                value={provider}
                providers={providers}
                onChange={handleProviderChange}
                disabled={put.isPending}
              />

              {/* Dynamic provider fields */}
              {currentSpec && currentSpec.fields.length > 0 ? (
                <div className="space-y-4">
                  {currentSpec.fields.map((field) => (
                    <DynamicField
                      key={field.key}
                      field={field}
                      config={providerConfig}
                      secretValue={secretValue}
                      secretSet={editing?.secret_set ?? false}
                      replacingSecret={replacingSecret}
                      onConfigChange={(k, v) =>
                        setProviderConfig((prev) => ({ ...prev, [k]: v }))
                      }
                      onSecretChange={setSecretValue}
                      onStartReplaceSecret={() => setReplacingSecret(true)}
                      disabled={put.isPending}
                    />
                  ))}
                </div>
              ) : null}

              {/* Sender identity for this connection */}
              <div className="flex flex-col gap-1.5">
                <Label htmlFor={`${titleId}-from-address`}>
                  From address
                  <span className="ml-1 text-xs text-[var(--color-muted-foreground)]">
                    (overrides primary when set)
                  </span>
                </Label>
                <Input
                  id={`${titleId}-from-address`}
                  type="email"
                  value={fromAddress}
                  onChange={(e) => setFromAddress(e.target.value)}
                  placeholder="optional@example.com"
                  disabled={put.isPending}
                />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor={`${titleId}-from-name`}>From name</Label>
                <Input
                  id={`${titleId}-from-name`}
                  type="text"
                  value={fromName}
                  onChange={(e) => setFromName(e.target.value)}
                  placeholder="Optional sender name"
                  disabled={put.isPending}
                />
              </div>

              {/* Bounce limitation notice */}
              <div className="flex gap-2 rounded-md border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/10 px-3 py-2">
                <AlertTriangle
                  aria-hidden="true"
                  className="mt-0.5 size-4 shrink-0 text-[var(--color-warning)]"
                />
                <p className="text-xs text-[var(--color-foreground)]">
                  Bounce and complaint webhooks are bound to the primary provider only.
                  Bounces from this connection are counted in the daily digest but do not
                  trigger per-failure alerts. Per-connection webhook routing is a planned
                  fast-follow.
                </p>
              </div>

              {put.isError ? (
                <p
                  role="alert"
                  className="text-sm text-[var(--color-destructive)]"
                >
                  {put.error.message}
                </p>
              ) : null}
            </div>
          )}
        </DialogBody>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={onClose}
            disabled={put.isPending}
          >
            Cancel
          </Button>
          <Button
            type="button"
            onClick={handleSave}
            disabled={
              put.isPending ||
              !provider ||
              (!isEditing && (!!keyError || !connKey.trim()))
            }
          >
            {put.isPending ? (
              <>
                <Loader2 aria-hidden="true" className="mr-1.5 size-4 animate-spin" />
                Saving…
              </>
            ) : (
              "Save connection"
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------------------------------------------------------------------------
// Connection key field
// ---------------------------------------------------------------------------

interface ConnectionKeyFieldProps {
  value: string;
  onChange: (v: string) => void;
  error: string;
  disabled?: boolean;
}

function ConnectionKeyField({ value, onChange, error, disabled }: ConnectionKeyFieldProps) {
  const id = useId();
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id}>
        Connection key
        <span className="ml-1 text-[var(--color-destructive)]">*</span>
      </Label>
      <Input
        id={id}
        type="text"
        value={value}
        onChange={(e) => onChange(e.target.value.toLowerCase())}
        placeholder="e.g. ses-primary or smtp-backup"
        disabled={disabled}
        aria-invalid={error !== ""}
        aria-describedby={error ? `${id}-error` : `${id}-help`}
      />
      {error ? (
        <p
          id={`${id}-error`}
          role="alert"
          className="text-xs text-[var(--color-destructive)]"
        >
          {error}
        </p>
      ) : (
        <p id={`${id}-help`} className="text-xs text-[var(--color-muted-foreground)]">
          Lowercase slug shown in routing selects and log entries. Cannot be changed
          after creation.
        </p>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Per-FROM mapping editor row
// ---------------------------------------------------------------------------

interface MappingRowProps {
  fromAddress: string;
  connKey: string;
  connectionKeys: string[];
  onChange: (from: string, key: string) => void;
  onRemove: () => void;
  disabled?: boolean;
}

function MappingRow({
  fromAddress,
  connKey,
  connectionKeys,
  onChange,
  onRemove,
  disabled,
}: MappingRowProps) {
  const fromId = useId();
  const connId = useId();
  return (
    <div className="flex items-end gap-2">
      <div className="flex-1 flex flex-col gap-1">
        <Label htmlFor={fromId} className="text-xs">
          From address
        </Label>
        <Input
          id={fromId}
          type="email"
          value={fromAddress}
          onChange={(e) => onChange(e.target.value, connKey)}
          placeholder="alerts@example.com"
          disabled={disabled}
        />
      </div>
      <div className="w-40 flex flex-col gap-1">
        <Label htmlFor={connId} className="text-xs">
          Connection
        </Label>
        <Select
          id={connId}
          value={connKey}
          onChange={(e) => onChange(fromAddress, e.target.value)}
          disabled={disabled}
        >
          <option value="default">default (primary)</option>
          {connectionKeys.map((k) => (
            <option key={k} value={k}>
              {k}
            </option>
          ))}
        </Select>
      </div>
      <button
        type="button"
        aria-label="Remove mapping"
        onClick={onRemove}
        disabled={disabled}
        className="mb-0.5 shrink-0 rounded p-1.5 text-[var(--color-muted-foreground)] hover:text-[var(--color-destructive)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-ring)] disabled:opacity-50"
      >
        <Trash2 aria-hidden="true" className="size-4" />
      </button>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main panel
// ---------------------------------------------------------------------------

export interface EmailRoutingPanelProps {
  siteId: string;
}

export function EmailRoutingPanel({ siteId }: EmailRoutingPanelProps) {
  const configQuery = useEmailConfig(siteId);
  const connectionsQuery = useEmailConnections(siteId);
  const save = usePutEmailConfig(siteId);
  const deleteConn = useDeleteEmailConnection(siteId);

  const [editorOpen, setEditorOpen] = useState(false);
  const [editingConn, setEditingConn] = useState<EmailConnection | null>(null);
  const [deletingKey, setDeletingKey] = useState<string | null>(null);

  // Routing state
  const [defaultConnection, setDefaultConnection] = useState("default");
  const [fallbackConnection, setFallbackConnection] = useState("");
  // Per-FROM mappings stored as array of {from, key} for the editor rows
  const [mappings, setMappings] = useState<{ from: string; key: string }[]>([]);
  const [initialized, setInitialized] = useState(false);

  const serverConfig = configQuery.data;
  if (serverConfig && !initialized) {
    setDefaultConnection(serverConfig.default_connection ?? "default");
    setFallbackConnection(serverConfig.fallback_connection ?? "");
    const raw = serverConfig.mappings ?? {};
    setMappings(
      Object.entries(raw)
        .filter(([, v]) => typeof v === "string")
        .map(([from, key]) => ({ from, key: key as string })),
    );
    setInitialized(true);
  }

  const connections = connectionsQuery.data ?? [];
  const connectionKeys = connections.map((c) => c.connection_key);

  // All valid slug options for selects: 'default' (the primary config row) + named connections
  const allSlugOptions = [
    { value: "default", label: "default (primary)" },
    ...connectionKeys.map((k) => ({ value: k, label: k })),
  ];

  function buildPayload(): PutEmailConfigRequest {
    const mappingsObj: Record<string, string> = {};
    for (const { from, key } of mappings) {
      if (from.trim() && key.trim()) {
        mappingsObj[from.trim()] = key.trim();
      }
    }
    return {
      ...(defaultConnection && defaultConnection !== "default"
        ? { default_connection: defaultConnection }
        : { default_connection: "" }),
      ...(fallbackConnection.trim()
        ? { fallback_connection: fallbackConnection }
        : { fallback_connection: "" }),
      mappings: mappingsObj,
    };
  }

  function handleDeleteConnection(connKey: string) {
    setDeletingKey(connKey);
    deleteConn.mutate(connKey, {
      onSettled: () => setDeletingKey(null),
    });
  }

  function openEditor(conn: EmailConnection | null) {
    setEditingConn(conn);
    setEditorOpen(true);
  }

  function addMappingRow() {
    setMappings((prev) => [...prev, { from: "", key: "default" }]);
  }

  function updateMappingRow(idx: number, from: string, key: string) {
    setMappings((prev) =>
      prev.map((m, i) => (i === idx ? { from, key } : m)),
    );
  }

  function removeMappingRow(idx: number) {
    setMappings((prev) => prev.filter((_, i) => i !== idx));
  }

  const isPending = configQuery.isPending || connectionsQuery.isPending;
  const isError = configQuery.isError || connectionsQuery.isError;

  if (isPending) {
    return (
      <div className="space-y-3">
        <Skeleton className="h-5 w-48" />
        <Skeleton className="h-9 w-full" />
        <Skeleton className="h-9 w-full" />
        <Skeleton className="h-9 w-full" />
      </div>
    );
  }

  if (isError) {
    return (
      <PageError
        what="Could not load routing configuration."
        why={
          (configQuery.error ?? connectionsQuery.error)?.message ?? "Unknown error"
        }
        onRetry={() => {
          void configQuery.refetch();
          void connectionsQuery.refetch();
        }}
      />
    );
  }

  return (
    <div className="space-y-6">
      {/* Named connections */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between gap-2">
            <div>
              <CardTitle>Named connections</CardTitle>
              <CardDescription>
                Add secondary providers for routing. The primary provider configured on
                the Provider tab is always available as{" "}
                <code className="rounded bg-[var(--color-muted)] px-1 text-xs">default</code>.
              </CardDescription>
            </div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="shrink-0 gap-1.5"
              onClick={() => openEditor(null)}
            >
              <Plus aria-hidden="true" className="size-4" />
              Add
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          {connections.length === 0 ? (
            <p className="text-sm text-[var(--color-muted-foreground)]">
              No named connections yet. Add one to enable multi-provider routing or
              failover.
            </p>
          ) : (
            <div className="space-y-2">
              {connections.map((conn) => (
                <ConnectionRow
                  key={conn.connection_key}
                  conn={conn}
                  onEdit={() => openEditor(conn)}
                  onDelete={() => handleDeleteConnection(conn.connection_key)}
                  isDeleting={
                    deletingKey === conn.connection_key && deleteConn.isPending
                  }
                />
              ))}
            </div>
          )}
          {deleteConn.isError &&
          !(deleteConn.error instanceof ConnectionReferencedError) ? (
            <p
              role="alert"
              className="mt-2 text-sm text-[var(--color-destructive)]"
            >
              {deleteConn.error.message}
            </p>
          ) : null}
        </CardContent>
      </Card>

      {/* Routing */}
      <Card>
        <CardHeader>
          <CardTitle>Connection routing</CardTitle>
          <CardDescription>
            Select the default and fallback connections. Use per-FROM mappings to route
            specific sender addresses through a named connection.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-5">
          {/* Default connection */}
          <DefaultConnectionSelect
            value={defaultConnection}
            options={allSlugOptions}
            onChange={setDefaultConnection}
            disabled={save.isPending}
          />
          {/* Fallback connection */}
          <FallbackConnectionSelect
            value={fallbackConnection}
            options={allSlugOptions}
            onChange={setFallbackConnection}
            disabled={save.isPending}
          />

          {/* Per-FROM mappings */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-[var(--color-foreground)]">
                Per-FROM mappings
              </span>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="gap-1 text-xs"
                onClick={addMappingRow}
                disabled={save.isPending}
              >
                <Plus aria-hidden="true" className="size-3.5" />
                Add mapping
              </Button>
            </div>
            {mappings.length === 0 ? (
              <p className="text-xs text-[var(--color-muted-foreground)]">
                No mappings. Add one to route a specific From address through a named
                connection.
              </p>
            ) : (
              <div className="space-y-3">
                {mappings.map((m, idx) => (
                  <MappingRow
                    key={idx}
                    fromAddress={m.from}
                    connKey={m.key}
                    connectionKeys={connectionKeys}
                    onChange={(from, key) => updateMappingRow(idx, from, key)}
                    onRemove={() => removeMappingRow(idx)}
                    disabled={save.isPending}
                  />
                ))}
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Save routing */}
      <div className="flex items-center justify-end gap-3">
        {save.isError ? (
          <p
            role="alert"
            className="text-sm text-[var(--color-destructive)]"
          >
            {save.error.message}
          </p>
        ) : null}
        <Button
          type="button"
          disabled={save.isPending}
          onClick={() => save.mutate(buildPayload())}
        >
          {save.isPending ? (
            <>
              <Loader2 aria-hidden="true" className="mr-1.5 size-4 animate-spin" />
              Saving…
            </>
          ) : (
            "Save routing"
          )}
        </Button>
      </div>

      {/* Connection editor dialog */}
      <ConnectionEditor
        siteId={siteId}
        editing={editingConn}
        open={editorOpen}
        onClose={() => {
          setEditorOpen(false);
          setEditingConn(null);
        }}
      />
    </div>
  );
}

// ---------------------------------------------------------------------------
// Small select sub-components
// ---------------------------------------------------------------------------

interface ConnectionSelectProps {
  value: string;
  options: { value: string; label: string }[];
  onChange: (v: string) => void;
  disabled?: boolean;
}

function DefaultConnectionSelect({
  value,
  options,
  onChange,
  disabled,
}: ConnectionSelectProps) {
  const id = useId();
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id}>Default connection</Label>
      <Select
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </Select>
      <p className="text-xs text-[var(--color-muted-foreground)]">
        The connection used when no per-FROM mapping matches.
      </p>
    </div>
  );
}

function FallbackConnectionSelect({
  value,
  options,
  onChange,
  disabled,
}: ConnectionSelectProps) {
  const id = useId();
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id}>Fallback connection</Label>
      <Select
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
      >
        <option value="">None</option>
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </Select>
      <p className="text-xs text-[var(--color-muted-foreground)]">
        Used when the default connection fails. Disabled for test sends.
      </p>
    </div>
  );
}
