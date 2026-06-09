/**
 * esbuild IIFE bundler for the @wpmgr/tracker RUM collector.
 *
 * Produces a single minified IIFE: dist/wpmgr-rum.min.js
 * Then copies it into the agent assets directory alongside wpmgr-delay.min.js.
 *
 * Run with: node build.mjs
 */

import { build } from 'esbuild';
import { copyFileSync, mkdirSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));

const DIST = join(__dirname, 'dist');
const OUT_FILE = join(DIST, 'wpmgr-rum.min.js');
const AGENT_ASSETS = join(__dirname, '../../apps/agent/assets');

mkdirSync(DIST, { recursive: true });

await build({
  entryPoints: [join(__dirname, 'src/index.ts')],
  bundle: true,
  minify: true,
  format: 'iife',
  platform: 'browser',
  target: ['es2017', 'chrome70', 'firefox68', 'safari12'],
  outfile: OUT_FILE,
  // web-vitals ships as a bundled dependency; include it entirely.
  // No external dependencies: the whole bundle must be self-contained.
  external: [],
  define: {
    // Ensure no Node.js globals leak into the bundle.
    'process.env.NODE_ENV': '"production"',
  },
  legalComments: 'none',
});

// Copy the artifact into the agent's assets directory.
mkdirSync(AGENT_ASSETS, { recursive: true });
copyFileSync(OUT_FILE, join(AGENT_ASSETS, 'wpmgr-rum.min.js'));

console.log('Built: dist/wpmgr-rum.min.js');
console.log('Copied -> apps/agent/assets/wpmgr-rum.min.js');
