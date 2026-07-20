export function SiteFooter() {
  return (
    <footer className="border-t border-[#d8c9ad] bg-[#173f2a] text-[#fff8ea]">
      <div className="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:px-6 md:grid-cols-3 lg:px-8">
        <div>
          <p className="text-2xl font-bold">Casa Viva</p>
          <p className="mt-2 text-sm text-[#e7d7b8]">Nuevo Vedado, La Habana.</p>
        </div>
        <nav aria-label="Enlaces provisionales" className="grid gap-2 text-sm">
          <a className="w-fit rounded focus:outline-none focus:ring-2 focus:ring-[#fff8ea]" href="#categorias">Categorías</a>
          <a className="w-fit rounded focus:outline-none focus:ring-2 focus:ring-[#fff8ea]" href="#productos">Productos destacados</a>
          <a className="w-fit rounded focus:outline-none focus:ring-2 focus:ring-[#fff8ea]" href="#seguimiento">Seguimiento</a>
        </nav>
        <p className="text-sm leading-6 text-[#e7d7b8]">La información de contacto, horarios y canales oficiales se configurará después.</p>
      </div>
    </footer>
  );
}
