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
    static final String HOME = "https://casaviva.company/palla/";
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
        if (state != null) { web.restoreState(state); } else { web.loadUrl(urlFrom(getIntent())); }
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        if (intent.getData() != null || intent.hasExtra("open")) { web.loadUrl(urlFrom(intent)); }
    }

    @Override
    protected void onResume() {
        super.onResume();
        AvisosService.stopAlarm(this); // al abrir la app, la bocina se calla
        if (Prefs.ready(this)) { AvisosService.start(this); }
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
            runOnUiThread(MainActivity.this::askBattery);
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
