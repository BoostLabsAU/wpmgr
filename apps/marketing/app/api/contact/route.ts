import { NextResponse } from "next/server";

// ---------------------------------------------------------------------------
// Contact form Route Handler.
//
// Validates the payload and returns { ok: true }. The CRM integration seam
// is the `forwardToCrm` function below. When you are ready to wire a CRM:
//
//   1. Add your CRM SDK to package.json (e.g. HubSpot, Pipedrive, Salesforce).
//   2. Replace the TODO block in `forwardToCrm` with the CRM API call.
//   3. Add the required env vars (HUBSPOT_API_KEY etc) to Cloud Run / .env.
//
// Rate-limiting and DDoS protection should be enforced at the CDN / LB layer
// (Cloud CDN + Cloud Armor) rather than in this handler, since Next.js
// standalone does not have a built-in rate limiter.
// ---------------------------------------------------------------------------

type ContactPayload = {
  name: string;
  email: string;
  company?: string;
  topic: "sales" | "support" | "security" | "contributing";
  message: string;
};

type ValidationError = {
  field: string;
  message: string;
};

function validatePayload(body: unknown): { ok: true; data: ContactPayload } | { ok: false; errors: ValidationError[] } {
  if (!body || typeof body !== "object") {
    return { ok: false, errors: [{ field: "body", message: "Request body must be a JSON object." }] };
  }

  const b = body as Record<string, unknown>;
  const errors: ValidationError[] = [];

  if (!b.name || typeof b.name !== "string" || !b.name.trim()) {
    errors.push({ field: "name", message: "Name is required." });
  }

  if (!b.email || typeof b.email !== "string") {
    errors.push({ field: "email", message: "Email is required." });
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(b.email)) {
    errors.push({ field: "email", message: "Invalid email address." });
  }

  const validTopics = ["sales", "support", "security", "contributing"];
  if (!b.topic || !validTopics.includes(b.topic as string)) {
    errors.push({ field: "topic", message: "Topic must be one of: sales, support, security, contributing." });
  }

  if (!b.message || typeof b.message !== "string" || b.message.trim().length < 20) {
    errors.push({ field: "message", message: "Message must be at least 20 characters." });
  }

  if (errors.length > 0) return { ok: false, errors };

  return {
    ok: true,
    data: {
      name: (b.name as string).trim(),
      email: (b.email as string).trim().toLowerCase(),
      company: typeof b.company === "string" ? b.company.trim() : undefined,
      topic: b.topic as ContactPayload["topic"],
      message: (b.message as string).trim(),
    },
  };
}

// ---------------------------------------------------------------------------
// CRM integration seam.
// This function is the single place to add CRM forwarding. Currently it
// logs the payload (for instance log capture) and returns cleanly.
// Replace the TODO block with your CRM API call when ready.
// ---------------------------------------------------------------------------
async function forwardToCrm(payload: ContactPayload): Promise<void> {
  // TODO(crm): forward payload to your CRM here.
  // Example HubSpot integration:
  //
  //   const client = new Client({ accessToken: process.env.HUBSPOT_API_KEY });
  //   await client.crm.contacts.basicApi.create({
  //     properties: {
  //       firstname: payload.name.split(" ")[0],
  //       lastname: payload.name.split(" ").slice(1).join(" "),
  //       email: payload.email,
  //       company: payload.company ?? "",
  //       message: payload.message,
  //       hs_lead_status: "NEW",
  //     },
  //   });
  //
  // For now, log to stdout so the instance log captures it.
  console.log("[contact] submission received", {
    topic: payload.topic,
    email: payload.email,
    company: payload.company,
    // Do not log the message body to avoid PII in logs by default.
    // Re-enable if your log infrastructure is appropriately secured.
    messageLength: payload.message.length,
  });
}

export async function POST(request: Request) {
  let body: unknown;

  try {
    body = await request.json();
  } catch {
    return NextResponse.json(
      { ok: false, error: "Request body must be valid JSON." },
      { status: 400 },
    );
  }

  const result = validatePayload(body);

  if (!result.ok) {
    return NextResponse.json(
      { ok: false, errors: result.errors },
      { status: 422 },
    );
  }

  try {
    await forwardToCrm(result.data);
  } catch (err) {
    // Log the error but do not expose internal details to the client.
    console.error("[contact] CRM forwarding failed:", err);
    // Return a 500 so the form shows an error state. The submission itself
    // was valid; the failure is on the integration side.
    return NextResponse.json(
      { ok: false, error: "Failed to process your message. Please try again." },
      { status: 500 },
    );
  }

  return NextResponse.json({ ok: true }, { status: 200 });
}
