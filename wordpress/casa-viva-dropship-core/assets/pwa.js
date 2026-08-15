(function () {
  "use strict";

  if ("serviceWorker" in navigator && window.cvdPwa) {
    window.addEventListener("load", function () {
      navigator.serviceWorker.register(cvdPwa.workerUrl, { scope: "/", updateViaCache: "none" }).then(async function (registration) {
        await registration.update();
        if (cvdPwa.canPush && Notification.permission === "granted") {
          var subscription = await registration.pushManager.getSubscription();
          if (subscription) await saveSubscription(subscription);
        }
      }).catch(function () {});
    });
  }

  function decodeKey(value) {
    var padding = '='.repeat((4 - value.length % 4) % 4);
    var binary = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(binary, function (character) { return character.charCodeAt(0); });
  }

  async function saveSubscription(subscription) {
    var response = await fetch(cvdPwa.pushSubscriptionUrl, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce':cvdPwa.restNonce}, body:JSON.stringify({endpoint:subscription.endpoint}) });
    if (!response.ok) throw new Error('No se pudo vincular este teléfono.');
  }

  window.cvdPushIsSubscribed = async function () {
    if (!cvdPwa.canPush || !('serviceWorker' in navigator) || !('PushManager' in window)) return false;
    var registration = await navigator.serviceWorker.ready;
    return Boolean(await registration.pushManager.getSubscription());
  };

  window.cvdPushToggle = async function () {
    if (!cvdPwa.canPush || !('serviceWorker' in navigator) || !('PushManager' in window) || !cvdPwa.pushPublicKey) throw new Error('Los avisos en segundo plano no están disponibles.');
    var permission = await Notification.requestPermission();
    if (permission !== 'granted') throw new Error('Debes permitir las notificaciones del teléfono.');
    var registration = await navigator.serviceWorker.ready;
    var subscription = await registration.pushManager.getSubscription();
    if (!subscription) subscription = await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:decodeKey(cvdPwa.pushPublicKey)});
    await saveSubscription(subscription);
    return true;
  };

  window.addEventListener('online', async function () {
    if (!cvdPwa.canPush || Notification.permission !== 'granted') return;
    try {
      var registration = await navigator.serviceWorker.ready;
      var subscription = await registration.pushManager.getSubscription();
      if (subscription) await saveSubscription(subscription);
    } catch (ignore) {}
  });

  var promptEvent = null;
  var buttons = [];

  function installButtons() {
    buttons = Array.prototype.slice.call(document.querySelectorAll("[data-cvd-install]"));
    buttons.forEach(function (button) {
      button.hidden = false;
      button.addEventListener("click", async function () {
        if (!promptEvent) return;
        promptEvent.prompt();
        await promptEvent.userChoice;
        promptEvent = null;
        buttons.forEach(function (item) { item.hidden = true; });
      });
    });
  }

  window.addEventListener("beforeinstallprompt", function (event) {
    event.preventDefault();
    promptEvent = event;
    installButtons();
  });

  window.addEventListener("appinstalled", function () {
    buttons.forEach(function (button) { button.hidden = true; });
  });

  document.addEventListener('click', async function (event) {
    var button = event.target.closest('[data-cvd-enable-notifications]');
    if (!button || !window.cvdPushToggle) return;
    button.disabled = true; button.textContent = 'Activando…';
    try { await window.cvdPushToggle(); button.textContent = 'Alertas activadas'; }
    catch (error) { button.textContent = error.message; button.disabled = false; }
  });
})();
