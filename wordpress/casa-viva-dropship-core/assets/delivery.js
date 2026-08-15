(function(){
  "use strict";
  var input=document.getElementById("cvd-delivery-search"),button=document.getElementById("cvd-delivery-scan"),video=document.getElementById("cvd-delivery-scanner"),help=document.getElementById("cvd-scan-help"),stream=null;
  if(!input)return;
  function normalize(value){return String(value||"").normalize("NFD").replace(/[\u0300-\u036f]/g,"").toLowerCase();}
  function filter(){var q=normalize(input.value);document.querySelectorAll(".cvd-sales-list>.cvd-sale-card").forEach(function(card){card.hidden=q&&!normalize(card.textContent).includes(q);});}
  function stop(){if(stream)stream.getTracks().forEach(function(track){track.stop();});stream=null;video.hidden=true;}
  input.addEventListener("input",filter);
  button.addEventListener("click",async function(){
    if(stream){stop();return;}
    if(!("BarcodeDetector" in window)||!navigator.mediaDevices){input.focus();input.placeholder="Escribe el número del pedido";if(help)help.textContent="Este navegador no tiene lector integrado. Abre la cámara normal del teléfono de la dependienta y apunta al QR, o escribe aquí el número del pedido.";return;}
    try{var detector=new BarcodeDetector({formats:["qr_code","code_128"]});stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:"environment"}},audio:false});video.srcObject=stream;video.hidden=false;await video.play();
      var scan=async function(){if(!stream)return;var codes=await detector.detect(video);if(codes.length){var raw=codes[0].rawValue;try{var url=new URL(raw);if(url.origin===location.origin&&url.searchParams.get("action")==="cvd_delivery_pickup"){stop();if(window.confirm("¿Confirmar la salida de este pedido con el mensajero?"))location.assign(url.href);return;}}catch(ignore){}var match=raw.match(/(?:CV-PEDIDO-)?(\d+)/i);if(match){input.value=match[1];filter();stop();var card=document.querySelector(".cvd-sales-list>.cvd-sale-card:not([hidden])");if(card)card.scrollIntoView({behavior:"smooth",block:"start"});return;}}setTimeout(scan,220);};scan();
    }catch{stop();input.focus();input.placeholder="Escribe el número del pedido";if(help)help.textContent="No se pudo usar la cámara. En los permisos del navegador permite Cámara para casavivadecuba.com, o escribe el número del pedido.";}
  });
  window.addEventListener("pagehide",stop);
})();
