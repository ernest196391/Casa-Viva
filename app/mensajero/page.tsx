"use client";

import { useMemo, useState } from "react";
import { AlertCard, BottomNavigation, Icon, MoneyBreakdown, NexoFab, QuickAction, StatusBadge } from "./components";

type DeliveryStage = "OFFERED" | "ACCEPTED" | "GOING_TO_PICKUP" | "PICKED_UP" | "ON_THE_WAY" | "DELIVERED";

const demoOrder = {
  store: "Todo Hogar", manager: "Isabella", managerCode: "061", orderId: "TH-DEMO-001",
  products: [{ quantity: 2, name: "Ventilador recargable", total: "180.00 USD" }],
  deliveryFee: "5000 CUP", customer: "Cliente de prueba", phone: "+5350000000",
  address: "Dirección de demostración, Regla, La Habana", reference: "Punto de referencia de demostración",
  zone: "Regla · Casa Blanca", note: "Llevar 20 USD de vuelto", totalUsd: "180.00 USD", totalCup: "5000 CUP",
};

const stageCopy: Record<DeliveryStage, { label: string; action: string | null; next: DeliveryStage | null }> = {
  OFFERED: { label: "Pedido nuevo", action: "Aceptar pedido", next: "ACCEPTED" },
  ACCEPTED: { label: "Pedido aceptado", action: "Voy a recoger", next: "GOING_TO_PICKUP" },
  GOING_TO_PICKUP: { label: "Camino a recoger", action: "Pedido recogido", next: "PICKED_UP" },
  PICKED_UP: { label: "Bajo tu custodia", action: "En camino al cliente", next: "ON_THE_WAY" },
  ON_THE_WAY: { label: "En camino al cliente", action: "Marcar entregado", next: "DELIVERED" },
  DELIVERED: { label: "Entrega completada", action: null, next: null },
};

export default function MessengerPage() {
  const [stage, setStage] = useState<DeliveryStage>("OFFERED");
  const [incidentOpen, setIncidentOpen] = useState(false);
  const current = stageCopy[stage];
  const accepted = stage !== "OFFERED";
  const whatsappHref = useMemo(() => {
    const phone = demoOrder.phone.replace(/\D/g, "");
    const text = encodeURIComponent(`Hola, soy el mensajero de ${demoOrder.store}. Voy a llevarte el pedido ${demoOrder.orderId}. ¿Puedes enviarme tu ubicación y confirmar si estás disponible?`);
    return `https://wa.me/${phone}?text=${text}`;
  }, []);
  const mapsHref = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(demoOrder.address)}`;

  return (
    <main className="min-h-screen bg-[#F6F1E6] text-[#2B2420] [font-family:Arial,sans-serif]">
      <div className="mx-auto w-full max-w-md pb-52">
        <header className="flex min-h-20 items-center justify-between border-b border-[#2B2420]/8 bg-[#F6F1E6]/95 px-5 backdrop-blur">
          <div className="flex items-center gap-3">
            <span aria-hidden="true" className="grid h-10 w-10 place-items-center rounded-full bg-[#0D3B45] font-black tracking-tight text-white">CV</span>
            <div><p className="text-[11px] font-extrabold uppercase tracking-[0.2em] text-[#A35A1F]">Casa Viva</p><h1 className="text-lg font-extrabold tracking-[-0.02em]">Mensajería</h1></div>
          </div>
          <span className="rounded-full border border-[#A35A1F]/20 bg-[#FFF4E7] px-3 py-1.5 text-[11px] font-bold text-[#8A4A18]">Demo visual</span>
        </header>

        <div className="space-y-4 px-5 py-5">
          <section aria-labelledby="active-delivery-title">
            <div className="mb-4 flex items-end justify-between gap-4">
              <div><p className="text-xs font-bold uppercase tracking-[0.15em] text-[#776B62]">Entrega en curso</p><h2 id="active-delivery-title" className="mt-1 text-[28px] font-black leading-8 tracking-[-0.035em]">Siguiente parada</h2></div>
              <StatusBadge>{current.label}</StatusBadge>
            </div>

            <article className="overflow-hidden rounded-3xl bg-white shadow-[0_4px_20px_rgba(43,36,32,0.06)]">
              <div className="bg-[#0D3B45] p-5 text-white">
                <div className="flex items-start justify-between gap-4">
                  <div><p className="text-xs font-bold uppercase tracking-[0.14em] text-white/60">{demoOrder.zone}</p><p className="mt-2 text-2xl font-black">{demoOrder.customer}</p><p className="mt-1 text-sm text-white/70">Pedido {demoOrder.orderId}</p></div>
                  <span className="rounded-full bg-white/10 px-3 py-1 text-xs font-bold">1 de 1</span>
                </div>
                <div className="mt-5 flex items-center gap-2 rounded-2xl bg-white/10 p-3 text-sm text-white/85"><Icon name="map" className="h-5 w-5 shrink-0 text-[#E5B078]" /><span>{demoOrder.address}</span></div>
              </div>

              <div className="space-y-5 p-5">
                <AlertCard title="Necesita vuelto"><p>{demoOrder.note}. Confírmalo antes de salir.</p></AlertCard>
                <section aria-labelledby="money-title">
                  <h3 id="money-title" className="mb-3 text-xs font-extrabold uppercase tracking-[0.14em] text-[#776B62]">Resumen financiero</h3>
                  <div className="grid grid-cols-2 gap-3"><MoneyBreakdown label="Cobrar"><p>{demoOrder.totalUsd}</p><p>+ {demoOrder.totalCup}</p></MoneyBreakdown><MoneyBreakdown label="Vuelto" accent><p>20 USD</p><p className="text-xs font-semibold text-[#8A4A18]">Pendiente de confirmar</p></MoneyBreakdown></div>
                </section>
                <section className="border-t border-[#2B2420]/8 pt-5" aria-labelledby="package-title">
                  <div className="flex items-center justify-between"><h3 id="package-title" className="text-xs font-extrabold uppercase tracking-[0.14em] text-[#776B62]">Paquete</h3><span className="text-xs font-bold text-[#776B62]">{demoOrder.store}</span></div>
                  {demoOrder.products.map((product) => <div key={product.name} className="mt-3 flex justify-between gap-4 text-sm"><span><strong>{product.quantity}×</strong> {product.name}</span><strong className="shrink-0">{product.total}</strong></div>)}
                  <div className="mt-3 flex justify-between border-t border-dashed border-[#2B2420]/15 pt-3 text-sm"><span className="text-[#776B62]">Mensajería</span><strong>{demoOrder.deliveryFee}</strong></div>
                </section>
                <section className="border-t border-[#2B2420]/8 pt-5" aria-labelledby="delivery-title">
                  <h3 id="delivery-title" className="text-xs font-extrabold uppercase tracking-[0.14em] text-[#776B62]">Datos de entrega</h3>
                  <dl className="mt-3 space-y-3 text-sm"><div><dt className="font-bold">Referencia</dt><dd className="mt-1 leading-5 text-[#695F57]">{demoOrder.reference}</dd></div><div><dt className="font-bold">Gestión</dt><dd className="mt-1 text-[#695F57]">{demoOrder.manager} · Código {demoOrder.managerCode}</dd></div></dl>
                </section>
                {accepted && <div className="grid grid-cols-3 gap-2"><QuickAction href={whatsappHref} icon="message" label="WhatsApp" /><QuickAction href={`tel:${demoOrder.phone}`} icon="phone" label="Llamar" /><QuickAction href={mapsHref} icon="map" label="Navegar" /></div>}
              </div>
            </article>
          </section>

          {incidentOpen && <section className="rounded-2xl border border-[#A35A1F]/25 bg-white p-4 text-sm" aria-live="polite"><p className="font-extrabold">Registrar incidencia</p><p className="mt-1 leading-5 text-[#695F57]">Cliente no responde · Dirección incorrecta · Rechazo · Problema de pago · Avería · Producto dañado · Otro.</p><p className="mt-3 rounded-xl bg-[#F6F1E6] p-3 text-xs font-semibold text-[#776B62]">Solo demostración: esta pantalla no escribe en Casa Viva.</p></section>}
          <p className="px-2 text-center text-xs leading-5 text-[#776B62]">Datos anonimizados. Las acciones cambian únicamente esta demostración y no modifican pedidos reales.</p>
        </div>
      </div>

      <div className="fixed inset-x-0 bottom-0 z-20">
        <div className="mx-auto flex max-w-md justify-end px-5 pb-3"><NexoFab /></div>
        <div className="border-t border-[#2B2420]/8 bg-[#F6F1E6]/95 px-4 py-3 backdrop-blur"><div className="mx-auto flex w-full max-w-md gap-2">
          <button type="button" onClick={() => setIncidentOpen((value) => !value)} aria-expanded={incidentOpen} className="min-h-12 rounded-xl border border-[#0D3B45]/20 px-4 text-sm font-extrabold text-[#0D3B45] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#A35A1F]">Incidencia</button>
          {current.action ? <button type="button" onClick={() => current.next && setStage(current.next)} className="min-h-12 flex-1 rounded-xl bg-[#0D3B45] px-4 text-sm font-extrabold text-white shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#A35A1F]">{current.action}</button> : <div className="grid min-h-12 flex-1 place-items-center rounded-xl bg-[#E7EFE9] px-4 text-sm font-extrabold text-[#0D3B45]">Entrega completada</div>}
        </div></div>
        <BottomNavigation />
      </div>
    </main>
  );
}
