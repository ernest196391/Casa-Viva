"use client";

import { useMemo, useState } from "react";

type DeliveryStage =
  | "OFFERED"
  | "ACCEPTED"
  | "GOING_TO_PICKUP"
  | "PICKED_UP"
  | "ON_THE_WAY"
  | "DELIVERED";

const demoOrder = {
  store: "Todo Hogar",
  manager: "Isabella",
  managerCode: "061",
  orderId: "TH-DEMO-001",
  products: [{ quantity: 2, name: "Ventilador recargable", total: "180.00 USD" }],
  deliveryFee: "5000 CUP",
  customer: "Cliente de prueba",
  phone: "+5350000000",
  address: "Dirección de demostración, Regla, La Habana",
  zone: "Regla",
  note: "Llevar 20 USD de vuelto",
  totalUsd: "180.00 USD",
  totalCup: "5000 CUP",
};

const stageCopy: Record<DeliveryStage, { label: string; action: string | null; next: DeliveryStage | null }> = {
  OFFERED: { label: "Pedido nuevo", action: "ACEPTAR PEDIDO", next: "ACCEPTED" },
  ACCEPTED: { label: "Pedido aceptado", action: "VOY A RECOGER", next: "GOING_TO_PICKUP" },
  GOING_TO_PICKUP: { label: "Camino a recoger", action: "PEDIDO RECOGIDO", next: "PICKED_UP" },
  PICKED_UP: { label: "Pedido bajo tu custodia", action: "EN CAMINO AL CLIENTE", next: "ON_THE_WAY" },
  ON_THE_WAY: { label: "En camino al cliente", action: "ENTREGADO", next: "DELIVERED" },
  DELIVERED: { label: "Entrega completada", action: null, next: null },
};

function WhatsAppIcon() {
  return <span aria-hidden="true">WA</span>;
}

export default function MessengerPage() {
  const [stage, setStage] = useState<DeliveryStage>("OFFERED");
  const [incidentOpen, setIncidentOpen] = useState(false);
  const current = stageCopy[stage];
  const accepted = stage !== "OFFERED";

  const whatsappHref = useMemo(() => {
    const phone = demoOrder.phone.replace(/\D/g, "");
    const text = encodeURIComponent(
      `Hola, soy el mensajero de ${demoOrder.store}. Voy a llevarte el pedido ${demoOrder.orderId}. ¿Serías tan amable de enviarme tu ubicación y confirmarme si estás disponible?`,
    );
    return `https://wa.me/${phone}?text=${text}`;
  }, []);

  const mapsHref = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(demoOrder.address)}`;

  return (
    <main className="min-h-screen bg-[#f3efe5] text-[#173b2d]">
      <div className="mx-auto flex min-h-screen w-full max-w-md flex-col px-4 pb-28 pt-5">
        <header className="mb-5 flex items-center justify-between">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[#66766f]">Casa Viva</p>
            <h1 className="text-2xl font-semibold tracking-tight">Centro del mensajero</h1>
          </div>
          <span className="rounded-full bg-[#173b2d] px-3 py-1.5 text-xs font-semibold text-white">Demo v1</span>
        </header>

        <section className="mb-4 rounded-3xl bg-[#173b2d] p-5 text-white shadow-sm">
          <div className="flex items-start justify-between gap-3">
            <div>
              <p className="text-xs uppercase tracking-[0.16em] text-white/65">Entrega activa</p>
              <h2 className="mt-1 text-xl font-semibold">{current.label}</h2>
              <p className="mt-1 text-sm text-white/75">{demoOrder.orderId}</p>
            </div>
            <span className="rounded-full bg-white/12 px-3 py-1 text-xs">{demoOrder.zone}</span>
          </div>

          <div className="mt-5 grid grid-cols-2 gap-3 text-sm">
            <div className="rounded-2xl bg-white/10 p-3">
              <p className="text-white/60">Cobrar</p>
              <p className="mt-1 font-semibold">{demoOrder.totalUsd}</p>
              <p className="font-semibold">+ {demoOrder.totalCup}</p>
            </div>
            <div className="rounded-2xl bg-white/10 p-3">
              <p className="text-white/60">Vuelto</p>
              <p className="mt-1 font-semibold">20 USD</p>
              <p className="text-xs text-white/60">Confirmar antes de salir</p>
            </div>
          </div>
        </section>

        <section className="space-y-3 rounded-3xl bg-white p-5 shadow-sm">
          <div className="border-b border-black/5 pb-4">
            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-[#7a817d]">Pedido</p>
            <div className="mt-3 flex items-start justify-between gap-4">
              <div>
                <p className="font-semibold">{demoOrder.store}</p>
                <p className="text-sm text-[#66706b]">Gestor: {demoOrder.manager} · Código {demoOrder.managerCode}</p>
              </div>
              <p className="text-sm font-semibold">{demoOrder.totalUsd}</p>
            </div>
            <div className="mt-3 rounded-2xl bg-[#f7f5ef] p-3 text-sm">
              {demoOrder.products.map((product) => (
                <div key={product.name} className="flex justify-between gap-3">
                  <span>{product.quantity} × {product.name}</span>
                  <span className="font-medium">{product.total}</span>
                </div>
              ))}
              <div className="mt-2 flex justify-between border-t border-black/5 pt-2">
                <span>Mensajería</span>
                <span className="font-medium">{demoOrder.deliveryFee}</span>
              </div>
            </div>
          </div>

          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-[#7a817d]">Cliente</p>
            <p className="mt-2 font-semibold">{demoOrder.customer}</p>
            <p className="mt-1 text-sm leading-6 text-[#59645f]">{demoOrder.address}</p>
            <p className="mt-2 rounded-xl bg-[#f3efe5] px-3 py-2 text-sm font-medium">{demoOrder.note}</p>
          </div>

          {accepted && (
            <div className="grid grid-cols-3 gap-2 pt-1">
              <a href={whatsappHref} target="_blank" rel="noreferrer" className="rounded-2xl border border-[#dfe4df] px-2 py-3 text-center text-xs font-semibold">
                <span className="mx-auto mb-1 block"><WhatsAppIcon /></span>
                WhatsApp
              </a>
              <a href={`tel:${demoOrder.phone}`} className="rounded-2xl border border-[#dfe4df] px-2 py-3 text-center text-xs font-semibold">
                <span className="mx-auto mb-1 block" aria-hidden="true">TEL</span>
                Llamar
              </a>
              <a href={mapsHref} target="_blank" rel="noreferrer" className="rounded-2xl border border-[#dfe4df] px-2 py-3 text-center text-xs font-semibold">
                <span className="mx-auto mb-1 block" aria-hidden="true">MAP</span>
                Navegar
              </a>
            </div>
          )}
        </section>

        {incidentOpen && (
          <section className="mt-4 rounded-3xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
            <p className="font-semibold">Registrar incidencia</p>
            <p className="mt-1">Cliente no responde · Dirección incorrecta · Rechazo · Problema de pago · Avería/transporte · Producto dañado · Otro.</p>
            <p className="mt-2 text-xs">Prototipo visual: todavía no escribe en backend.</p>
          </section>
        )}

        <div className="fixed inset-x-0 bottom-0 z-10 border-t border-black/5 bg-[#f3efe5]/95 px-4 py-3 backdrop-blur">
          <div className="mx-auto flex w-full max-w-md gap-2">
            <button
              type="button"
              onClick={() => setIncidentOpen((value) => !value)}
              className="rounded-2xl border border-[#173b2d]/20 px-4 py-3 text-sm font-semibold"
            >
              Incidencia
            </button>
            {current.action ? (
              <button
                type="button"
                onClick={() => current.next && setStage(current.next)}
                className="flex-1 rounded-2xl bg-[#173b2d] px-4 py-3 text-sm font-bold text-white shadow-sm"
              >
                {current.action}
              </button>
            ) : (
              <div className="flex-1 rounded-2xl bg-[#dfe7e2] px-4 py-3 text-center text-sm font-bold text-[#173b2d]">
                Entrega completada
              </div>
            )}
          </div>
        </div>
      </div>
    </main>
  );
}
