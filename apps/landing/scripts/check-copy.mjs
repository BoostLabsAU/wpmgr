// Copy-compliance gate for the landing page. Fails the build if any em dash,
// en dash, or competitor product name slips into the source. Run before ship.
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

const ROOT = new URL("../src", import.meta.url).pathname;
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

if (failures > 0) {
  console.error(`\ncheck-copy FAILED with ${failures} issue(s).`);
  process.exit(1);
}
console.log("check-copy passed: no em dashes, en dashes, or competitor names.");
