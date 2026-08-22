import type { ReactNode } from "react";

type IconName = "home" | "contacts" | "package" | "route" | "more" | "phone" | "message" | "map" | "sparkles";

export function Icon({ name, className = "h-5 w-5" }: { name: IconName; className?: string }) {
  const paths: Record<IconName, ReactNode> = {
    home: <><path d="m3 10.5 9-7 9 7"/><path d="M5 9.5V21h14V9.5M9 21v-7h6v7"/></>,
    contacts: <><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></>,
    package: <><path d="m21 8-9 5-9-5 9-5 9 5Z"/><path d="m3 8 9 5 9-5v10l-9 5-9-5V8Z"/><path d="M12 13v10"/></>,
    route: <><circle cx="6" cy="19" r="3"/><circle cx="18" cy="5" r="3"/><path d="M8.5 17.5c3-1.5 1-7 4-8.5l3-1.5"/></>,
    more: <><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></>,
    phone: <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.2 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.78.62 2.63a2 2 0 0 1-.45 2.11L8 9.73a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.85.29 1.73.5 2.63.62A2 2 0 0 1 22 16.92Z"/>,
    message: <><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v8Z"/><path d="M8 9h8M8 13h5"/></>,
    map: <><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21 3 6"/><path d="M9 3v15M15 6v15"/></>,
    sparkles: <><path d="m12 3 1.25 3.75L17 8l-3.75 1.25L12 13l-1.25-3.75L7 8l3.75-1.25L12 3Z"/><path d="m19 14 .75 2.25L22 17l-2.25.75L19 20l-.75-2.25L16 17l2.25-.75L19 14ZM5 13l.75 2.25L8 16l-2.25.75L5 19l-.75-2.25L2 16l2.25-.75L5 13Z"/></>,
  };
  return <svg aria-hidden="true" className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">{paths[name]}</svg>;
}

export function StatusBadge({ children }: { children: ReactNode }) {
  return <span className="inline-flex min-h-8 items-center rounded-full bg-[#E7EFE9] px-3 text-xs font-bold text-[#0D3B45]">{children}</span>;
}

export function AlertCard({ title, children }: { title: string; children: ReactNode }) {
  return <aside className="rounded-2xl border border-[#A35A1F]/20 bg-[#FFF4E7] p-4 text-[#2B2420]" role="note"><div className="flex gap-3"><span aria-hidden="true" className="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full bg-[#A35A1F] text-sm font-bold text-white">!</span><div><p className="font-bold">{title}</p><div className="mt-1 text-sm leading-5 text-[#695A50]">{children}</div></div></div></aside>;
}

export function MoneyBreakdown({ label, children, accent = false }: { label: string; children: ReactNode; accent?: boolean }) {
  return <div className={`rounded-2xl p-4 ${accent ? "bg-[#FFF4E7]" : "bg-[#F6F1E6]"}`}><p className="text-[11px] font-bold uppercase tracking-[0.12em] text-[#776B62]">{label}</p><div className="mt-2 text-base font-extrabold leading-6 text-[#2B2420]">{children}</div></div>;
}

export function QuickAction({ href, icon, label }: { href: string; icon: IconName; label: string }) {
  const external = href.startsWith("http");
  return <a href={href} target={external ? "_blank" : undefined} rel={external ? "noreferrer" : undefined} className="flex min-h-14 items-center justify-center gap-2 rounded-xl border border-[#0D3B45]/15 bg-white px-2 text-sm font-bold text-[#0D3B45] transition hover:border-[#0D3B45]/35 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#A35A1F]"><Icon name={icon} className="h-[18px] w-[18px]" />{label}</a>;
}

const navItems: Array<{ label: string; icon: IconName; active?: boolean }> = [
  { label: "Inicio", icon: "home", active: true }, { label: "Contactos", icon: "contacts" },
  { label: "Preparar", icon: "package" }, { label: "Ruta", icon: "route" }, { label: "Más", icon: "more" },
];

export function BottomNavigation() {
  return <nav aria-label="Navegación del mensajero" className="border-t border-white/10 bg-[#0D3B45] px-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] pt-2 text-white"><ul className="mx-auto grid max-w-md grid-cols-5">{navItems.map((item) => <li key={item.label}><button type="button" disabled={!item.active} aria-current={item.active ? "page" : undefined} aria-label={item.active ? item.label : `${item.label}, próximamente`} className={`flex min-h-14 w-full flex-col items-center justify-center gap-1 rounded-xl text-[10px] font-bold ${item.active ? "bg-white/12 text-white" : "cursor-not-allowed text-white/55"}`}><Icon name={item.icon} className="h-5 w-5" />{item.label}</button></li>)}</ul></nav>;
}

export function NexoFab() {
  return <button type="button" disabled title="NEXO Copilot estará disponible en una fase posterior" className="flex min-h-12 items-center gap-2 rounded-full border border-[#0D3B45]/10 bg-white px-4 text-sm font-extrabold text-[#0D3B45] shadow-[0_8px_30px_rgba(43,36,32,0.16)] disabled:cursor-not-allowed"><span className="grid h-7 w-7 place-items-center rounded-full bg-[#0D3B45] text-white"><Icon name="sparkles" className="h-4 w-4" /></span>Pregunta a NEXO</button>;
}
