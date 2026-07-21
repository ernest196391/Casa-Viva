import {
  CategorySection,
  ClosingSection,
  DifferentiatorsSection,
  HeroSection,
  HowItWorksSection,
  ProblemSection,
  ProductContentSection,
  ProductsSection,
  RiskReductionSection,
  SocialProofSection,
  TrackingSection,
} from "@/components/home-sections";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";

export default function Home() {
  return (
    <div className="min-h-screen bg-white text-[#222222]">
      <SiteHeader />
      <main>
        <HeroSection />
        <ProblemSection />
        <HowItWorksSection />
        <CategorySection />
        <ProductsSection />
        <DifferentiatorsSection />
        <ProductContentSection />
        <SocialProofSection />
        <RiskReductionSection />
        <TrackingSection />
        <ClosingSection />
      </main>
      <SiteFooter />
    </div>
  );
}
