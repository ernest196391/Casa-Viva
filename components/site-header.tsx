export function SiteHeader() {
  return (
    <header className="sticky top-0 z-10 border-b border-[#E0E0E0] bg-white/95 backdrop-blur">
      <div className="mx-auto flex max-w-7xl items-center gap-3 px-4 py-3 sm:px-6 lg:px-8">
        <a href="#inicio" className="rounded-md text-xl font-light uppercase leading-none tracking-[0.28em] text-[#004042] focus:outline-none focus:ring-2 focus:ring-[#006068] focus:ring-offset-4">
          CASA<br className="hidden sm:block" /> VIVA
        </a>

        <label className="sr-only" htmlFor="site-search">Buscar productos</label>
        <div className="hidden flex-1 items-center rounded-full border border-[#E0E0E0] bg-[#FAFAF7] px-4 py-2 text-sm text-[#555555] md:flex">
          <span aria-hidden="true">Buscar</span>
          <input id="site-search" className="ml-2 w-full bg-transparent font-normal outline-none placeholder:text-[#555555]" placeholder="productos, categorías o soluciones" type="search" />
        </div>

        <nav aria-label="Navegación principal" className="ml-auto hidden items-center gap-5 text-sm font-light uppercase tracking-[0.18em] text-[#004042] lg:flex">
          <a className="rounded-md hover:text-[#006068] focus:outline-none focus:ring-2 focus:ring-[#006068]" href="#categorias">Categorías</a>
          <a className="rounded-md hover:text-[#006068] focus:outline-none focus:ring-2 focus:ring-[#006068]" href="#seguimiento">Seguimiento</a>
        </nav>

        <a href="#seguimiento" className="hidden rounded-full border border-[#006068] px-4 py-2 text-sm font-semibold text-[#006068] focus:outline-none focus:ring-2 focus:ring-[#006068] focus:ring-offset-4 sm:inline-flex">
          Mi pedido
        </a>

        <details className="relative lg:hidden">
          <summary className="cursor-pointer list-none rounded-full border border-[#E0E0E0] px-3 py-2 text-sm font-semibold text-[#004042] focus:outline-none focus:ring-2 focus:ring-[#006068] focus:ring-offset-4">
            Menú
          </summary>
          <div className="absolute right-0 mt-3 w-64 rounded-2xl border border-[#E0E0E0] bg-white p-3 shadow-sm">
            <a className="block rounded-xl px-3 py-2 hover:bg-[#FAFAF7]" href="#productos">Ver productos disponibles</a>
            <a className="block rounded-xl px-3 py-2 hover:bg-[#FAFAF7]" href="#categorias">Categorías</a>
            <a className="block rounded-xl px-3 py-2 hover:bg-[#FAFAF7]" href="#seguimiento">Seguimiento</a>
            <a className="block rounded-xl px-3 py-2 hover:bg-[#FAFAF7]" href="#seguimiento">Mi pedido</a>
          </div>
        </details>
      </div>
    </header>
  );
}
