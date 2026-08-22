# Casa Viva · NEXO — Blueprint Maestro de Mensajería, Operaciones y Asistente IA

**Versión:** 1.0  
**Fecha:** 22-08-2026  
**Estado:** fuente de verdad funcional para el piloto y su evolución  
**Rama inicial:** `feature/mvp-ruta-mensajero`

## 1. Objetivo

Construir un sistema operativo de última milla que convierta vales desestructurados en pedidos accionables, coordine gestoras, dependientas, mensajeros y clientes, y evolucione hacia una capa conversacional común para Casa Viva, Triciclub, Prevente, NEXO y futuros servicios.

El sistema debe convivir con WhatsApp, no exigir que el equipo abandone su forma actual de trabajo y reducir al mínimo la reescritura manual de datos.

## 2. Principios no negociables

1. Un pedido, una fuente de verdad.
2. La IA interpreta y propone; no inventa precios, stock, políticas, ubicaciones ni cobros.
3. Toda acción sensible exige permisos y, cuando corresponda, confirmación.
4. Los cambios relevantes se propagan a todas las vistas y notifican a los actores afectados.
5. Privacidad por mínimo privilegio.
6. Hostinger se usa para WordPress/PHP; no se diseña dependencia de Node.js allí.
7. Los servicios Node/Next, cuando sean necesarios, se alojan en Render u otro runtime compatible.
8. El producto no depende de VPN; las integraciones externas se resuelven en servidor.
9. El MVP debe funcionar con mala conexión y dispositivos modestos.
10. No se reconstruye una capacidad canónica de Casa Viva si ya existe.
11. La arquitectura debe poder crecer hacia Triciclub, Prevente y NEXO sin rehacerse.

## 3. Actores y permisos

### Administrador / Operador

Puede configurar tarifas, corregir pedidos, administrar roles, revisar incidencias, aprobar excepciones y consultar la operación global. Toda acción administrativa relevante debe quedar auditada.

### Gestora

Puede subir vales, confirmar su interpretación, editar sus pedidos antes de carga, actualizar teléfonos y referencias, consultar/calcular mensajería, ver contacto/estado/entrega de sus pedidos y solicitar cambios. Solo ve sus pedidos.

Después de que un pedido haya sido cargado por el mensajero, un cambio relevante no puede aplicarse silenciosamente: debe actualizar la fuente de verdad, generar historial y notificar al mensajero mediante push/alerta visible.

### Dependienta

Ve preparación, proveedor, ID, productos, cantidades, referencia operativa, vuelto necesario y enlace original. Marca `PREPARADO`, `VERIFICADO` y `CARGADO`. No necesita ver comisiones ni información financiera interna ajena a la preparación.

### Mensajero

Ve pedidos asignados, contactos, ubicaciones, productos, cobros necesarios, alertas, ruta y cierre. Puede llamar, abrir WhatsApp/mapa, ordenar paradas y registrar resultado de entrega/cobro. No ve datos administrativos innecesarios.

### Cliente

Ve solo su pedido, estado, ETA y posición aproximada cuando proceda. No necesita instalar aplicación. No se muestra GPS exacto permanente ni datos de otros clientes.

### Asistente IA

Interpreta vales, consulta conocimiento, propone acciones, responde preguntas, calcula usando reglas configuradas y crea borradores de pedido. Si no sabe, pide confirmación o deriva a humano.

## 4. Entrada multiformato de vales

Cualquier rol autorizado puede introducir un vale mediante:

- texto pegado desde WhatsApp;
- captura de pantalla;
- fotografía;
- PDF;
- URL del pedido;
- formulario manual;
- futura recepción directa desde WhatsApp/API.

El sistema conserva el vale original como evidencia.

### Flujo de ingestión

1. Usuario carga o pega el vale.
2. IA extrae proveedor/origen, ID, cliente, teléfonos, dirección, zona, referencia, productos, cantidades, importes, mensajería, forma de pago, vuelto, notas, horario y gestora.
3. El motor valida campos obligatorios, incoherencias de cobro, formato y conflictos de horario.
4. Si faltan datos, pregunta exactamente qué falta.
5. Muestra una ficha corta: **“He entendido esto”**.
6. El usuario confirma o corrige.
7. Se crea el pedido en la fuente de verdad y se adjunta el vale original.
8. El pedido entra en prellamada, preparación, planificación de ruta y seguimiento.

La creación no debe ser completamente automática cuando haya ambigüedad. En casos completos, la confirmación debe poder hacerse en pocos segundos.

## 5. Modelo de pedido operativo

### Identidad

- ID interno;
- ID externo;
- proveedor/origen;
- enlace original;
- evidencia original;
- actor creador.

### Cliente

- nombre;
- teléfono principal;
- teléfonos alternativos;
- WhatsApp;
- dirección;
- referencia;
- coordenadas.

### Producto

- líneas de producto;
- cantidades;
- importe;
- moneda;
- peso/volumen opcional.

### Mensajería

- tarifa total;
- importe a cobrar al cliente;
- importe asumido por gestor/negocio;
- moneda;
- regla de tarifa aplicada.

### Cobros

- producto esperado;
- mensajería esperada;
- vuelto requerido;
- forma de pago;
- importe real recibido.

### Operación

- gestora;
- dependienta;
- mensajero;
- proveedor/punto de recogida;
- franja horaria;
- prioridad.

### Contacto

- no llamado;
- mensaje enviado;
- confirmó;
- no responde;
- ubicación recibida;
- reprogramó;
- canceló.

### Ruta

- orden propuesto;
- orden final;
- ETA;
- estado de parada;
- incidencia.

### Auditoría

- historial de cambios;
- actor/rol;
- fecha/hora;
- motivo;
- valor anterior/nuevo.

## 6. Estados por dimensiones

No todo se modela como un único estado.

### Logística

`CREATED → CONFIRMED → PREPARING → READY → ASSIGNED → PICKED_UP → ON_ROUTE → DELIVERED → RECONCILED → COMPLETED`

### Contacto

`NO_LLAMADO → MENSAJE_ENVIADO → CONFIRMO / NO_RESPONDE / REPROGRAMO / CANCELO`

### Preparación

`PENDIENTE → PREPARADO → VERIFICADO → CARGADO`

### Incidencias

Dimensión separada: dirección, cliente, pago, producto, transporte u otra.

### Cobro

`ESPERADO → RECIBIDO_PARCIAL/COMPLETO → DIFERENCIA → CONCILIADO`

## 7. Prellamada y WhatsApp operativo

Pantalla `Contactos` con:

- nombre;
- pedido;
- productos/cantidad;
- zona;
- teléfono principal y alternativos;
- botones `WHATSAPP`, `LLAMAR`, `CONFIRMO`, `NO RESPONDE`, `REPROGRAMAR`, `UBICACION RECIBIDA`.

Los mensajes se generan automáticamente según proveedor y pedido. Deben incluir nombre, productos/cantidad, aviso de salida, solicitud de ubicación, disponibilidad y aviso de llamada.

La ubicación recibida se guarda en el pedido y se reutiliza en la ruta. Debe registrarse quién contactó al cliente y cuándo.

## 8. Preparación y recogida

Los pedidos se agrupan por proveedor/punto de recogida.

El sistema genera un resumen por tienda con:

- IDs;
- productos y cantidades;
- unidades totales;
- gestor;
- alertas;
- vuelto;
- enlace original.

La dependienta marca `PREPARADO → VERIFICADO → CARGADO`.

Debe existir una acción **“Compartir resumen por WhatsApp”** para la dependienta.

Todo cambio relevante posterior a `CARGADO` genera actualización sincronizada y notificación al mensajero.

## 9. Ruta inteligente

Prioridad acordada:

1. restricciones y prioridad del cliente;
2. ventanas horarias y condiciones especiales;
3. clientes confirmados;
4. lógica geográfica;
5. distancia/tiempo/eficiencia.

El sistema propone una ruta, pero el mensajero tiene la última palabra.

### Flujo

1. excluir/revisar pedidos no confirmados o reprogramados;
2. respetar franjas horarias;
3. agrupar por lógica geográfica y recogidas;
4. estimar orden eficiente;
5. presentar propuesta;
6. permitir reordenación manual;
7. recalcular ETA y seguimiento cuando cambie el orden;
8. registrar la ruta realmente ejecutada para aprendizaje futuro.

### Evolución

- MVP: orden manual asistido + deep links a mapas + coordenadas guardadas;
- siguiente fase: matriz de distancias/tiempos;
- futuro: capa geográfica propia sobre cartografía abierta con zonas, piqueras, proveedores, tarifas, incidencias e históricos.

## 10. Mapas y conectividad

La aplicación usa una capa de mapas intercambiable:

- Google Maps;
- OpenStreetMap;
- CubaMapa;
- futuro proveedor propio.

Primero se priorizan deep links a aplicaciones de mapas instaladas antes de construir navegación propia.

### Offline

La jornada debe poder conservar localmente:

- teléfonos;
- direcciones;
- coordenadas;
- productos;
- cobros;
- alertas;
- notas;
- orden de paradas.

Las acciones realizadas sin conexión se encolan y sincronizan al recuperar conectividad.

La aplicación no debe requerir VPN. Si OpenAI, Gemini u otra API no es accesible desde Cuba, el servidor intermedio realiza la llamada.

## 11. Seguimiento del cliente

Sin instalar aplicación: cada pedido recibe un link privado.

Debe mostrar:

- estado;
- ETA aproximado;
- número aproximado de entregas por delante cuando sea útil;
- posición aproximada solo cuando el mensajero esté próximo;
- cambios relevantes de ruta/incidencia.

Nunca GPS exacto permanente ni información de otros pedidos.

## 12. Dinero, pagos y conciliación

El modelo acepta múltiples monedas y medios de pago desde el inicio.

### Monedas

- CUP;
- USD;
- EUR;
- extensible a otras.

### Medios

- efectivo;
- transferencia MN;
- Clásica;
- Zelle;
- PayPal;
- cripto/USDT;
- otros configurables.

Siempre separar:

- valor de productos;
- mensajería total;
- mensajería que paga el cliente;
- parte asumida por gestora/negocio;
- vuelto.

El mensajero confirma el importe realmente recibido por moneda/medio. Toda diferencia genera incidencia y debe conciliarse antes del cierre.

## 13. Tarifas y cotizador

Debe existir una vista pública de tarifas y un cotizador `origen → destino`.

Modelo híbrido:

1. si existe tarifa oficial: usarla;
2. si no existe: sugerir según distancia/zona/carga;
3. si hay duda o excepción: solicitar cotización.

Solo administradores autorizados pueden modificar tarifas.

Toda tarifa tiene vigencia y auditoría. Una cotización aprobada puede convertirse en tarifa oficial.

La IA consulta el tarifario; nunca inventa precios.

## 14. Asistente IA y portal conversacional

Se adopta un cerebro/orquestador común con identidades por negocio:

- Casa Viva;
- Mensajería;
- Triciclub;
- Prevente;
- NEXO;
- futuros servicios.

La persona puede preguntar en lenguaje natural. El sistema detecta intención y cambia de contexto.

### Router de modelos

Se usará un gateway que pueda decidir entre Gemini y OpenAI según:

- coste;
- disponibilidad;
- calidad;
- tipo de tarea.

La arquitectura no se casa con un único proveedor.

### Base de conocimiento

Separada del modelo:

- tarifas;
- FAQ;
- políticas;
- horarios;
- productos;
- servicios;
- enlaces;
- reglas.

Debe existir un panel para editar esta información sin programar.

Si la IA no conoce una respuesta verificable, pide confirmación o deriva a humano. Debe existir siempre la opción **“Hablar con una persona”**.

La IA puede ejecutar acciones con permisos y confirmación cuando corresponda: interpretar vales, crear borradores de pedido, consultar tarifas, proponer actualizaciones, etc.

## 15. WhatsApp oficial

La automatización debe basarse en WhatsApp Business Platform/API o proveedor oficial compatible. No se construye el negocio sobre automatizaciones no oficiales con riesgo de bloqueo.

WhatsApp es canal de entrada, no base de datos.

La automatización debe:

- clasificar intención;
- contestar preguntas simples;
- dirigir a cotizador/portal cuando convenga;
- derivar a persona;
- respetar las reglas de plantillas y ventanas de conversación de la plataforma.

## 16. Notificaciones

Eventos que justifican aviso inmediato:

- nuevo pedido asignado;
- cliente cambió dirección/ubicación;
- cliente canceló/reprogramó;
- pedido preparado;
- pedido cargado;
- cambio relevante después de carga;
- mensajero aceptó;
- incidencia;
- entrega completada;
- diferencia de cobro.

Regla: notificar solo eventos accionables para evitar ruido.

## 17. Arquitectura técnica objetivo

### Capa de entrada

WhatsApp, vale, foto, PDF, URL, formulario y futuras APIs.

### Capa de ingesta IA

Extracción, clasificación, detección de faltantes, validación y ficha de confirmación.

### Dominio

Pedidos, tarifas, clientes, proveedores, rutas, eventos, pagos e incidencias.

### Operación

Gestora, dependienta, mensajero y administrador.

### Cliente

Seguimiento, cotizador y portal conversacional.

### AI Gateway

Router Gemini/OpenAI, herramientas y políticas.

### Mapas

Adaptadores Google/OSM/CubaMapa/futuro mapa propio.

### Infraestructura

- WordPress/PHP en Hostinger para el piloto y capacidades compatibles;
- Node/Next en Render u otro runtime compatible cuando se necesite;
- secretos solo en servidor;
- PWA/cache/offline y push según fase.

## 18. Seguridad, privacidad y auditoría

- Gestora: solo sus pedidos.
- Mensajero: solo pedidos asignados y datos necesarios de jornada.
- Dependienta: preparación/logística sin comisiones ni finanzas innecesarias.
- Cliente: solo su pedido.
- Administrador: acceso global auditado.
- Links privados difíciles de adivinar y revocables.
- Acciones sensibles autenticadas y protegidas contra CSRF.
- Datos personales fuera de repositorios públicos.
- Secretos de IA fuera del cliente y de GitHub.
- Separar conocimiento público de datos privados.
- Todo cambio relevante registra actor, rol, timestamp, antes/después y motivo.

## 19. Alcance MVP de primera entrega

### B0 — Baseline y seguridad

Plugin MVP aislado, sin tocar el core, datos privados fuera de GitHub, deploy con backup/rollback.

### B1 — Ingesta de vales

Texto/manual primero; parser IA → confirmación → pedido. Imagen/PDF/URL en iteración inmediata siguiente.

### B2 — Contactos

Prellamada, WhatsApp, teléfonos alternativos, ubicación y registro de contacto.

### B3 — Preparación

Resumen por proveedor, unidades, vuelto, preparado/verificado/cargado.

### B4 — Ruta

Orden inteligente inicial, reordenación manual, mapa, estados de parada.

### B5 — Cliente

Tracking por link, ETA y posición aproximada.

### B6 — Cobros

Multimoneda, medios, vuelto, esperado/real y conciliación.

### B7 — Tarifas

Tabla oficial, buscador, cotizador, vigencia y excepciones.

### B8 — Offline / Push

Cache de jornada, cola de sincronización y notificaciones críticas.

### B9 — Asistente IA

NEXO AI Gateway, router Gemini/OpenAI, conocimiento editable y acciones con permisos.

### B10 — WhatsApp oficial

Automatización, clasificación, plantillas y handoff humano.

### B11 — Optimización avanzada

Matriz de rutas, históricos, priorización dinámica y capa geográfica propia.

## 20. Criterios de aceptación del piloto

- Un pedido puede entrar sin volver a escribir manualmente todos sus datos.
- El mensajero puede contactar a todos los clientes desde una sola pantalla.
- La dependienta puede preparar/cargar mercancía sin leer chats individuales.
- Un cambio posterior a carga genera actualización y notificación visible.
- El mensajero puede ordenar la ruta y completar una jornada con mala conexión.
- Cada entrega muestra exactamente qué cobrar y qué vuelto llevar.
- El cliente puede conocer estado/ETA sin instalar app.
- Las gestoras pueden consultar tarifas sin preguntar por WhatsApp.
- Ningún rol ve información innecesaria.
- El sistema conserva evidencia y auditoría suficientes para reconstruir qué ocurrió.

## 21. Decisiones cerradas

- Todos los roles autorizados pueden subir vales.
- Se aceptarán todos los formatos definidos.
- Confirmación rápida antes de creación; anomalías obligan revisión.
- IA pregunta por faltantes.
- Múltiples proveedores/puntos de recogida.
- Gestora puede editar sus pedidos; cambios post-carga notifican y sincronizan.
- Contacto registrable por cualquier actor autorizado.
- Ubicación guardada y reutilizable.
- Mapas intercambiables; mensajero decide.
- Ruta: prioridad/restricción del cliente antes que distancia/eficiencia.
- Cliente: estado + ETA + posición aproximada cuando proceda.
- Dependienta usa la vista operativa propuesta.
- Enlace al pedido original disponible.
- Multimoneda y múltiples medios de pago.
- Mensajero confirma cobro real.
- Tarifas administradas por autorizados; modelo híbrido.
- Asistente único/orquestador con identidades por negocio.
- Si la IA no sabe, deriva o pide confirmación.
- Portal conversacional público.
- Router Gemini/OpenAI.
- Panel de conocimiento editable.
- Vale original conservado como evidencia.
- Notificaciones críticas.
- Modo offline.
- Gestora ve solo sus pedidos; mensajero/dependienta mínimo privilegio.

## 22. Preguntas abiertas no bloqueantes

- proveedor oficial de WhatsApp Business Platform/API;
- reglas exactas del cálculo cuando no exista tarifa fija;
- tabla vigente inicial de zonas/tarifas;
- política de retención de vales y datos personales;
- proveedor principal de mapas y estrategia offline detallada;
- umbral para mostrar posición aproximada al cliente;
- política de severidad de push;
- nombre comercial definitivo del portal/asistente.

## 23. Gobernanza

Este documento es la fuente de verdad funcional para el sistema de mensajería y su evolución conversacional.

Toda implementación debe clasificarse como:

- **MVP actual**;
- **siguiente iteración**;
- **expansión del ecosistema**.

No se debe introducir un nuevo estado, motor de tarifas, sistema de pedidos, base de datos paralela o canal de IA sin comprobar primero compatibilidad con este Blueprint y con la arquitectura canónica de Casa Viva.
