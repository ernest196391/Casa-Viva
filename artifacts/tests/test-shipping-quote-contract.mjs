import fs from "node:fs";const php=fs.readFileSync("wordpress/casa-viva-dropship-core/includes/class-cvd-shipping-rates.php","utf8");const plugin=fs.readFileSync("wordpress/casa-viva-dropship-core/includes/class-cvd-plugin.php","utf8");for(const item of ["/shipping/quote","self::quote(","official","referenceCup","casa_viva_shipping_quote","tarifas-mensajeria"]){if(!php.includes(item)&&!plugin.includes(item))throw new Error(`Falta contrato de tarifa: ${item}`)}const js=fs.readFileSync("wordpress/casa-viva-dropship-core/assets/shipping-quote.js","utf8");if(/NEXO|estimatePrice|Math\.random/.test(js))throw new Error("La tarifa no puede originarse en IA o cálculo cliente");console.log("OK: cotizador móvil usa exclusivamente tarifas Casa Viva.");

for(const required of ["DATA_VERSION_OPTION","2026-09-23-v3","update_option( self::OPTION, $rates","update_option( self::DATA_VERSION_OPTION, self::VERSION"]){if(!php.includes(required))throw new Error(`Falta sincronización versionada de tarifas: ${required}`)}
const csv=fs.readFileSync("wordpress/casa-viva-dropship-core/data/shipping-rates.csv","utf8");for(const required of ["Regla,Casablanca,4500","Boyeros,Bejucal,5000","Boyeros,Embil,2800","Cerro,,1700"]){if(!csv.includes(required))throw new Error(`Tarifa unificada ausente: ${required}`)}
console.log("OK: matriz unificada y sincronización versionada verificadas.");

if(csv.includes("Habana del Este,Casa Blanca,"))throw new Error("Casa Blanca no debe duplicarse en Habana del Este");
if(!csv.includes("Regla,Casablanca,4500"))throw new Error("Casablanca debe existir solo en Regla a 4500 CUP");
console.log("OK: Casablanca única en Regla a 4500 CUP.");
