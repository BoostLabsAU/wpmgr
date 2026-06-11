import { useEffect, useState } from "react";

/** IntersectionObserver scrollspy that tracks which cluster section is currently
 *  in the viewport. Returns the id of the active cluster, or null if none.
 *
 *  Progressive enhancement: the chip rail works as plain anchors without this
 *  hook. The hook only adds a highlight on the active chip.
 *
 *  rootMargin "-30% 0px -60% 0px" means a section activates when its top
 *  crosses the 30% mark from the top of the viewport, and deactivates when its
 *  bottom crosses the 60% mark from the bottom. This keeps exactly one cluster
 *  active at a time during normal scroll speeds. */
export function useActiveCluster(ids: string[]): string | null {
  const [active, setActive] = useState<string | null>(null);

  useEffect(() => {
    if (ids.length === 0) return;

    const observers: IntersectionObserver[] = [];

    const handleIntersect = (id: string) => (entries: IntersectionObserverEntry[]) => {
      for (const entry of entries) {
        if (entry.isIntersecting) {
          setActive(id);
        }
      }
    };

    for (const id of ids) {
      const el = document.getElementById(id);
      if (!el) continue;
      const obs = new IntersectionObserver(handleIntersect(id), {
        rootMargin: "-30% 0px -60% 0px",
        threshold: 0,
      });
      obs.observe(el);
      observers.push(obs);
    }

    return () => {
      for (const obs of observers) obs.disconnect();
    };
  }, [ids]);

  return active;
}
