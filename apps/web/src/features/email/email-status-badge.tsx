import { Badge } from "@/components/ui/badge";

// Shared status badge used in both the log table and the detail dialog.
// Maps status strings to semantic badge variants.

const STATUS_VARIANT: Record<
  string,
  "success" | "destructive" | "muted" | "outline"
> = {
  sent: "success",
  failed: "destructive",
  pending: "muted",
  bounced: "destructive",
  complained: "destructive",
};

const STATUS_LABEL: Record<string, string> = {
  sent: "Sent",
  failed: "Failed",
  pending: "Pending",
  bounced: "Bounced",
  complained: "Complained",
};

export function EmailStatusBadge({ status }: { status: string }) {
  const variant = STATUS_VARIANT[status] ?? "outline";
  const label = STATUS_LABEL[status] ?? status;
  return <Badge variant={variant}>{label}</Badge>;
}
