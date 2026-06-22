import type { NextConfig } from "next";
import path from "path";
import createMDX from "@next/mdx";

// remark-gfm is loaded dynamically in the mdx-components.tsx context.
// We use the Rust-based mdxRs compiler (experimental.mdxRs) which:
//   - avoids Turbopack serialization errors with remark plugin functions, and
//   - ships native GFM support by default (tables, task lists, strikethrough).
// For custom remark/rehype plugins, switch experimental.mdxRs to false and
// use the webpack path (disable Turbopack with --no-turbo in dev/build).
const withMDX = createMDX({});

const nextConfig: NextConfig = {
  output: "standalone",
  // CRITICAL monorepo gotcha: without this, the file-trace roots at
  // apps/marketing and drops hoisted workspace dependencies.
  outputFileTracingRoot: path.join(__dirname, "../../"),
  // Allow .mdx files as pages (page-extension pattern for MDX route files).
  // Content-collection MDX (blog/guides) uses gray-matter + fs loader, not
  // page-extension MDX, so standalone MDX route files stay as .tsx.
  pageExtensions: ["tsx", "ts", "mdx"],
  experimental: {
    // mdxRs: the Rust-based MDX compiler. Supports GFM natively.
    // This avoids the Turbopack "non-serializable options" error that
    // occurs when remark plugin functions are passed through the Turbopack
    // loader bridge. Compatible with Next.js 16 + Turbopack builds.
    mdxRs: true,
  },
};

export default withMDX(nextConfig);
