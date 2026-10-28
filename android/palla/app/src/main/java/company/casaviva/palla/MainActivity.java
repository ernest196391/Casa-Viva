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

        @JavascriptInterface
        public String status() {
            String s = Prefs.status(ctx);
            return s.isEmpty() ? "conectando…" : s;
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
