import type { MetadataRoute } from "next";
import { SITE_CONFIG } from "@/lib/site";

export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "WPMgr - WordPress Fleet Management",
    short_name: "WPMgr",
    description: SITE_CONFIG.description,
    start_url: "/",
    display: "standalone",
    background_color: "#FFFFFF",
    theme_color: "#1791A6",
    icons: [
      { src: "/icon-192.png", sizes: "192x192", type: "image/png", purpose: "any" },
      { src: "/icon-512.png", sizes: "512x512", type: "image/png", purpose: "any" },
      { src: "/icon-maskable-512.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
    ],
  };
}
