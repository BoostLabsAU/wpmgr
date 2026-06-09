/**
 * WPMgr RUM collector — Core Web Vitals + TTFB/FCP beacon sender.
 *
 * Reads per-site config from window.__WPMGR_RUM__ injected by the agent at
 * cache-write time, applies client-side sampling, then flushes one
 * navigator.sendBeacon per finalised metric on visibilitychange->hidden and
 * pagehide (standard flush pair; never unload/beforeunload).
 *
 * Ingest contract (must match the Go control-plane POST /rum/ingest handler):
 *   key    string  plaintext public beacon key
 *   url    string  window.location.href
 *   metric string  lowercase one of: lcp | inp | cls | ttfb | fcp
 *   value  number  integer milliseconds (CLS: Math.round(value * 1000))
 *   device string  desktop | mobile | tablet
 *   conn   string  4g | 3g | 2g | slow-2g | offline | unknown
 *
 * Transport: Blob("text/plain") keeps the request CORS-simple (no preflight).
 * Licensing note: web-vitals is Apache-2.0 (Google LLC). Bundled here under
 * the Apache-2.0 grant; the outer package remains MIT.
 */

import { onLCP, onINP, onCLS, onFCP, onTTFB, type Metric } from 'web-vitals';

/** Shape of the per-site config the PHP injector writes into window. */
interface WpmgrRumConfig {
  /** Plaintext public beacon key (derived by CP, stored only as hash server-side). */
  key: string;
  /** Full ingest endpoint URL, e.g. https://cp.example.com/rum/ingest */
  url: string;
  /** Client-side sample rate [0,1]. 1 = always send; 0 = never send. */
  rate: number;
}

declare global {
  interface Window {
    __WPMGR_RUM__?: WpmgrRumConfig;
  }
  interface Navigator {
    connection?: {
      effectiveType?: string;
    };
    /** Chromium-only User-Agent Client Hints API. */
    userAgentData?: {
      mobile?: boolean;
    };
  }
}

/** One collected metric waiting to be beaconed on flush. */
interface QueuedMetric {
  metric: string;
  value: number;
}

/** Queue of metrics collected this page load, flushed once on hide/pagehide. */
const queue: QueuedMetric[] = [];

/** Ensures the flush runs at most once per page load. */
let flushed = false;

/**
 * Derive a coarse device class from the UA data API + viewport width.
 * Returns "desktop" | "mobile" | "tablet".
 */
function deviceType(): 'desktop' | 'mobile' | 'tablet' {
  try {
    // navigator.userAgentData is Chromium-only and chromium-hinted.
    if (navigator.userAgentData?.mobile) {
      // Mobile UA hint: distinguish phone vs tablet by viewport width.
      return window.innerWidth >= 768 ? 'tablet' : 'mobile';
    }
    // Fallback: coarse UA-string check for common tablet/phone keywords.
    const ua = navigator.userAgent ?? '';
    if (/tablet|ipad|playbook|silk/i.test(ua)) {
      return 'tablet';
    }
    if (/mobile|android|iphone|ipod|blackberry|iemobile|opera mini/i.test(ua)) {
      return 'mobile';
    }
    return 'desktop';
  } catch {
    return 'desktop';
  }
}

/**
 * Derive the effective connection type from the Network Information API.
 * Returns one of: "4g" | "3g" | "2g" | "slow-2g" | "offline" | "unknown".
 */
function connType(): string {
  try {
    if (!navigator.onLine) {
      return 'offline';
    }
    const et = navigator.connection?.effectiveType;
    if (et === '4g' || et === '3g' || et === '2g' || et === 'slow-2g') {
      return et;
    }
    return 'unknown';
  } catch {
    return 'unknown';
  }
}

/**
 * Convert a metric name to the lowercase wire format the ingest endpoint
 * expects. web-vitals emits uppercase names (LCP, INP, CLS, FCP, TTFB).
 */
function metricName(name: string): string {
  return name.toLowerCase();
}

/**
 * Convert a metric value to an integer following the ingest contract:
 * - LCP/INP/FCP/TTFB: Math.round(ms) — already in milliseconds from web-vitals.
 * - CLS: Math.round(value * 1000) — stored as milli-units so the int column
 *   carries the fractional shift score without losing precision.
 */
function metricValue(name: string, value: number): number {
  if (name === 'CLS') {
    return Math.round(value * 1000);
  }
  return Math.round(value);
}

/**
 * Push a finalised metric into the queue.
 * Called once per metric type when web-vitals fires its final report.
 */
function collect(m: Metric): void {
  queue.push({
    metric: metricName(m.name),
    value: metricValue(m.name, m.value),
  });
}

/**
 * Send all queued metrics as individual beacons. Guarded by the `flushed`
 * flag so subsequent hide/pagehide events are no-ops.
 *
 * Each beacon is a separate POST so the server processes one metric per
 * request (matching the ingest contract: one metric per call).
 */
function flush(config: WpmgrRumConfig): void {
  if (flushed || queue.length === 0) {
    return;
  }
  flushed = true;

  const pageUrl = window.location.href;
  const device = deviceType();
  const conn = connType();

  for (const item of queue) {
    try {
      const body = JSON.stringify({
        key: config.key,
        url: pageUrl,
        metric: item.metric,
        value: item.value,
        device,
        conn,
      });
      // Blob with type "text/plain" keeps the request CORS-simple (no preflight).
      const blob = new Blob([body], { type: 'text/plain' });
      navigator.sendBeacon(config.url, blob);
    } catch {
      // Fire-and-forget: never throw, never block the page.
    }
  }
}

/**
 * Bootstrap the collector.
 *
 * Guards: requires sendBeacon, requires PerformanceObserver (feature-detect),
 * requires a valid config object. All failures are silent.
 */
export function init(): void {
  try {
    if (!('sendBeacon' in navigator)) {
      return;
    }
    if (typeof window.PerformanceObserver === 'undefined') {
      return;
    }

    const config = window.__WPMGR_RUM__;
    if (!config || typeof config.key !== 'string' || config.key === '') {
      return;
    }
    if (typeof config.url !== 'string' || config.url === '') {
      return;
    }

    // Client-side sampling: skip this page load if outside the sample window.
    // The server re-applies an authoritative random sample; this just saves egress.
    const rate = typeof config.rate === 'number' ? config.rate : 1;
    if (Math.random() >= rate) {
      return;
    }

    // Register all five V1 metrics. web-vitals fires each once when finalized.
    onLCP(collect);
    onINP(collect);
    onCLS(collect);
    onFCP(collect);
    onTTFB(collect);

    // Flush on the standard hide-pair. visibilitychange->hidden fires first on
    // most browsers; pagehide is the reliable cross-browser fallback and also
    // fires for bfcache navigations.
    const flushOnce = (): void => {
      flush(config);
    };

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') {
        flushOnce();
      }
    });
    window.addEventListener('pagehide', flushOnce);
  } catch {
    // Defensive top-level catch: this script must never throw.
  }
}
