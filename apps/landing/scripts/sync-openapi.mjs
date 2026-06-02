// Sync the source OpenAPI spec + the vendored Scalar bundle into public/docs so
// the static API reference at wpmgr.app/docs always renders the CURRENT spec
// with ZERO external network at runtime (right for an AGPL self-hostable app).
// Runs before the landing build, so /docs can never ship a stale spec.
import { readFileSync, writeFileSync, mkdirSync, copyFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { parse } from "yaml";

// The exact pinned Scalar version (must match the devDependency in package.json).
const SCALAR_VERSION = "1.57.5";

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, ".."); // apps/landing
const repoRoot = join(root, "..", ".."); // monorepo root
const specYaml = join(repoRoot, "packages/openapi/openapi.yaml");
const outDir = join(root, "public/docs");
const vendorDir = join(outDir, "vendor");
const scalarSrc = join(
  root,
  "node_modules/@scalar/api-reference/dist/browser/standalone.js",
);
const scalarOut = join(vendorDir, `scalar-api-reference@${SCALAR_VERSION}.js`);

mkdirSync(vendorDir, { recursive: true });

// 1) Parse the source YAML and emit JSON (smaller + faster for the client).
const spec = parse(readFileSync(specYaml, "utf8"));
writeFileSync(join(outDir, "openapi.json"), JSON.stringify(spec));

// 2) Vendor the pinned Scalar standalone bundle (no runtime CDN dependency).
copyFileSync(scalarSrc, scalarOut);

const pathCount = spec.paths ? Object.keys(spec.paths).length : 0;
console.log(
  `docs sync: openapi.json (v${spec.info?.version}, ${pathCount} paths) + scalar@${SCALAR_VERSION}`,
);
