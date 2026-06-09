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
  PerformanceHow,
  PerformanceSpotlight,
  RumSection,
  Security,
  Stats,
  TechStack,
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
        <PerformanceSpotlight />
        <PerformanceHow />
        <RumSection />
        <MediaSpotlight />
        <MediaHow />
        <HowItWorks />
        <Security />
        <OpenSource />
        <TechStack />
        <Stats />
        <Faq />
        <FinalCta />
      </main>
      <Footer />
    </MotionConfig>
  );
}
