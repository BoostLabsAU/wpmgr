import { fileURLToPath, URL } from "node:url";
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";

// Standalone marketing site for the apex domain (wpmgr.app). It is intentionally
// decoupled from apps/web: no router, no API client, fully static output that
// drops onto any static host (Cloudflare Pages, a bucket, etc.).
// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./src", import.meta.url)),
    },
  },
  server: { port: 5180 },
  preview: { port: 4173 },
});
