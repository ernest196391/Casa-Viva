import {
  BenefitsSection,
  CategorySection,
  HeroSection,
  ProductsSection,
  TrackingSection,
} from "@/components/home-sections";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";

export default function Home() {
  return (
    <div className="min-h-screen bg-[#f7efdf] text-[#173f2a]">
      <SiteHeader />
      <main>
        <HeroSection />
        <CategorySection />
        <ProductsSection />
        <BenefitsSection />
        <TrackingSection />
      </main>
      <SiteFooter />
    </div>
  );
}
