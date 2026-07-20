export default function Home() {
  return (
    <main className="flex min-h-screen items-center justify-center bg-[#f4ead8] px-6 py-12 text-[#173f2a]">
      <section className="mx-auto flex w-full max-w-4xl flex-col items-center rounded-3xl border border-[#d6c7aa] bg-[#fff8ea] px-6 py-12 text-center shadow-lg sm:px-10 lg:px-16 lg:py-20">
        <span className="mb-8 rounded-full bg-[#173f2a] px-4 py-2 text-sm font-semibold uppercase tracking-[0.2em] text-[#f4ead8]">
          Versión inicial en desarrollo
        </span>
        <h1 className="text-5xl font-bold tracking-tight sm:text-6xl lg:text-7xl">
          Casa Viva
        </h1>
        <p className="mt-5 text-xl font-medium sm:text-2xl">
          Tu hogar moderno y práctico
        </p>
        <p className="mt-8 max-w-2xl text-base leading-7 text-[#2f5c43] sm:text-lg">
          Estamos construyendo una nueva experiencia para tu hogar
        </p>
      </section>
    </main>
  );
}
