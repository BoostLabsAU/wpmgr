// Pure helper functions for the connection-state-badge component.
// Kept in a separate module so the react-refresh fast-refresh boundary stays
// clean (a file exporting a component should not also export plain functions).

import type { ConnectionState } from "@/features/sites/connection-state";

/**
 * SHAPE labels duplicated here as a const so this module has no runtime
 * dependency on the component file (avoids the circular import that would
 * arise if connection-state-badge.tsx imported from this file and vice versa).
 */
const STATE_LABELS: Record<ConnectionState, string> = {
  pending_enrollment: "Awaiting agent",
  connected: "Connected",
  degraded: "Degraded",
  disconnected: "Disconnected",
  revoked: "Revoked",
  archived: "Archived",
};

/**
 * Resolve the human-visible label for the disconnected state based on the
 * disconnect reason written by the CP.
 *
 * - "agent_unreachable": the CP dialed the agent directly and got no answer
 *   (active verify). The site may be truly down.
 * - "heartbeat_timeout" or absent: the agent stopped reporting on its own
 *   schedule (passive path). The site itself may still be up.
 *
 * For all other states the label from STATE_LABELS is returned unchanged.
 */
export function resolveLabel(
  state: ConnectionState,
  disconnectedReason?: string | null,
): string {
  if (state !== "disconnected") return STATE_LABELS[state];
  if (disconnectedReason === "agent_unreachable") return "Agent unreachable";
  return "No heartbeat";
}
