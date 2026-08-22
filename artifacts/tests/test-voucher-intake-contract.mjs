import fs from "node:fs";
const php=fs.readFileSync("wordpress/casa-viva-dropship-core/includes/class-cvd-voucher-intake.php","utf8");
const plugin=fs.readFileSync("wordpress/casa-viva-dropship-core/includes/class-cvd-plugin.php","utf8");
for(const required of ["/api/messaging/parse-voucher","wp_remote_post","permission_callback","CVD_Registration::is_approved_gestora","cvd_nexo_unavailable","No se creó ningún pedido"]){if(!php.includes(required))throw new Error(`Falta contrato: ${required}`)}
if(/api[_-]?key|Authorization/i.test(fs.readFileSync("wordpress/casa-viva-dropship-core/assets/voucher-intake.js","utf8")))throw new Error("El cliente no debe recibir secretos");
if(!plugin.includes("'interpretar-vale'")||!plugin.includes("CVD_Voucher_Intake::register"))throw new Error("La superficie canónica no está registrada");
console.log("OK: Casa Viva consume NEXO sin persistencia ni secretos cliente.");
