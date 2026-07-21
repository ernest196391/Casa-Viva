import Image from "next/image";

import {
  customerProblems,
  demoProducts,
  differentiators,
  featuredCategories,
  productContent,
  steps,
} from "@/data/demo-store";

const sectionTitle = "text-3xl font-semibold tracking-tight text-[#222222] sm:text-4xl";
const eyebrow = "text-xs font-light uppercase tracking-[0.28em] text-[#006068]";
const primaryButton = "rounded-full bg-[#006068] px-6 py-3 text-center font-semibold text-white transition hover:bg-[#004042] focus:outline-none focus:ring-2 focus:ring-[#006068] focus:ring-offset-4";
const secondaryButton = "rounded-full border border-[#006068] px-6 py-3 text-center font-semibold text-[#006068] transition hover:bg-[#FAFAF7] focus:outline-none focus:ring-2 focus:ring-[#006068] focus:ring-offset-4";

export function HeroSection() {
  return (
    <section id="inicio" className="mx-auto grid max-w-7xl gap-10 px-4 py-10 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:px-8 lg:py-16">
      <div className="flex flex-col justify-center">
        <p className={eyebrow}>Tienda para el hogar · Nuevo Vedado</p>
        <h1 className="mt-5 max-w-4xl text-4xl font-semibold leading-tight tracking-tight text-[#222222] sm:text-5xl lg:text-6xl">
          Compra para tu hogar sin perder horas buscando ni comprar a ciegas.
        </h1>
        <p className="mt-6 max-w-2xl text-base leading-8 text-[#555555] sm:text-lg">
          Encuentra productos útiles con precios visibles, fotos reales e información clara. Prepara tu pedido en minutos y confírmalo directamente por WhatsApp, con recogida en Nuevo Vedado o entrega en La Habana.
        </p>
        <div className="mt-8 flex flex-col gap-3 sm:flex-row">
          <a className={primaryButton} href="#productos">Ver productos disponibles</a>
          <a className={secondaryButton} href="#seguimiento">Consultar mi pedido</a>
        </div>
        <p className="mt-6 text-sm font-light tracking-wide text-[#004042]">Fotos reales · Precios visibles · Atención directa · Pedido organizado</p>
      </div>
      <div className="relative min-h-[24rem] overflow-hidden rounded-[2rem] border border-[#E0E0E0] bg-white">
        <Image
          src="/images/casa-viva-hero.webp"
          alt="Sala moderna y acogedora de Casa Viva"
          fill
          priority
          sizes="(min-width: 1024px) 46vw, (min-width: 640px) 92vw, 100vw"
          className="object-cover"
        />
      </div>
    </section>
  );
}

export function ProblemSection() {
  return (
    <section className="bg-[#FAFAF7] py-12">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <h2 className={sectionTitle}>Comprar para el hogar en Cuba no debería convertirse en una búsqueda interminable.</h2>
        <div className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {customerProblems.map((problem) => <p className="rounded-2xl border border-[#E0E0E0] bg-white p-4 text-sm text-[#555555]" key={problem}>{problem}</p>)}
        </div>
        <p className="mt-6 font-semibold text-[#004042]">Casa Viva reúne productos, información y pedido en un solo lugar.</p>
      </div>
    </section>
  );
}

export function HowItWorksSection() {
  return (
    <section className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
      <p className={eyebrow}>Cómo funciona</p>
      <div className="mt-6 grid gap-4 md:grid-cols-4">
        {steps.map((step, index) => <div className="rounded-3xl border border-[#E0E0E0] bg-white p-5" key={step}><span className="text-sm font-semibold text-[#006068]">{index + 1}</span><p className="mt-3 font-semibold text-[#222222]">{step}</p></div>)}
      </div>
    </section>
  );
}

export function CategorySection() {
  return (
    <section id="categorias" className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
      <h2 className={sectionTitle}>Categorías</h2>
      <div className="mt-6 flex gap-3 overflow-x-auto pb-3 sm:grid sm:grid-cols-3 sm:overflow-visible lg:grid-cols-4">
        {featuredCategories.map((category) => <a className="min-w-40 rounded-2xl border border-[#E0E0E0] bg-white px-4 py-3 text-sm font-semibold text-[#222222] hover:border-[#006068] focus:outline-none focus:ring-2 focus:ring-[#006068]" href="#productos" key={category}>{category}</a>)}
      </div>
    </section>
  );
}

export function ProductsSection() {
  return (
    <section id="productos" className="bg-white py-12">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div><p className={eyebrow}>Datos de demostración</p><h2 className={sectionTitle}>Productos destacados</h2></div>
          <p className="max-w-xl text-sm leading-6 text-[#555555]">Seis ejemplos visuales para preparar el flujo de solicitud. No representan inventario ni precios reales.</p>
        </div>
        <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {demoProducts.map((product) => <article className="rounded-[1.5rem] border border-[#E0E0E0] bg-white p-4" key={product.name}>
            <div className={`flex h-40 items-end rounded-[1rem] ${product.accent} p-4`} aria-label="Bloque visual temporal sin imagen externa"><span className="text-xs font-light uppercase tracking-[0.22em] text-[#004042]">Imagen futura</span></div>
            <div className="pt-5"><h3 className="text-xl font-semibold text-[#222222]">{product.name}</h3><p className="mt-2 font-semibold text-[#006068]">{product.price}</p><p className="mt-1 text-sm text-[#28A745]">{product.availability}</p><p className="mt-3 text-sm leading-6 text-[#555555]">{product.feature}</p><button className="mt-5 w-full rounded-full bg-[#006068] px-4 py-3 font-semibold text-white" type="button">Agregar al pedido</button><a className="mt-3 block text-center text-sm font-semibold text-[#006068]" href="#contenido-producto">Ver detalles</a></div>
          </article>)}
        </div>
      </div>
    </section>
  );
}

export function DifferentiatorsSection() {
  return <InfoGrid title="Antes de comprar, sabes mejor lo que estás comprando." items={differentiators} />;
}

export function ProductContentSection() {
  return <InfoGrid id="contenido-producto" title="Cada ficha podrá ayudarte a decidir con más seguridad." items={productContent} intro="La idea es que veas el producto desde varios ángulos antes de preparar tu pedido." />;
}

function InfoGrid({ title, items, intro, id }: { title: string; items: string[]; intro?: string; id?: string }) {
  return <section id={id} className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8"><h2 className={sectionTitle}>{title}</h2>{intro ? <p className="mt-3 max-w-2xl text-[#555555]">{intro}</p> : null}<div className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{items.map((item) => <div className="rounded-2xl bg-[#FAFAF7] p-5 text-[#222222]" key={item}>{item}</div>)}</div></section>;
}

export function SocialProofSection() {
  return <section className="bg-[#FAFAF7] py-12"><div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"><h2 className={sectionTitle}>Experiencias de Casa Viva</h2><div className="mt-6 grid gap-4 md:grid-cols-4">{["Opiniones verificadas", "Fotos de clientes", "Productos más vendidos", "Pedidos entregados"].map((item) => <div className="rounded-3xl border border-dashed border-[#E5D6BD] bg-white p-5 text-[#555555]" key={item}>{item}</div>)}</div><p className="mt-6 font-semibold text-[#006068]">Próximamente: experiencias verificadas de clientes.</p></div></section>;
}

export function RiskReductionSection() {
  return <section className="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8"><div className="rounded-[2rem] bg-[#F2E9DC] p-6 sm:p-8"><h2 className={sectionTitle}>Confirma tus dudas antes de pagar.</h2><p className="mt-4 text-[#555555]">¿Tienes dudas sobre tamaño, material o compatibilidad? Consúltalas antes de confirmar tu pedido.</p><p className="mt-4 text-[#555555]"><strong className="font-semibold text-[#222222]">Postventa:</strong> Si recibes un producto con una incidencia, envíanos fotos y revisamos el caso contigo.</p></div></section>;
}

export function TrackingSection() {
  return <section id="seguimiento" className="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8"><div className="rounded-[2rem] border border-[#E0E0E0] bg-white p-6 sm:p-8"><h2 className={sectionTitle}>¿Ya hiciste tu pedido?</h2><p className="mt-3 text-[#555555]">Consulta su estado con el código o enlace de seguimiento.</p><form className="mt-6 flex flex-col gap-3 sm:flex-row" aria-label="Formulario visual de seguimiento"><label className="sr-only" htmlFor="order-code">Código o enlace de seguimiento</label><input id="order-code" className="min-h-12 flex-1 rounded-full border border-[#E0E0E0] bg-white px-5 text-[#222222] outline-none focus:ring-2 focus:ring-[#006068]" placeholder="Código o enlace de seguimiento" type="text" /><button className={primaryButton} type="button">Consultar pedido</button></form></div></section>;
}

export function ClosingSection() {
  return <section className="bg-[#004042] px-4 py-14 text-center text-white"><h2 className="mx-auto max-w-3xl text-3xl font-semibold sm:text-4xl">Resuelve lo que necesita tu hogar sin empezar otra búsqueda interminable.</h2><a className="mt-8 inline-flex rounded-full bg-white px-6 py-3 font-semibold text-[#006068]" href="#productos">Ver productos disponibles</a></section>;
}
