package company.casaviva.palla;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.media.AudioAttributes;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.Uri;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

/**
 * Escucha los avisos de Pa'llá en el ntfy del VPS (https://…/TEMA/json, una conexión abierta con latido cada 45 s:
 * unos 0,3–1 MB al día) y, cuando sale «🛵 Carrera libre», suena la bocina hasta que el mensajero lo mira (máx. 3 min).
 * Sin Google: funciona en Cuba sin VPN (probado con la app ntfy el 27-oct). Si se cae la señal, reintenta solo.
 */
public class AvisosService extends Service {
    private static final String CONFIG_URL = "https://casaviva.company/wp-json/casa-viva/v1/palla/app";
    private static final String CH_RUN = "palla_conexion";
    private static final String CH_NEW = "palla_carreras_1";
    private static final int ID_RUN = 1, ID_NEW = 2;
    private static final long ALARM_MS = 3 * 60_000L;   // la bocina se repite como mucho 3 min
    private static final long FRESH_S = 10 * 60L;       // al reconectar, solo suenan los avisos de los últimos 10 min

    private volatile boolean running;
    private volatile HttpURLConnection conn;
    private Thread worker;
    private ConnectivityManager.NetworkCallback netCallback;

    // ------------------------------------------------------------------ Desde fuera

    /** Pide a Core el tema de avisos de este mensajero (GET palla/app con su enlace) y arranca. Hilo de fondo. */
    static void configure(Context c) {
        String token = Prefs.token(c);
        if (token.isEmpty()) { return; }
        HttpURLConnection h = null;
        try {
            h = (HttpURLConnection) new URL(CONFIG_URL).openConnection();
            h.setConnectTimeout(15_000);
            h.setReadTimeout(20_000);
            h.setRequestProperty("Accept", "application/json");
            h.setRequestProperty("X-Palla-Token", token);
            int code = h.getResponseCode();
            InputStream in = code >= 400 ? h.getErrorStream() : h.getInputStream();
            JSONObject d = new JSONObject(read(in));
            if (d.optBoolean("ok")) {
                Prefs.setServer(c, d.getString("url"), d.getString("topic"));
                Prefs.setStatus(c, "conectando…");
                start(c);
            } else {
                Prefs.clearServer(c);
                Prefs.setStatus(c, "todavía no están abiertos para ti");
                c.stopService(new Intent(c, AvisosService.class));
            }
        } catch (Exception e) {
            Prefs.setStatus(c, "sin señal, lo intento luego");
        } finally {
            if (h != null) { h.disconnect(); }
        }
    }

    static void start(Context c) {
        if (!Prefs.ready(c)) { return; }
        Intent i = new Intent(c, AvisosService.class);
        try {
            if (Build.VERSION.SDK_INT >= 26) { c.startForegroundService(i); } else { c.startService(i); }
        } catch (Exception ignored) { } // Android puede no dejar arrancarlo desde el fondo: lo arranca la app al abrirse
    }

    /** Calla la bocina y quita el aviso (al abrir la app). */
    static void stopAlarm(Context c) {
        NotificationManager nm = (NotificationManager) c.getSystemService(NOTIFICATION_SERVICE);
        if (nm != null) { nm.cancel(ID_NEW); }
    }

    // ------------------------------------------------------------------ Servicio

    @Override
    public void onCreate() {
        super.onCreate();
        channels();
        Notification n = builder(CH_RUN)
            .setContentTitle("Pa'llá")
            .setContentText("Esperando carreras")
            .setOngoing(true)
            .setContentIntent(openApp(null, 0))
            .build();
        if (Build.VERSION.SDK_INT >= 34) {
            startForeground(ID_RUN, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE);
        } else {
            startForeground(ID_RUN, n);
        }
        listenNetwork();
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (!Prefs.ready(this)) { stopSelf(); return START_NOT_STICKY; }
        if (worker == null || !worker.isAlive()) {
            running = true;
            worker = new Thread(this::loop, "palla-avisos");
            worker.start();
        }
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        running = false;
        HttpURLConnection c = conn;
        if (c != null) { c.disconnect(); }
        if (worker != null) { worker.interrupt(); }
        if (netCallback != null) {
            try { ((ConnectivityManager) getSystemService(CONNECTIVITY_SERVICE)).unregisterNetworkCallback(netCallback); } catch (Exception ignored) { }
        }
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) { return null; }

    /** Conectar → leer avisos línea a línea → si se corta, esperar (5 s, 10 s… hasta 1 min) y volver a conectar. */
    private void loop() {
        long wait = 5_000;
        while (running) {
            try {
                String last = Prefs.lastId(this);
                String url = Prefs.server(this) + "/" + Prefs.topic(this) + "/json" + (last.isEmpty() ? "" : "?since=" + last);
                HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
                conn = c;
                c.setConnectTimeout(20_000);
                c.setReadTimeout(120_000); // ntfy manda un latido cada 45 s: si en 2 min no llega nada, la conexión está muerta
                c.setRequestProperty("Accept", "application/x-ndjson");
                if (c.getResponseCode() != 200) { throw new IllegalStateException("HTTP " + c.getResponseCode()); }
                Prefs.setStatus(this, "conectado");
                wait = 5_000;
                try (BufferedReader r = new BufferedReader(new InputStreamReader(c.getInputStream(), StandardCharsets.UTF_8))) {
                    String line;
                    while (running && (line = r.readLine()) != null) { handle(line); }
                }
            } catch (Exception e) {
                if (running) { Prefs.setStatus(this, "sin señal, reintentando"); }
            } finally {
                HttpURLConnection c = conn;
                if (c != null) { c.disconnect(); }
                conn = null;
            }
            if (!running) { break; }
            try { Thread.sleep(wait); } catch (InterruptedException ignored) { } // la vuelta de la red lo despierta antes
            wait = Math.min(wait * 2, 60_000);
        }
    }

    private void handle(String line) {
        try {
            JSONObject e = new JSONObject(line);
            if (!"message".equals(e.optString("event"))) { return; }
            Prefs.setLastId(this, e.optString("id"));
            if (System.currentTimeMillis() / 1000 - e.optLong("time") > FRESH_S) { return; } // viejo (estaba sin señal): no suena
            alarm(e.optString("title", "Pa'llá"), e.optString("message", ""), e.optString("click", ""));
        } catch (Exception ignored) { }
    }

    /** El aviso que suena: bocina repetida (FLAG_INSISTENT) hasta que lo toque o abra la app; a los 3 min, se calla solo. */
    private void alarm(String title, String text, String click) {
        NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        if (nm == null) { return; }
        Notification.Builder b = builder(CH_NEW)
            .setContentTitle(title)
            .setContentText(text)
            .setStyle(new Notification.BigTextStyle().bigText(text))
            .setAutoCancel(true)
            .setCategory(Notification.CATEGORY_MESSAGE)
            .setContentIntent(openApp(click, 1));
        if (Build.VERSION.SDK_INT < 26) {
            b.setSound(sound()).setVibrate(new long[]{0, 400, 150, 400, 150, 400}).setPriority(Notification.PRIORITY_MAX);
        }
        Notification n = b.build();
        n.flags |= Notification.FLAG_INSISTENT;
        nm.notify(ID_NEW, n);
        new Handler(Looper.getMainLooper()).postDelayed(() -> {
            // Pasados 3 min: el mismo aviso, ya sin sonar (si sigue ahí).
            for (android.service.notification.StatusBarNotification s : nm.getActiveNotifications()) {
                if (s.getId() == ID_NEW) { nm.notify(ID_NEW, b.setOnlyAlertOnce(true).build()); break; }
            }
        }, ALARM_MS);
    }

    // ------------------------------------------------------------------ Ayudas

    private Notification.Builder builder(String channel) {
        Notification.Builder b = Build.VERSION.SDK_INT >= 26 ? new Notification.Builder(this, channel) : new Notification.Builder(this);
        return b.setSmallIcon(R.drawable.ic_stat_palla).setColor(0xFFFFC21A);
    }

    private PendingIntent openApp(String url, int code) {
        Intent i = new Intent(this, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        i.putExtra("open", url != null && !url.isEmpty() ? url : MainActivity.HOME + "#carreras");
        return PendingIntent.getActivity(this, code, i, PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= 23 ? PendingIntent.FLAG_IMMUTABLE : 0));
    }

    private Uri sound() { return Uri.parse("android.resource://" + getPackageName() + "/" + R.raw.bocina); }

    private void channels() {
        if (Build.VERSION.SDK_INT < 26) { return; }
        NotificationManager nm = getSystemService(NotificationManager.class);
        NotificationChannel run = new NotificationChannel(CH_RUN, "Conexión de avisos", NotificationManager.IMPORTANCE_MIN);
        run.setShowBadge(false);
        nm.createNotificationChannel(run);
        NotificationChannel nueva = new NotificationChannel(CH_NEW, "Carreras libres", NotificationManager.IMPORTANCE_HIGH);
        nueva.setSound(sound(), new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_EVENT).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build());
        nueva.enableVibration(true);
        nueva.setVibrationPattern(new long[]{0, 400, 150, 400, 150, 400});
        nueva.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
        nm.createNotificationChannel(nueva);
    }

    /** Si vuelve la red, no esperar al próximo reintento. */
    private void listenNetwork() {
        if (Build.VERSION.SDK_INT < 24) { return; }
        try {
            ConnectivityManager cm = (ConnectivityManager) getSystemService(CONNECTIVITY_SERVICE);
            netCallback = new ConnectivityManager.NetworkCallback() {
                @Override
                public void onAvailable(Network network) {
                    Thread w = worker;
                    if (w != null && conn == null) { w.interrupt(); }
                }
            };
            cm.registerDefaultNetworkCallback(netCallback);
        } catch (Exception ignored) { }
    }

    private static String read(InputStream in) throws java.io.IOException {
        if (in == null) { return "{}"; }
        StringBuilder sb = new StringBuilder();
        try (BufferedReader r = new BufferedReader(new InputStreamReader(in, StandardCharsets.UTF_8))) {
            String line;
            while ((line = r.readLine()) != null) { sb.append(line); }
        }
        return sb.toString();
    }
}
