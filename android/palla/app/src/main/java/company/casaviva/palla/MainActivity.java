package company.casaviva.palla;

import android.Manifest;
import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.PowerManager;
import android.provider.Settings;
import android.webkit.GeolocationPermissions;
import android.webkit.JavascriptInterface;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

/**
 * La misma Pa'llá de la web (casaviva.company/palla/) a pantalla completa. Lo único que añade la app:
 *  - el puente window.PallaApp: la web le pasa el enlace del mensajero (setToken) y pregunta cómo van los avisos (status);
 *  - el servicio AvisosService, que avisa con sonido aunque la app esté cerrada;
 *  - el enlace personal de WhatsApp abre la app (assetlinks), y los enlaces de fuera (Llamar, Ir, WhatsApp) salen fuera.
 */
public class MainActivity extends Activity {
    static final String HOME = BuildConfig.HOME; // mensajero: /palla/ · chofer (10-nov): /palla/chofer/
    private static final int REQ_NOTIF = 1, REQ_GEO = 2;

    private WebView web;
    private GeolocationPermissions.Callback geoCallback;
    private String geoOrigin;

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        // Solo la copia de pruebas (debug, la del teléfono simulado): la pantalla se puede manejar desde el ordenador.
        if ((getApplicationInfo().flags & android.content.pm.ApplicationInfo.FLAG_DEBUGGABLE) != 0) { WebView.setWebContentsDebuggingEnabled(true); }
        web = new WebView(this);
        setContentView(web);
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setGeolocationEnabled(true);
        s.setUserAgentString(s.getUserAgentString() + " PallaApp/" + BuildConfig.VERSION_NAME);
        web.addJavascriptInterface(new Bridge(getApplicationContext()), "PallaApp");
        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri u = request.getUrl();
                if (isPalla(u)) { return false; }
                try { startActivity(new Intent(Intent.ACTION_VIEW, u)); } catch (Exception ignored) { }
                return true;
            }
        });
        web.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onGeolocationPermissionsShowPrompt(String origin, GeolocationPermissions.Callback callback) {
                if (hasGeo()) { callback.invoke(origin, true, false); return; }
                geoOrigin = origin;
                geoCallback = callback;
                if (Build.VERSION.SDK_INT >= 23) {
                    requestPermissions(new String[]{Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION}, REQ_GEO);
                }
            }
        });
        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, REQ_NOTIF);
        }
        // 10-nov (Redmi): si Android cerró la app y la reabre desde un aviso, antes se restauraba la página vieja y se
        // ignoraba a dónde llevaba el aviso. Ahora, si el aviso trae dirección, se abre esa.
        if (BuildConfig.CHOFER && getIntent() != null && getIntent().hasExtra("aceptar")) { web.loadUrl(HOME); aceptarDesdeAviso(getIntent()); }
        else if (state != null) {
            web.restoreState(state);
            Intent in = getIntent();
            if (in != null && (in.getData() != null || in.hasExtra("open"))) { web.loadUrl(urlFrom(in)); in.removeExtra("open"); in.setData(null); }
        } else { web.loadUrl(urlFrom(getIntent())); }
    }

    /**
     * 10-nov (chofer): «Aceptar» desde el aviso → «Acepto» al precio del cliente (POST pv/chofer/pedidos/{id}/ofertar
     * {pct:0}) y luego se abre «Pedidos cerca», donde se ve «Enviaste… Esperando» o si ya lo cogió otro.
     */
    private void aceptarDesdeAviso(Intent in) {
        String id = in == null ? null : in.getStringExtra("aceptar");
        if (id == null || !id.matches("\\d+")) { return; }
        in.removeExtra("aceptar");
        AvisosService.stopAlarm(this);
        final String url = urlFrom(in), token = Prefs.token(this);
        new Thread(() -> {
            java.net.HttpURLConnection h = null;
            try {
                h = (java.net.HttpURLConnection) new java.net.URL("https://casaviva.company/wp-json/casa-viva/v1/pv/chofer/pedidos/" + id + "/ofertar").openConnection();
                h.setConnectTimeout(10_000);
                h.setReadTimeout(15_000);
                h.setDoOutput(true);
                h.setRequestMethod("POST");
                h.setRequestProperty("Content-Type", "application/json");
                h.setRequestProperty("X-Palla-Token", token);
                h.getOutputStream().write("{\"pct\":0}".getBytes(java.nio.charset.StandardCharsets.UTF_8));
                h.getResponseCode();
            } catch (Exception ignored) {
            } finally {
                if (h != null) { h.disconnect(); }
            }
            runOnUiThread(() -> web.loadUrl(url)); // la lista ya trae su oferta (o «lo cogió otro»)
        }, "palla-aceptar").start();
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        if (BuildConfig.CHOFER && intent.hasExtra("aceptar")) { aceptarDesdeAviso(intent); return; }
        if (intent.getData() != null || intent.hasExtra("open")) { web.loadUrl(urlFrom(intent)); }
    }

    // ------------------------------------------------------------------ 10-nov (chofer): permisos para que suene
    private boolean permisoAbierto, permisoAhoraNo, notifPedida, geoPedida; // pedidas una vez por apertura: si dice que no, no se repite en bucle

    /**
     * Prueba en el Redmi de Ernesto (MIUI): la app estaba «En línea» pero sin permiso de avisos, sin «Inicio automático» y
     * con ahorro de batería: así Android la calla o la cierra. Al conectarse (y al volver a la app) se le guía, de uno en
     * uno: 1) avisos, 2) ubicación, 3) batería sin restricciones, 4) en Xiaomi, «Inicio automático». «Ahora no» lo deja
     * hasta la próxima vez que abra la app.
     */
    private void checkPermisos() {
        if (!BuildConfig.CHOFER || permisoAbierto || permisoAhoraNo || isFinishing() || !Prefs.available(this)) { return; }
        android.app.NotificationManager nm = (android.app.NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED && !notifPedida) {
            notifPedida = true;
            requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, REQ_NOTIF);
            return;
        }
        if (nm != null && !nm.areNotificationsEnabled()) {
            Intent i = Build.VERSION.SDK_INT >= 26
                ? new Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, getPackageName())
                : new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:" + getPackageName()));
            pedir("Permite los avisos", "Activa «Permitir notificaciones».", i);
            return;
        }
        if (!hasGeo() && !geoPedida) { geoPedida = true; askLocation(); return; }
        PowerManager pm = (PowerManager) getSystemService(POWER_SERVICE);
        if (Build.VERSION.SDK_INT >= 23 && pm != null && !pm.isIgnoringBatteryOptimizations(getPackageName())) {
            pedir("Que no se duerma", "Para que suene con la pantalla apagada: toca «Permitir» o «Sin restricciones».",
                new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:" + getPackageName())));
            return;
        }
        if (isXiaomi() && !Prefs.autostartAsked(this)) {
            Prefs.setAutostartAsked(this);
            Intent i = new Intent().setComponent(new android.content.ComponentName("com.miui.securitycenter", "com.miui.permcenter.autostart.AutoStartManagementActivity"));
            if (getPackageManager().resolveActivity(i, 0) == null) { i = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:" + getPackageName())); }
            pedir("Inicio automático", "Busca «Pa'llá Chofer» y actívalo.", i);
        }
    }

    private static boolean isXiaomi() {
        String m = (Build.MANUFACTURER + " " + Build.BRAND).toLowerCase(java.util.Locale.ROOT);
        return m.contains("xiaomi") || m.contains("redmi") || m.contains("poco");
    }

    private void pedir(String titulo, String texto, Intent abrir) {
        permisoAbierto = true;
        new android.app.AlertDialog.Builder(this)
            .setTitle(titulo)
            .setMessage(texto)
            .setCancelable(false)
            .setPositiveButton("Abrir", (d, w) -> {
                permisoAbierto = false;
                try { startActivity(abrir); } catch (Exception e) {
                    try { startActivity(new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:" + getPackageName()))); } catch (Exception ignored) { }
                }
            })
            .setNegativeButton("Ahora no", (d, w) -> { permisoAbierto = false; permisoAhoraNo = true; })
            .show();
    }

    /** 10-nov (auditoría): fuera de la pantalla, la web no sigue preguntando ni usando el GPS (el servicio avisa y manda la posición). */
    @Override
    protected void onPause() {
        super.onPause();
        if (BuildConfig.CHOFER && web != null) { web.onPause(); web.pauseTimers(); }
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (BuildConfig.CHOFER && web != null) { web.resumeTimers(); web.onResume(); }
        if (BuildConfig.CHOFER) { checkPermisos(); } // 10-nov: al volver de Ajustes, el paso siguiente
        AvisosService.stopAlarm(this); // al abrir la app, la bocina se calla
        if (Prefs.needsService(this)) { AvisosService.start(this); } // 1.3: solo si está disponible o en una carrera
    }

    /** Tocar la pantalla de la app calla la bocina (como en la web: suena hasta que la mira). */
    @Override
    public boolean dispatchTouchEvent(android.view.MotionEvent ev) {
        if (ev.getAction() == android.view.MotionEvent.ACTION_DOWN) { AvisosService.stopSiren(this); }
        return super.dispatchTouchEvent(ev);
    }

    /**
     * 1.2: con la app delante, el botón de volumen calla la bocina (y esa pulsación no cambia el volumen). En
     * dispatchKeyEvent, antes que la página: en el Redmi de Ernesto onKeyDown no llegaba a recibirlo.
     */
    @Override
    public boolean dispatchKeyEvent(android.view.KeyEvent event) {
        int k = event.getKeyCode();
        if ((k == android.view.KeyEvent.KEYCODE_VOLUME_DOWN || k == android.view.KeyEvent.KEYCODE_VOLUME_UP) && AvisosService.ringing()) {
            if (event.getAction() == android.view.KeyEvent.ACTION_DOWN) { AvisosService.stopSiren(this); }
            return true;
        }
        return super.dispatchKeyEvent(event);
    }

    @Override
    protected void onSaveInstanceState(Bundle out) {
        super.onSaveInstanceState(out);
        web.saveState(out);
    }

    @Override
    public void onBackPressed() {
        if (web.canGoBack()) { web.goBack(); } else { super.onBackPressed(); }
    }

    @Override
    public void onRequestPermissionsResult(int code, String[] permissions, int[] results) {
        super.onRequestPermissionsResult(code, permissions, results);
        if (code == REQ_GEO && geoCallback != null) {
            geoCallback.invoke(geoOrigin, hasGeo(), false);
            geoCallback = null;
        }
        if (code == REQ_GEO && hasGeo() && Prefs.active(this)) { AvisosService.track(this, true); } // 1.1: ya puede seguir el recorrido
        if (BuildConfig.CHOFER && (code == REQ_NOTIF || code == REQ_GEO)) { checkPermisos(); } // 10-nov: el siguiente paso
    }

    private boolean hasGeo() {
        return Build.VERSION.SDK_INT < 23 || checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
            || checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED;
    }

    static boolean isPalla(Uri u) {
        return u != null && "https".equals(u.getScheme()) && "casaviva.company".equals(u.getHost()) && u.getPath() != null && u.getPath().startsWith("/palla");
    }

    /** El enlace personal de WhatsApp, la carrera de un aviso (extra «open») o la portada de Pa'llá. */
    private static String urlFrom(Intent intent) {
        if (intent != null) {
            Uri data = intent.getData();
            if (isPalla(data)) { return data.toString(); }
            String open = intent.getStringExtra("open");
            if (open != null && isPalla(Uri.parse(open))) { return open; }
        }
        return HOME;
    }

    /** window.PallaApp en la web. Los métodos corren en un hilo del WebView: nada de pantalla aquí. */
    private final class Bridge {
        private final Context ctx;

        Bridge(Context ctx) { this.ctx = ctx; }

        @JavascriptInterface
        public void setToken(String token) {
            if (token == null || token.trim().isEmpty()) { return; }
            boolean changed = !token.trim().equals(Prefs.token(ctx));
            Prefs.setToken(ctx, token);
            if (changed || !Prefs.ready(ctx)) { new Thread(() -> AvisosService.configure(ctx), "palla-config").start(); }
            if (!BuildConfig.CHOFER) { runOnUiThread(MainActivity.this::askBattery); }
        }

        /** 1.1: la web dice si hay carreras abiertas → la app manda (o deja de mandar) el recorrido. */
        @JavascriptInterface
        public void setActive(boolean active) {
            if (active == Prefs.active(ctx)) { return; }
            Prefs.setActive(ctx, active);
            runOnUiThread(() -> {
                if (active) { askLocation(); }
                AvisosService.track(ctx, active);
            });
        }

        /** 1.3: el botón 🟢 Disponible / ⚪ No disponible de la web. Desconectado (y sin carrera) la app se apaga del todo. */
        @JavascriptInterface
        public void setAvailable(boolean on, double until) {
            long u = (long) until;
            if (on == Prefs.available(ctx) && u == Prefs.availableUntil(ctx)) { return; }
            Prefs.setAvailable(ctx, on, u);
            runOnUiThread(() -> {
                if (BuildConfig.CHOFER && on) { permisoAhoraNo = false; checkPermisos(); } // chofer: en línea → que suene y mande su posición
                AvisosService.availability(ctx);
            });
        }

        /** 10-nov: «Probar el sonido» en Mi perfil (el chofer comprueba que su teléfono no calla la app). */
        @JavascriptInterface
        public void probarSonido() { AvisosService.startSiren(ctx); }

        @JavascriptInterface
        public String status() {
            String s = Prefs.status(ctx);
            return s.isEmpty() ? "conectando…" : s;
        }
    }

    /** 1.1: el permiso de ubicación para el recorrido (si aún no lo tiene). */
    private void askLocation() {
        if (Build.VERSION.SDK_INT >= 23 && !hasGeo()) {
            requestPermissions(new String[]{Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION}, REQ_GEO);
        }
    }

    /** Una sola vez: pedir que Android no duerma la app (si no, en muchos teléfonos los avisos se cortan). */
    private void askBattery() {
        if (Build.VERSION.SDK_INT < 23 || Prefs.batteryAsked(this)) { return; }
        Prefs.setBatteryAsked(this);
        PowerManager pm = (PowerManager) getSystemService(POWER_SERVICE);
        if (pm == null || pm.isIgnoringBatteryOptimizations(getPackageName())) { return; }
        try {
            startActivity(new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:" + getPackageName())));
        } catch (Exception ignored) { }
    }
}
