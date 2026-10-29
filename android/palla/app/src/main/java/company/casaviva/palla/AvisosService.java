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
import android.os.Bundle;
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
    // 1.1 (28-oct, Ernesto: «salió con la P pero no sonó»): el canal ya no lleva sonido; la bocina la toca la propia app
    // como ALARMA (MediaPlayer, volumen de alarma), así no depende del volumen de notificaciones ni de cómo cada marca de
    // teléfono silencia las apps nuevas. Canal nuevo porque Android no deja cambiar el sonido de un canal ya creado.
    private static final String CH_NEW = "palla_carreras_2";
    private static final String ACTION_STOP = "company.casaviva.palla.PARAR";
    private static final String ACTION_SILENCE = "company.casaviva.palla.SILENCIAR"; // 1.2: calla, pero el aviso se queda
    private static android.media.MediaPlayer siren;
    // 1.2 (29-oct, Ernesto: «se quedó más de 1 min vibrando y no sabía cómo apagarlo»): la vibración se para con el MISMO
    // Vibrator que la empezó (en algunos teléfonos cancel() desde otra pantalla de la app no la paraba).
    private static android.os.Vibrator vib;
    private static android.content.BroadcastReceiver keys;
    private static long sirenAt;
    private static String alarmOrder = "";              // la carrera que está sonando (tag «o<id>»), para callarla si otro la coge
    private static final Handler MAIN = new Handler(Looper.getMainLooper());
    private static final int ID_RUN = 1, ID_NEW = 2;
    private static final long ALARM_MS = 30_000L;       // 1.2 (Ernesto): la bocina suena como mucho 30 s
    private static final long FRESH_S = 10 * 60L;       // al reconectar, solo suenan los avisos de los últimos 10 min

    private volatile boolean running;
    private volatile HttpURLConnection conn;
    private Thread worker;
    private ConnectivityManager.NetworkCallback netCallback;

    // 1.1 Recorrido (Ernesto 28-oct, «como Mandao»): con carreras abiertas, un punto cada 30 s si se mueve (o 50 m), y
    // cada minuto se mandan juntos a Core (POST palla/ubicacion). Sin señal se guardan y salen al volver.
    private static final String TRACK_URL = "https://casaviva.company/wp-json/casa-viva/v1/palla/ubicacion";
    private static final String ACTION_TRACK = "company.casaviva.palla.RECORRIDO";
    private Notification runNotif;
    private android.location.LocationListener locListener;
    private java.util.concurrent.ScheduledExecutorService uploader;
    private android.location.Location lastLoc;

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

    /** 1.1: empezar o parar el recorrido (lo pide la web por PallaApp.setActive). */
    static void track(Context c, boolean on) {
        if (!Prefs.ready(c)) { return; }
        Intent i = new Intent(c, AvisosService.class).setAction(ACTION_TRACK).putExtra("on", on);
        try {
            if (Build.VERSION.SDK_INT >= 26) { c.startForegroundService(i); } else { c.startService(i); }
        } catch (Exception ignored) { }
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
        stopSiren(c);
        NotificationManager nm = (NotificationManager) c.getSystemService(NOTIFICATION_SERVICE);
        if (nm != null) { nm.cancel(ID_NEW); }
    }

    /**
     * La bocina en bucle (cada 3 s, el archivo ya trae el silencio) por el volumen de ALARMA, y vibración a la vez.
     * 1.2 (Ernesto 29-oct): se calla sola a los 30 s, y antes con el botón de volumen, el de encendido, tocando la app,
     * con «Silenciar» o quitando el aviso.
     */
    static synchronized void startSiren(Context c) {
        final Context app = c.getApplicationContext();
        stopSiren(app);
        try {
            android.media.MediaPlayer mp = new android.media.MediaPlayer();
            mp.setAudioAttributes(new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_ALARM).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build());
            mp.setDataSource(app, Uri.parse("android.resource://" + app.getPackageName() + "/" + R.raw.bocina));
            mp.setLooping(true);
            mp.prepare();
            mp.start();
            siren = mp;
        } catch (Exception ignored) { }
        vib = (android.os.Vibrator) app.getSystemService(VIBRATOR_SERVICE);
        long[] pattern = {0, 400, 150, 400, 2050}; // dos vibraciones cada 3 s, como la bocina
        if (vib != null) {
            if (Build.VERSION.SDK_INT >= 26) { vib.vibrate(android.os.VibrationEffect.createWaveform(pattern, 0)); } else { vib.vibrate(pattern, 0); }
        }
        sirenAt = android.os.SystemClock.elapsedRealtime();
        listenKeys(app);
        MAIN.removeCallbacksAndMessages(null);
        MAIN.postDelayed(() -> stopSiren(app), ALARM_MS);
    }

    static synchronized boolean ringing() { return siren != null || sirenAt > 0; }

    static synchronized void stopSiren(Context c) {
        final Context app = c.getApplicationContext();
        MAIN.removeCallbacksAndMessages(null);
        sirenAt = 0;
        if (siren != null) {
            try { siren.stop(); } catch (Exception ignored) { }
            siren.release();
            siren = null;
        }
        if (vib != null) { vib.cancel(); vib = null; }
        android.os.Vibrator v = (android.os.Vibrator) app.getSystemService(VIBRATOR_SERVICE);
        if (v != null) { v.cancel(); }
        if (keys != null) {
            try { app.unregisterReceiver(keys); } catch (Exception ignored) { }
            keys = null;
        }
    }

    /**
     * Mientras suena: el botón de volumen (cambia el volumen → VOLUME_CHANGED) o el de encendido (la pantalla se apaga,
     * o se enciende pasados 2 s: la que enciende el propio aviso no cuenta) la callan. El aviso se queda arriba.
     */
    private static void listenKeys(Context app) {
        keys = new android.content.BroadcastReceiver() {
            @Override public void onReceive(Context ctx, Intent i) {
                String a = i.getAction();
                if (Intent.ACTION_SCREEN_ON.equals(a) && android.os.SystemClock.elapsedRealtime() - sirenAt < 2_000) { return; }
                stopSiren(ctx);
            }
        };
        android.content.IntentFilter f = new android.content.IntentFilter();
        f.addAction(Intent.ACTION_SCREEN_OFF);
        f.addAction(Intent.ACTION_SCREEN_ON);
        f.addAction("android.media.VOLUME_CHANGED_ACTION");
        try {
            if (Build.VERSION.SDK_INT >= 33) { app.registerReceiver(keys, f, Context.RECEIVER_EXPORTED); } else { app.registerReceiver(keys, f); }
        } catch (Exception e) { keys = null; }
    }

    // ------------------------------------------------------------------ Servicio

    @Override
    public void onCreate() {
        super.onCreate();
        channels();
        runNotif = builder(CH_RUN)
            .setContentTitle("Pa'llá")
            .setContentText("Esperando carreras")
            .setOngoing(true)
            .setContentIntent(openApp(null, 0))
            .build();
        foreground(false);
        listenNetwork();
    }

    /** En primer plano: avisos (specialUse) y, si hay recorrido y permiso, también ubicación. */
    private void foreground(boolean location) {
        try {
            if (Build.VERSION.SDK_INT >= 34) {
                startForeground(ID_RUN, runNotif, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE | (location ? ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION : 0));
            } else if (Build.VERSION.SDK_INT >= 29 && location) {
                startForeground(ID_RUN, runNotif, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION);
            } else {
                startForeground(ID_RUN, runNotif);
            }
        } catch (Exception e) {
            if (location) { foreground(false); } // Android no dejó añadir la ubicación (p. ej. desde el fondo): solo avisos
        }
    }

    private boolean hasLocation() {
        return Build.VERSION.SDK_INT < 23 || checkSelfPermission(android.Manifest.permission.ACCESS_FINE_LOCATION) == android.content.pm.PackageManager.PERMISSION_GRANTED
            || checkSelfPermission(android.Manifest.permission.ACCESS_COARSE_LOCATION) == android.content.pm.PackageManager.PERMISSION_GRANTED;
    }

    @SuppressWarnings("MissingPermission")
    private synchronized void startTracking() {
        if (locListener != null || !hasLocation()) { return; }
        android.location.LocationManager lm = (android.location.LocationManager) getSystemService(LOCATION_SERVICE);
        if (lm == null) { return; }
        foreground(true);
        // Clase completa, no lambda: en Android 10 o menos estos métodos no tienen versión por defecto y una lambda fallaría.
        locListener = new android.location.LocationListener() {
            @Override public void onLocationChanged(android.location.Location l) { onLocation(l); }
            @Override public void onStatusChanged(String provider, int status, Bundle extras) { }
            @Override public void onProviderEnabled(String provider) { }
            @Override public void onProviderDisabled(String provider) { }
        };
        for (String provider : new String[]{android.location.LocationManager.GPS_PROVIDER, android.location.LocationManager.NETWORK_PROVIDER}) {
            try { if (lm.isProviderEnabled(provider)) { lm.requestLocationUpdates(provider, 30_000L, 50f, locListener, Looper.getMainLooper()); } } catch (Exception ignored) { }
        }
        uploader = java.util.concurrent.Executors.newSingleThreadScheduledExecutor();
        uploader.scheduleWithFixedDelay(this::upload, 60, 60, java.util.concurrent.TimeUnit.SECONDS);
    }

    private synchronized void stopTracking() {
        if (locListener != null) {
            android.location.LocationManager lm = (android.location.LocationManager) getSystemService(LOCATION_SERVICE);
            try { if (lm != null) { lm.removeUpdates(locListener); } } catch (Exception ignored) { }
            locListener = null;
        }
        if (uploader != null) {
            uploader.shutdown();
            new Thread(this::upload, "palla-recorrido-fin").start(); // lo último que quedó
            uploader = null;
        }
        foreground(false);
    }

    private void onLocation(android.location.Location l) {
        if (l == null || !l.hasAccuracy() || l.getAccuracy() > 150) { return; }
        // 30 s o 50 m desde el último (GPS y red pueden mandar a la vez)
        if (lastLoc != null && l.getTime() - lastLoc.getTime() < 30_000 && l.distanceTo(lastLoc) < 50) { return; }
        lastLoc = l;
        synchronized (AvisosService.class) {
            try {
                org.json.JSONArray a = new org.json.JSONArray(Prefs.trail(this));
                a.put(new JSONObject().put("lat", Math.round(l.getLatitude() * 1e5) / 1e5).put("lon", Math.round(l.getLongitude() * 1e5) / 1e5)
                    .put("acc", Math.round(l.getAccuracy())).put("t", l.getTime() / 1000));
                while (a.length() > 240) { a.remove(0); }
                Prefs.setTrail(this, a.toString());
            } catch (Exception ignored) { }
        }
    }

    /** Manda los puntos guardados; si no hay señal, se quedan para la próxima vez. */
    private void upload() {
        String json;
        int count;
        synchronized (AvisosService.class) {
            json = Prefs.trail(this);
            try { count = new org.json.JSONArray(json).length(); } catch (Exception e) { return; }
        }
        if (count == 0 || Prefs.token(this).isEmpty()) { return; }
        HttpURLConnection h = null;
        try {
            h = (HttpURLConnection) new URL(TRACK_URL).openConnection();
            h.setConnectTimeout(20_000);
            h.setReadTimeout(20_000);
            h.setDoOutput(true);
            h.setRequestMethod("POST");
            h.setRequestProperty("Content-Type", "application/json");
            h.setRequestProperty("Accept", "application/json");
            h.setRequestProperty("X-Palla-Token", Prefs.token(this));
            byte[] body = ("{\"points\":" + json + "}").getBytes(StandardCharsets.UTF_8);
            h.getOutputStream().write(body);
            if (h.getResponseCode() == 200) {
                synchronized (AvisosService.class) {
                    // quitar solo los que se mandaron (pueden haber llegado otros mientras)
                    org.json.JSONArray now = new org.json.JSONArray(Prefs.trail(this)), rest = new org.json.JSONArray();
                    for (int i = count; i < now.length(); i++) { rest.put(now.get(i)); }
                    Prefs.setTrail(this, rest.toString());
                }
            }
        } catch (Exception ignored) {
        } finally {
            if (h != null) { h.disconnect(); }
        }
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (intent != null && (ACTION_STOP.equals(intent.getAction()) || ACTION_SILENCE.equals(intent.getAction()))) { stopSiren(this); } // quitó el aviso o tocó «Silenciar»
        if (!Prefs.ready(this)) { stopSelf(); return START_NOT_STICKY; }
        if (intent != null && ACTION_TRACK.equals(intent.getAction())) {
            if (intent.getBooleanExtra("on", false)) { startTracking(); } else { stopTracking(); }
        } else if (Prefs.active(this)) {
            startTracking(); // p. ej. tras reiniciar el teléfono con una carrera abierta (si Android lo deja)
        }
        if (worker == null || !worker.isAlive()) {
            running = true;
            worker = new Thread(this::loop, "palla-avisos");
            worker.start();
        }
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        stopTracking();
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
            // 1.2: Core dice qué es en los tags (offer, assigned, lost, cancelled, changed, prueba; «o<id>» = la carrera).
            java.util.Set<String> tags = new java.util.HashSet<>();
            org.json.JSONArray t = e.optJSONArray("tags");
            for (int i = 0; t != null && i < t.length(); i++) { tags.add(t.optString(i)); }
            String order = "";
            for (String s : tags) { if (s.matches("o\\d+")) { order = s; } }
            if (tags.contains("cerrada")) { // otro la cogió: si es la que suena, se calla y se quita
                if (!order.isEmpty() && order.equals(alarmOrder)) { stopAlarm(this); alarmOrder = ""; }
                return;
            }
            boolean loud = !(tags.contains("lost") || tags.contains("cancelled") || tags.contains("changed"));
            alarm(e.optString("title", "Pa'llá"), e.optString("message", ""), e.optString("click", ""), loud, order);
        } catch (Exception ignored) { }
    }

    /**
     * El aviso: se ve arriba (sin sonido del canal) con «Silenciar», y la bocina suena aparte (carrera libre, asignada o
     * prueba) hasta 30 s. Lo demás (ya no es tuya, se canceló, cambió) solo vibra una vez.
     */
    private void alarm(String title, String text, String click, boolean loud, String order) {
        NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        if (nm == null) { return; }
        Intent stop = new Intent(this, AvisosService.class).setAction(ACTION_STOP);
        int flags = PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= 23 ? PendingIntent.FLAG_IMMUTABLE : 0);
        PendingIntent onDismiss = Build.VERSION.SDK_INT >= 26 ? PendingIntent.getForegroundService(this, 2, stop, flags) : PendingIntent.getService(this, 2, stop, flags);
        Notification.Builder b = builder(CH_NEW)
            .setContentTitle(title)
            .setContentText(text)
            .setStyle(new Notification.BigTextStyle().bigText(text))
            .setAutoCancel(true)
            .setCategory(Notification.CATEGORY_ALARM)
            .setContentIntent(openApp(click, 1))
            .setDeleteIntent(onDismiss);
        if (loud) {
            Intent silence = new Intent(this, AvisosService.class).setAction(ACTION_SILENCE);
            PendingIntent onSilence = Build.VERSION.SDK_INT >= 26 ? PendingIntent.getForegroundService(this, 3, silence, flags) : PendingIntent.getService(this, 3, silence, flags);
            b.addAction(new Notification.Action.Builder(null, "🔇 Silenciar", onSilence).build());
            b.addAction(new Notification.Action.Builder(null, "Ver carrera", openApp(click, 4)).build());
        }
        if (Build.VERSION.SDK_INT < 26) { b.setPriority(Notification.PRIORITY_MAX); }
        nm.notify(ID_NEW, b.build());
        if (!loud) {
            android.os.Vibrator v = (android.os.Vibrator) getSystemService(VIBRATOR_SERVICE);
            if (v != null) { if (Build.VERSION.SDK_INT >= 26) { v.vibrate(android.os.VibrationEffect.createOneShot(300, android.os.VibrationEffect.DEFAULT_AMPLITUDE)); } else { v.vibrate(300); } }
            return;
        }
        alarmOrder = order;
        // De 22:00 a 7:00 (hora del teléfono) el aviso sale, pero sin bocina: como alarma sonaría aunque esté en silencio.
        int h = java.util.Calendar.getInstance().get(java.util.Calendar.HOUR_OF_DAY);
        if (h >= 7 && h < 22) { startSiren(this); }
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
        nueva.setSound(null, null);       // la bocina la toca startSiren(), no el canal
        nueva.enableVibration(false);     // y la vibración también
        nueva.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
        nm.createNotificationChannel(nueva);
        nm.deleteNotificationChannel("palla_carreras_1"); // el de la 1.0
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
