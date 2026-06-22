// Copy-compliance gate for the marketing site. Fails the build if any em dash,
// en dash, or competitor product name slips into the source. Covers:
//   - TypeScript/TSX content modules under lib/content/
//   - MDX files under content/ (when Phase 3 adds them)
//   - All component and page files under app/ and components/
// Run at build time via the "check-copy" and "build" scripts in package.json.
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = fileURLToPath(new URL("..", import.meta.url));

const SCAN_DIRS = [
  join(ROOT, "app"),
  join(ROOT, "components"),
  join(ROOT, "lib", "content"),
];

// Try to also scan content/ if it exists (Phase 3 MDX)
const CONTENT_DIR = join(ROOT, "content");
try {
  statSync(CONTENT_DIR);
  SCAN_DIRS.push(CONTENT_DIR);
} catch {
  // content/ not yet created; skip.
}

const BANNED_CHARS = [
  { ch: "—", name: "em dash" },
  { ch: "–", name: "en dash" },
];

// Named competitor products that must never appear in shipped files.
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
  let out = [];
  let entries;
  try {
    entries = readdirSync(dir);
  } catch {
    return out;
  }
  for (const entry of entries) {
    const p = join(dir, entry);
    let stat;
    try {
      stat = statSync(p);
    } catch {
      continue;
    }
    if (stat.isDirectory()) {
      out = out.concat(walk(p));
    } else if (/\.(tsx?|mdx?|css|html)$/.test(entry)) {
      out.push(p);
    }
  }
  return out;
}

let failures = 0;

const files = SCAN_DIRS.flatMap(walk);

for (const file of files) {
  let text;
  try {
    text = readFileSync(file, "utf8");
  } catch {
    continue;
  }
  const lines = text.split("\n");
  lines.forEach((line, i) => {
    // Skip comment-only lines about banned characters themselves (this script)
    for (const { ch, name } of BANNED_CHARS) {
      if (line.includes(ch)) {
        console.error(`${file}:${i + 1}  banned ${name}: ${line.trim().slice(0, 80)}`);
        failures++;
      }
    }
    for (const w of BANNED_WORDS) {
      if (line.toLowerCase().includes(w.toLowerCase())) {
        console.error(
          `${file}:${i + 1}  banned competitor name "${w}": ${line.trim().slice(0, 80)}`,
        );
        failures++;
      }
    }
  });
}

if (failures > 0) {
  console.error(`\ncheck-copy FAILED with ${failures} issue(s).`);
  process.exit(1);
}
console.log(
  `check-copy passed: no em dashes, en dashes, or competitor names found across ${files.length} files.`,
);
