// Public, stable external links for the WPMgr project.

/**
 * Public download for the WPMgr Agent plugin zip. The `releases/latest/download`
 * alias always resolves to the newest published GitHub release's asset
 * (`wpmgr-agent.zip`, attached by the release workflow), so this never needs
 * bumping per release. Self-hosters who fork can point this at their own fork's
 * releases.
 */
export const AGENT_PLUGIN_DOWNLOAD_URL =
  "https://github.com/mosamlife/wpmgr/releases/latest/download/wpmgr-agent.zip";

/** The public source repository. */
export const GITHUB_REPO_URL = "https://github.com/mosamlife/wpmgr";
