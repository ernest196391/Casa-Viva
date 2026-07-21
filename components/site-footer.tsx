export function SiteFooter() {
  return (
    <footer className="border-t border-[#E0E0E0] bg-[#FAFAF7] text-[#222222]">
      <div className="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:px-6 md:grid-cols-4 lg:px-8">
        <div>
          <p className="text-xl font-light uppercase tracking-[0.28em] text-[#004042]">CASA VIVA</p>
          <p className="mt-3 text-sm text-[#555555]">Nuevo Vedado, La Habana.</p>
        </div>
        <div className="text-sm text-[#555555]">
          <p className="font-semibold text-[#222222]">Recogida y entrega</p>
          <p className="mt-2">Recogida en Nuevo Vedado y entrega en La Habana.</p>
        </div>
        <nav aria-label="Enlaces provisionales" className="grid gap-2 text-sm text-[#004042]">
          <a className="w-fit rounded focus:outline-none focus:ring-2 focus:ring-[#006068]" href="#categorias">Categorías</a>
          <a className="w-fit rounded focus:outline-none focus:ring-2 focus:ring-[#006068]" href="#productos">Productos disponibles</a>
          <a className="w-fit rounded focus:outline-none focus:ring-2 focus:ring-[#006068]" href="#seguimiento">Consultar pedido</a>
        </nav>
        <p className="text-sm leading-6 text-[#555555]">Los datos oficiales de contacto, horarios y canales de atención se configurarán después.</p>
      </div>
    </footer>
  );
}
