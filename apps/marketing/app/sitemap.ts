import type { MetadataRoute } from "next";
import { SITE_CONFIG } from "@/lib/site";
import { FEATURE_SLUGS } from "@/lib/content/features";
import { SOLUTION_SLUGS } from "@/lib/content/solutions";
import { getAllPosts } from "@/lib/content/blog";
import { getAllGuides } from "@/lib/content/guides";

const base = SITE_CONFIG.baseUrl;

export default function sitemap(): MetadataRoute.Sitemap {
  const now = new Date();

  const coreRoutes: MetadataRoute.Sitemap = [
    { url: base, lastModified: now, changeFrequency: "weekly", priority: 1.0 },
    { url: `${base}/features/`, lastModified: now, changeFrequency: "weekly", priority: 0.9 },
    { url: `${base}/solutions/`, lastModified: now, changeFrequency: "weekly", priority: 0.9 },
    { url: `${base}/pricing/`, lastModified: now, changeFrequency: "monthly", priority: 0.8 },
    { url: `${base}/about/`, lastModified: now, changeFrequency: "monthly", priority: 0.6 },
    { url: `${base}/changelog/`, lastModified: now, changeFrequency: "weekly", priority: 0.7 },
    { url: `${base}/resources/`, lastModified: now, changeFrequency: "monthly", priority: 0.7 },
    { url: `${base}/contact/`, lastModified: now, changeFrequency: "monthly", priority: 0.6 },
    { url: `${base}/docs`, lastModified: now, changeFrequency: "monthly", priority: 0.7 },
    { url: `${base}/legal/`, lastModified: now, changeFrequency: "yearly", priority: 0.3 },
    {
      url: `${base}/legal/security-policy/`,
      lastModified: now,
      changeFrequency: "monthly",
      priority: 0.5,
    },
    // Blog index + cluster pages
    { url: `${base}/guides/`, lastModified: now, changeFrequency: "monthly", priority: 0.75 },
    { url: `${base}/blog/`, lastModified: now, changeFrequency: "weekly", priority: 0.75 },
    {
      url: `${base}/blog/wordpress-security/`,
      lastModified: now,
      changeFrequency: "weekly",
      priority: 0.7,
    },
    {
      url: `${base}/blog/wordpress-performance/`,
      lastModified: now,
      changeFrequency: "weekly",
      priority: 0.7,
    },
    {
      url: `${base}/blog/wordpress-backups/`,
      lastModified: now,
      changeFrequency: "weekly",
      priority: 0.7,
    },
    {
      url: `${base}/blog/agency-operations/`,
      lastModified: now,
      changeFrequency: "weekly",
      priority: 0.7,
    },
  ];

  const featureRoutes: MetadataRoute.Sitemap = FEATURE_SLUGS.map((slug) => ({
    url: `${base}/features/${slug}/`,
    lastModified: now,
    changeFrequency: "monthly" as const,
    priority: slug === "media-optimizer" ? 0.85 : 0.8,
  }));

  const solutionRoutes: MetadataRoute.Sitemap = SOLUTION_SLUGS.map((slug) => ({
    url: `${base}/solutions/${slug}/`,
    lastModified: now,
    changeFrequency: "monthly" as const,
    priority: 0.8,
  }));

  // Blog post routes (from MDX frontmatter)
  let blogPostRoutes: MetadataRoute.Sitemap = [];
  try {
    const posts = getAllPosts();
    blogPostRoutes = posts.map((post) => {
      const { frontmatter: fm } = post;
      return {
        url: `${base}/blog/${fm.category}/${fm.slug}/`,
        lastModified: new Date(fm.date),
        changeFrequency: "monthly" as const,
        priority: 0.65,
      };
    });
  } catch {
    // content/ directory not available during static analysis; skip gracefully.
  }

  // Guide routes
  let guideRoutes: MetadataRoute.Sitemap = [];
  try {
    const guides = getAllGuides();
    guideRoutes = guides.map((guide) => ({
      url: `${base}/guides/${guide.frontmatter.slug}/`,
      lastModified: new Date(guide.frontmatter.date),
      changeFrequency: "monthly" as const,
      priority: 0.75,
    }));
  } catch {
    // skip gracefully
  }

  return [
    ...coreRoutes,
    ...featureRoutes,
    ...solutionRoutes,
    ...blogPostRoutes,
    ...guideRoutes,
  ];
}
