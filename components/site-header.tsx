export function SiteHeader() {
  const navigation = ["Categorías", "Seguimiento de pedido"];

  return (
    <header className="sticky top-0 z-10 border-b border-[#d8c9ad] bg-[#f7efdf]/95 backdrop-blur">
      <div className="mx-auto flex max-w-7xl items-center gap-3 px-4 py-4 sm:px-6 lg:px-8">
        <a
          href="#inicio"
          className="rounded-md text-2xl font-bold tracking-tight text-[#173f2a] focus:outline-none focus:ring-2 focus:ring-[#173f2a] focus:ring-offset-4 focus:ring-offset-[#f7efdf]"
        >
          Casa Viva
        </a>

        <label className="sr-only" htmlFor="site-search">
          Buscar productos
        </label>
        <div className="hidden flex-1 items-center rounded-full border border-[#cbb994] bg-[#fffaf0] px-4 py-2 text-sm text-[#52705d] md:flex">
          <span aria-hidden="true">⌕</span>
          <input
            id="site-search"
            className="ml-2 w-full bg-transparent outline-none placeholder:text-[#718572]"
            placeholder="Buscar productos para el hogar"
            type="search"
          />
        </div>

        <nav aria-label="Navegación principal" className="ml-auto hidden items-center gap-6 text-sm font-semibold text-[#31563e] lg:flex">
          {navigation.map((item) => (
            <a
              className="rounded-md focus:outline-none focus:ring-2 focus:ring-[#173f2a] focus:ring-offset-4 focus:ring-offset-[#f7efdf] hover:text-[#173f2a]"
              href={item === "Categorías" ? "#categorias" : "#seguimiento"}
              key={item}
            >
              {item}
            </a>
          ))}
        </nav>

        <a
          href="#productos"
          aria-label="Carrito visual, todavía no funcional"
          className="rounded-full border border-[#173f2a] px-3 py-2 text-sm font-semibold text-[#173f2a] focus:outline-none focus:ring-2 focus:ring-[#173f2a] focus:ring-offset-4 focus:ring-offset-[#f7efdf]"
        >
          Carrito · 0
        </a>

        <details className="relative lg:hidden">
          <summary className="cursor-pointer list-none rounded-full border border-[#cbb994] px-3 py-2 text-sm font-semibold text-[#173f2a] focus:outline-none focus:ring-2 focus:ring-[#173f2a] focus:ring-offset-4 focus:ring-offset-[#f7efdf]">
            Menú
          </summary>
          <div className="absolute right-0 mt-3 w-56 rounded-2xl border border-[#d8c9ad] bg-[#fffaf0] p-3 shadow-sm">
            <a className="block rounded-xl px-3 py-2 hover:bg-[#f0e4cf]" href="#categorias">Categorías</a>
            <a className="block rounded-xl px-3 py-2 hover:bg-[#f0e4cf]" href="#seguimiento">Seguimiento de pedido</a>
            <a className="block rounded-xl px-3 py-2 hover:bg-[#f0e4cf]" href="#productos">Productos</a>
          </div>
        </details>
      </div>
    </header>
  );
}
