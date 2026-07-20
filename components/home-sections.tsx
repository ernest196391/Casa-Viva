import { benefits, demoProducts, featuredCategories } from "@/data/demo-store";

const sectionTitle = "text-3xl font-bold tracking-tight text-[#173f2a] sm:text-4xl";

export function HeroSection() {
  return (
    <section id="inicio" className="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:px-8 lg:py-20">
      <div className="flex flex-col justify-center">
        <p className="mb-4 w-fit rounded-full border border-[#cbb994] px-4 py-2 text-sm font-semibold uppercase tracking-[0.18em] text-[#31563e]">
          Tienda para el hogar · Nuevo Vedado
        </p>
        <h1 className="max-w-3xl text-5xl font-bold leading-tight tracking-tight text-[#173f2a] sm:text-6xl lg:text-7xl">
          Un hogar moderno, práctico y con calma.
        </h1>
        <p className="mt-6 max-w-2xl text-lg leading-8 text-[#3d6147]">
          Casa Viva reúne productos seleccionados para que cada espacio se sienta más ordenado, cálido y fácil de disfrutar.
        </p>
        <div className="mt-8 flex flex-col gap-3 sm:flex-row">
          <a className="rounded-full bg-[#173f2a] px-6 py-3 text-center font-semibold text-[#fff8ea] focus:outline-none focus:ring-2 focus:ring-[#173f2a] focus:ring-offset-4 focus:ring-offset-[#f7efdf]" href="#productos">
            Explorar productos
          </a>
          <a className="rounded-full border border-[#173f2a] px-6 py-3 text-center font-semibold text-[#173f2a] focus:outline-none focus:ring-2 focus:ring-[#173f2a] focus:ring-offset-4 focus:ring-offset-[#f7efdf]" href="#categorias">
            Ver categorías
          </a>
        </div>
      </div>
      <div className="min-h-[22rem] rounded-[2rem] border border-[#d8c9ad] bg-[#fff8ea] p-5">
        <div className="flex h-full min-h-[19rem] flex-col justify-between rounded-[1.5rem] bg-[#eadcc2] p-6 text-[#173f2a]">
          <span className="text-sm font-semibold uppercase tracking-[0.2em]">Espacio para imagen futura</span>
          <div className="grid grid-cols-3 gap-3" aria-hidden="true">
            <div className="h-24 rounded-3xl bg-[#173f2a] opacity-90" />
            <div className="h-32 rounded-3xl bg-[#c7d0b4]" />
            <div className="h-20 self-end rounded-3xl bg-[#d1b889]" />
          </div>
          <p className="max-w-sm text-sm leading-6 text-[#31563e]">Área decorativa liviana, lista para fotografía propia de Casa Viva.</p>
        </div>
      </div>
    </section>
  );
}

export function CategorySection() {
  return (
    <section id="categorias" className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
      <h2 className={sectionTitle}>Categorías destacadas</h2>
      <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {featuredCategories.map((category) => (
          <a className="rounded-3xl border border-[#d8c9ad] bg-[#fff8ea] p-5 font-semibold text-[#173f2a] focus:outline-none focus:ring-2 focus:ring-[#173f2a] focus:ring-offset-4 focus:ring-offset-[#f7efdf] hover:border-[#173f2a]" href="#productos" key={category}>
            {category}
          </a>
        ))}
      </div>
    </section>
  );
}

export function ProductsSection() {
  return (
    <section id="productos" className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-semibold uppercase tracking-[0.18em] text-[#52705d]">Datos temporales</p>
          <h2 className={sectionTitle}>Productos destacados</h2>
        </div>
        <p className="max-w-xl text-sm leading-6 text-[#52705d]">Estos seis artículos son ejemplos visuales para validar el diseño; no representan inventario ni precios reales.</p>
      </div>
      <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        {demoProducts.map((product) => (
          <article className="rounded-[1.75rem] border border-[#d8c9ad] bg-[#fff8ea] p-4" key={product.name}>
            <div className={`h-40 rounded-[1.25rem] ${product.accent}`} aria-label="Bloque decorativo sin imagen externa" />
            <div className="p-2 pt-5">
              <h3 className="text-xl font-bold text-[#173f2a]">{product.name}</h3>
              <p className="mt-2 font-semibold text-[#31563e]">{product.price}</p>
              <p className="mt-1 text-sm text-[#52705d]">{product.availability}</p>
              <button className="mt-5 w-full rounded-full border border-[#173f2a] px-4 py-3 font-semibold text-[#173f2a] focus:outline-none focus:ring-2 focus:ring-[#173f2a] focus:ring-offset-4 focus:ring-offset-[#fff8ea]" type="button">
                Ver producto
              </button>
            </div>
          </article>
        ))}
      </div>
    </section>
  );
}

export function BenefitsSection() {
  return (
    <section className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
      <h2 className={sectionTitle}>Comprar con claridad</h2>
      <div className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        {benefits.map((benefit) => (
          <div className="rounded-3xl bg-[#173f2a] p-5 text-[#fff8ea]" key={benefit}>{benefit}</div>
        ))}
      </div>
    </section>
  );
}

export function TrackingSection() {
  return (
    <section id="seguimiento" className="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
      <div className="rounded-[2rem] border border-[#d8c9ad] bg-[#fff8ea] p-6 sm:p-8">
        <h2 className={sectionTitle}>Seguimiento de pedido</h2>
        <p className="mt-3 text-[#52705d]">Consulta visual preparada para una futura función de seguimiento.</p>
        <form className="mt-6 flex flex-col gap-3 sm:flex-row" aria-label="Formulario visual de seguimiento">
          <label className="sr-only" htmlFor="order-code">Código de pedido</label>
          <input id="order-code" className="min-h-12 flex-1 rounded-full border border-[#cbb994] bg-white px-5 text-[#173f2a] outline-none focus:ring-2 focus:ring-[#173f2a]" placeholder="Código de pedido" type="text" />
          <button className="rounded-full bg-[#173f2a] px-6 py-3 font-semibold text-[#fff8ea] focus:outline-none focus:ring-2 focus:ring-[#173f2a] focus:ring-offset-4 focus:ring-offset-[#fff8ea]" type="button">
            Consultar pedido
          </button>
        </form>
      </div>
    </section>
  );
}
