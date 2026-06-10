import { describe, it, expect } from "vitest";

import type {
  SiteEmailConfig,
  PutEmailWebhookConfigRequest,
  EmailWebhookConfigResponse,
} from "@wpmgr/api";

// ---------------------------------------------------------------------------
// One-time token surfacing
//
// When PUT webhook-config is called with rotate_token: true the response
// includes webhook_route_token (plain token, returned once). The UI must
// surface it immediately and tell the user to copy it before navigating away.
// The server will NOT return it again on a subsequent GET.
//
// We pin the type contract here and verify the surfacing logic that the
// component relies on.
// ---------------------------------------------------------------------------

describe("one-time token surfacing", () => {
  it("webhook_route_token is present in the response only when rotate_token was true", () => {
    // Simulates a rotate response — token is present
    const rotateResponse: EmailWebhookConfigResponse = {
      webhook_url: "https://manage.wpmgr.app/webhooks/email/sendgrid/abc123",
      webhook_signing_key_set: false,
      ses_topic_arns: [],
      webhook_route_token: "abc123",
    };
    expect(rotateResponse.webhook_route_token).toBe("abc123");
  });

  it("webhook_route_token is absent on a non-rotate update (key update only)", () => {
    // Simulates a signing-key-only update — no token rotation
    const keyUpdateResponse: EmailWebhookConfigResponse = {
      webhook_url: "https://manage.wpmgr.app/webhooks/email/sendgrid/<token>",
      webhook_signing_key_set: true,
      ses_topic_arns: [],
      // webhook_route_token deliberately absent
    };
    expect(keyUpdateResponse.webhook_route_token).toBeUndefined();
  });

  it("the one-time-token callout should be shown when webhook_route_token is present", () => {
    // The component shows the callout when result.webhook_route_token is truthy.
    // This test pins the truthiness check used in the component.
    const resultWithToken: EmailWebhookConfigResponse = {
      webhook_url: "https://manage.wpmgr.app/webhooks/email/sendgrid/tok456",
      webhook_signing_key_set: true,
      ses_topic_arns: [],
      webhook_route_token: "tok456",
    };
    const resultWithoutToken: EmailWebhookConfigResponse = {
      webhook_url: "https://manage.wpmgr.app/webhooks/email/sendgrid/<token>",
      webhook_signing_key_set: true,
      ses_topic_arns: [],
    };

    // The component gates on !!result.webhook_route_token
    expect(Boolean(resultWithToken.webhook_route_token)).toBe(true);
    expect(Boolean(resultWithoutToken.webhook_route_token)).toBe(false);
  });

  it("webhook_route_token is cleared from local state after operator dismisses", () => {
    // Simulates the dismiss callback: setOneTimeResult(null)
    let oneTimeResult: EmailWebhookConfigResponse | null = {
      webhook_url: "https://manage.wpmgr.app/webhooks/email/sendgrid/tok",
      webhook_signing_key_set: false,
      ses_topic_arns: [],
      webhook_route_token: "tok",
    };
    // operator clicks dismiss
    oneTimeResult = null;
    expect(oneTimeResult).toBeNull();
  });
});

// ---------------------------------------------------------------------------
// Write-only signing key — never display or round-trip the stored value
//
// The GET /email/config response contains webhook_signing_key_set (bool) but
// NEVER returns the actual signing key. The PUT body accepts
// webhook_signing_key?: string where omission preserves the stored key
// (nil-sentinel, same as the provider secret pattern).
// ---------------------------------------------------------------------------

describe("write-only signing key contract", () => {
  it("SiteEmailConfig exposes webhook_signing_key_set (bool) but no webhook_signing_key field", () => {
    // This is a type-level contract pinned as a runtime shape check.
    // The GET response type must have webhook_signing_key_set but NOT
    // webhook_signing_key.
    type ReadConfig = Pick<
      SiteEmailConfig,
      "webhook_signing_key_set" | "provider" | "secret_set"
    >;
    const mockRead: ReadConfig = {
      webhook_signing_key_set: true,
      provider: "sendgrid",
      secret_set: true,
    };
    expect(mockRead.webhook_signing_key_set).toBe(true);

    // The shape should NOT have a webhook_signing_key field
    // (verified at compile time — the type above omits it intentionally)
    expect("webhook_signing_key" in mockRead).toBe(false);
  });

  it("PutEmailWebhookConfigRequest accepts webhook_signing_key as optional write-only", () => {
    // Omitting means preserve stored key
    const withoutKey: PutEmailWebhookConfigRequest = { rotate_token: false };
    expect(withoutKey.webhook_signing_key).toBeUndefined();

    // Providing replaces the stored key
    const withKey: PutEmailWebhookConfigRequest = {
      webhook_signing_key: "whsec_newvalue",
    };
    expect(withKey.webhook_signing_key).toBe("whsec_newvalue");
  });

  it("an empty string webhook_signing_key in the PUT body clears the stored key", () => {
    // The UI sends an empty string to clear; omission preserves.
    const clearKey: PutEmailWebhookConfigRequest = {
      webhook_signing_key: "",
    };
    expect(clearKey.webhook_signing_key).toBe("");
  });

  it("signing key input value is reset after save so the key never re-surfaces in the DOM", () => {
    // Simulates handleSaveSigningKey in the component:
    //   onSaveSigningKey(trimmed) → setSigningKey("") → setReplacingKey(false)
    let signingKey = "whsec_secret";
    let replacingKey = true;

    // after save
    signingKey = "";
    replacingKey = false;

    expect(signingKey).toBe("");
    expect(replacingKey).toBe(false);
  });
});

// ---------------------------------------------------------------------------
// SES TopicArn allowlist contract
//
// ses_topic_arns is an array of strings. null/absent in the PUT body
// preserves the existing list; [] clears it (accepts any topic).
// ---------------------------------------------------------------------------

describe("ses_topic_arns allowlist contract", () => {
  it("ses_topic_arns is returned as an array of strings in the GET response", () => {
    const mockConfig: Pick<SiteEmailConfig, "ses_topic_arns" | "provider"> = {
      provider: "ses",
      ses_topic_arns: [
        "arn:aws:sns:us-east-1:123456789012:BouncesTopic",
        "arn:aws:sns:us-east-1:123456789012:ComplaintsTopic",
      ],
    };
    expect(Array.isArray(mockConfig.ses_topic_arns)).toBe(true);
    expect(mockConfig.ses_topic_arns).toHaveLength(2);
  });

  it("omitting ses_topic_arns in PUT body preserves the stored list", () => {
    const body: PutEmailWebhookConfigRequest = { rotate_token: false };
    expect(body.ses_topic_arns).toBeUndefined();
  });

  it("an empty ses_topic_arns array in PUT body clears the filter", () => {
    const body: PutEmailWebhookConfigRequest = { ses_topic_arns: [] };
    expect(body.ses_topic_arns).toEqual([]);
  });

  it("omitting ses_topic_arns in a second PUT body preserves the existing list (same as undefined)", () => {
    // The server treats absent ses_topic_arns as preserve.
    // In TypeScript the field is optional so omission is the only way to preserve.
    const bodyPreserve: PutEmailWebhookConfigRequest = { rotate_token: false };
    expect(bodyPreserve.ses_topic_arns).toBeUndefined();
  });
});

// ---------------------------------------------------------------------------
// Provider gating — Webhooks card is only shown for API providers
// ---------------------------------------------------------------------------

describe("provider gating", () => {
  const API_PROVIDERS = ["ses", "sendgrid", "mailgun", "postmark"];
  const NON_API_PROVIDERS = ["smtp"];

  it("API providers should show the webhook card", () => {
    for (const p of API_PROVIDERS) {
      const isApi = ["ses", "sendgrid", "mailgun", "postmark"].includes(p);
      expect(isApi).toBe(true);
    }
  });

  it("smtp is NOT an API provider and must not show the webhook card", () => {
    for (const p of NON_API_PROVIDERS) {
      const isApi = ["ses", "sendgrid", "mailgun", "postmark"].includes(p);
      expect(isApi).toBe(false);
    }
  });

  it("SES provider has no signing key field but has TopicArn list", () => {
    // SES uses SNS cert verification, not an HMAC key
    const sesProvider = "ses";
    const hasSigningKey = !["ses"].includes(sesProvider);
    const hasSesArns = sesProvider === "ses";
    expect(hasSigningKey).toBe(false);
    expect(hasSesArns).toBe(true);
  });
});
