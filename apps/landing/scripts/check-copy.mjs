// Copy-compliance gate for the landing page. Fails the build if any em dash,
// en dash, or competitor product name slips into the source. Also enforces hard
// copy budgets on the FEATURES cluster/card content. Run before ship.
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = fileURLToPath(new URL("../src", import.meta.url));
const BANNED_CHARS = [
  { ch: "—", name: "em dash" },
  { ch: "–", name: "en dash" },
];
// Named competitor products that must never appear in an open-source project.
const BANNED_WORDS = [
  "ManageWP",
  "MainWP",
  "WPvivid",
  "FlyingPress",
  "InfiniteWP",
  "WP Remote",
  "WPRemote",
];

function walk(dir) {
  const out = [];
  for (const entry of readdirSync(dir)) {
    const p = join(dir, entry);
    if (statSync(p).isDirectory()) out.push(...walk(p));
    else if (/\.(tsx?|css|html|mjs)$/.test(entry)) out.push(p);
  }
  return out;
}

let failures = 0;
for (const file of walk(ROOT)) {
  const text = readFileSync(file, "utf8");
  const lines = text.split("\n");
  lines.forEach((line, i) => {
    for (const { ch, name } of BANNED_CHARS) {
      if (line.includes(ch)) {
        console.error(`${file}:${i + 1}  banned ${name}: ${line.trim().slice(0, 80)}`);
        failures++;
      }
    }
    for (const w of BANNED_WORDS) {
      if (line.toLowerCase().includes(w.toLowerCase())) {
        console.error(`${file}:${i + 1}  banned competitor name "${w}": ${line.trim().slice(0, 80)}`);
        failures++;
      }
    }
  });
}

// ---------------------------------------------------------------------------
// Platform copy-budget pass. Slices the FEATURES block from content.ts and
// asserts exact counts + field-level limits.
//
// EXPECTED counts (bump when the IA changes):
//   clusters  5   (Operate / Accelerate / Clean up / Serve clients / Protect)
//   features  20  (total cards across all clusters)
//   links     3   (Performance + RUM + Media deep-dive anchors)
//   visuals   3   (cache-trend / rum-distribution / media-compare)
// ---------------------------------------------------------------------------

const EXPECTED = { clusters: 5, features: 20, links: 3, visuals: 3 };

const VALID_VISUALS = ["cache-trend", "rum-distribution", "media-compare"];

function checkPlatformBudgets() {
  const contentPath = fileURLToPath(new URL("../src/data/content.ts", import.meta.url));
  const src = readFileSync(contentPath, "utf8");

  // Slice from the FEATURES const declaration to the next top-level export.
  const startIdx = src.indexOf("export const FEATURES");
  const afterStart = src.slice(startIdx);
  // Find the next "export const" that is NOT the FEATURES one.
  const nextExportMatch = afterStart.match(/\nexport const (?!FEATURES)/);
  const featuresBlock = nextExportMatch
    ? afterStart.slice(0, nextExportMatch.index)
    : afterStart;

  // Extract single-line double-quoted string values by field name.
  function extractField(fieldName) {
    const re = new RegExp(`${fieldName}:\\s*"([^"]*)"`, "g");
    const vals = [];
    let m;
    while ((m = re.exec(featuresBlock)) !== null) vals.push(m[1]);
    return vals;
  }

  // Extract bullets arrays: collect every quoted string that appears inside
  // a "bullets: [" block (lines between "bullets: [" and the closing "]").
  function extractBulletArrays() {
    const arrays = [];
    const bulletBlockRe = /bullets:\s*\[([^\]]*)\]/g;
    let m;
    while ((m = bulletBlockRe.exec(featuresBlock)) !== null) {
      const inner = m[1];
      const items = [];
      const itemRe = /"([^"]*)"/g;
      let im;
      while ((im = itemRe.exec(inner)) !== null) items.push(im[1]);
      arrays.push(items);
    }
    return arrays;
  }

  // Extract href values inside link: { href: "#..." } patterns.
  function extractLinkHrefs() {
    const re = /link:\s*\{\s*href:\s*"(#[^"]*)"\s*\}/g;
    const vals = [];
    let m;
    while ((m = re.exec(featuresBlock)) !== null) vals.push(m[1]);
    return vals;
  }

  // Extract visual values.
  function extractVisuals() {
    const re = /visual:\s*"([^"]*)"/g;
    const vals = [];
    let m;
    while ((m = re.exec(featuresBlock)) !== null) vals.push(m[1]);
    return vals;
  }

  // Count cluster id declarations.
  function extractClusterIds() {
    const re = /id:\s*"(platform-[^"]*)"/g;
    const vals = [];
    let m;
    while ((m = re.exec(featuresBlock)) !== null) vals.push(m[1]);
    return vals;
  }

  const names = extractField("name");
  const taglines = extractField("tagline");
  const titles = extractField("title");
  const summaries = extractField("summary");
  const bulletArrays = extractBulletArrays();
  const linkHrefs = extractLinkHrefs();
  const visuals = extractVisuals();
  const clusterIds = extractClusterIds();

  let budgetFailures = 0;

  function fail(msg) {
    console.error(`check-copy [platform-budget] ${msg}`);
    budgetFailures++;
  }

  // --- Exact-count assertions ---
  if (clusterIds.length !== EXPECTED.clusters) {
    fail(`Expected ${EXPECTED.clusters} clusters, found ${clusterIds.length}. Bump EXPECTED.clusters if intentional.`);
  }
  if (titles.length !== EXPECTED.features) {
    fail(`Expected ${EXPECTED.features} features (titles), found ${titles.length}. Bump EXPECTED.features if intentional.`);
  }
  if (linkHrefs.length !== EXPECTED.links) {
    fail(`Expected ${EXPECTED.links} feature links, found ${linkHrefs.length}. Bump EXPECTED.links if intentional.`);
  }
  if (visuals.length !== EXPECTED.visuals) {
    fail(`Expected ${EXPECTED.visuals} feature visuals, found ${visuals.length}. Bump EXPECTED.visuals if intentional.`);
  }

  // --- Per-cluster assertions ---
  if (clusterIds.length < 4 || clusterIds.length > 6) {
    fail(`Cluster count ${clusterIds.length} is outside the allowed range 4 to 6.`);
  }

  for (const name of names) {
    if (name.length > 16) fail(`cluster.name too long (${name.length}/16): "${name}"`);
  }

  for (const tagline of taglines) {
    if (tagline.length > 90) fail(`cluster.tagline too long (${tagline.length}/90): "${tagline}"`);
    const sentences = tagline.split(/[.!?]/).filter((s) => s.trim().length > 0);
    if (sentences.length !== 1) fail(`cluster.tagline must be exactly one sentence: "${tagline}"`);
  }

  // --- Per-feature assertions ---
  for (const title of titles) {
    if (title.length > 26) fail(`feature.title too long (${title.length}/26): "${title}"`);
    if (title.endsWith(".")) fail(`feature.title must not end with a period: "${title}"`);
  }

  for (const summary of summaries) {
    if (summary.length > 120) fail(`feature.summary too long (${summary.length}/120): "${summary}"`);
    const sentences = summary.split(/[.!?]/).filter((s) => s.trim().length > 0);
    if (sentences.length !== 1) fail(`feature.summary must be exactly one sentence: "${summary}"`);
  }

  for (let fi = 0; fi < bulletArrays.length; fi++) {
    const arr = bulletArrays[fi];
    const titleLabel = titles[fi] ?? `feature #${fi + 1}`;
    if (arr.length < 2 || arr.length > 4) {
      fail(`feature "${titleLabel}" has ${arr.length} bullets (allowed: 2 to 4)`);
    }
    for (const b of arr) {
      if (b.length > 64) fail(`feature "${titleLabel}" bullet too long (${b.length}/64): "${b}"`);
      if (b.endsWith(".")) fail(`feature "${titleLabel}" bullet must not end with a period: "${b}"`);
    }
  }

  // --- Link href assertions ---
  for (const href of linkHrefs) {
    if (!href.startsWith("#")) fail(`feature link href must start with "#": "${href}"`);
  }

  // --- Visual assertions ---
  for (const v of visuals) {
    if (!VALID_VISUALS.includes(v)) {
      fail(`feature visual "${v}" is not a valid VISUALS key. Allowed: ${VALID_VISUALS.join(", ")}`);
    }
  }

  if (budgetFailures > 0) {
    console.error(`\ncheck-copy [platform-budget] FAILED with ${budgetFailures} issue(s).`);
    failures += budgetFailures;
  } else {
    console.log(
      `check-copy [platform-budget] passed: ${clusterIds.length} clusters, ${titles.length} features, ${linkHrefs.length} links, ${visuals.length} visuals.`,
    );
  }
}

checkPlatformBudgets();

if (failures > 0) {
  console.error(`\ncheck-copy FAILED with ${failures} issue(s).`);
  process.exit(1);
}
console.log("check-copy passed: no em dashes, en dashes, or competitor names.");
