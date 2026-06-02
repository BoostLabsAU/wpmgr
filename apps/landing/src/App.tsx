import { MotionConfig } from "motion/react";
import {
  Faq,
  FeatureGrid,
  FinalCta,
  Footer,
  Hero,
  HowItWorks,
  MediaHow,
  MediaSpotlight,
  NavBar,
  OpenSource,
  Security,
  Stats,
  TrustStrip,
} from "@/sections";

export function App() {
  // reducedMotion="user" makes every motion animation honour the OS setting,
  // pairing with the global CSS reduced-motion block for full coverage.
  return (
    <MotionConfig reducedMotion="user">
      <NavBar />
      <main>
        <Hero />
        <TrustStrip />
        <FeatureGrid />
        <MediaSpotlight />
        <MediaHow />
        <HowItWorks />
        <Security />
        <OpenSource />
        <Stats />
        <Faq />
        <FinalCta />
      </main>
      <Footer />
    </MotionConfig>
  );
}
