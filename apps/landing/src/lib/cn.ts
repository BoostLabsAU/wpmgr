import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";

/** Merge Tailwind classes with conflict resolution. Mirrors apps/web. */
export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}
