import { useEffect, useState } from "react";
import { Icon } from "@/components/icon";

/** Reads the current theme. Light is the default; dark is opt-in and persisted. */
function getInitial(): "light" | "dark" {
  if (typeof document === "undefined") return "light";
  return document.documentElement.classList.contains("dark") ? "dark" : "light";
}

export function ThemeToggle() {
  const [theme, setTheme] = useState<"light" | "dark">(getInitial);

  useEffect(() => {
    const root = document.documentElement;
    root.classList.toggle("dark", theme === "dark");
    try {
      localStorage.setItem("wpmgr-landing-theme", theme);
    } catch {
      // storage may be blocked; the toggle still works for the session.
    }
  }, [theme]);

  const next = theme === "dark" ? "light" : "dark";
  return (
    <button
      type="button"
      onClick={() => setTheme(next)}
      aria-label={`Switch to ${next} mode`}
      className="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-[var(--radius)] border border-border bg-card text-muted-foreground transition-colors duration-[var(--duration-fast)] hover:bg-accent hover:text-accent-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)]"
    >
      <Icon name={theme === "dark" ? "Sun" : "Moon"} size={17} />
    </button>
  );
}
