import type { TargetFormat, TargetQuality } from "./types";

export const FORMAT_OPTIONS: { value: TargetFormat; label: string; hint: string }[] = [
  { value: "avif", label: "AVIF", hint: "Smallest. Best for photos." },
  { value: "webp", label: "WebP", hint: "Broad support. Safe default." },
  {
    value: "original",
    label: "Original",
    hint: "Re-compress, keep the format.",
  },
];

export const QUALITY_OPTIONS: { value: TargetQuality; label: string; hint: string }[] =
  [
    { value: "lossy", label: "Lossy", hint: "Smaller files, near-identical." },
    { value: "lossless", label: "Lossless", hint: "Pixel-perfect, larger." },
  ];
