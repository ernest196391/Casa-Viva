package company.casaviva.palla;

import android.content.Context;
import android.content.SharedPreferences;

/** Lo que la app recuerda: el enlace (token) del mensajero, su tema de avisos y cómo va la conexión. */
final class Prefs {
    private Prefs() {}

    private static SharedPreferences p(Context c) { return c.getSharedPreferences("palla", Context.MODE_PRIVATE); }

    static String token(Context c) { return p(c).getString("token", ""); }
    static void setToken(Context c, String t) { p(c).edit().putString("token", t == null ? "" : t.trim()).apply(); }

    static String server(Context c) { return p(c).getString("server", ""); }
    static String topic(Context c) { return p(c).getString("topic", ""); }
    static void setServer(Context c, String url, String topic) { p(c).edit().putString("server", url).putString("topic", topic).apply(); }
    static void clearServer(Context c) { p(c).edit().remove("server").remove("topic").apply(); }
    static boolean ready(Context c) { return !server(c).isEmpty() && !topic(c).isEmpty(); }

    static String lastId(Context c) { return p(c).getString("last", ""); }
    static void setLastId(Context c, String id) { p(c).edit().putString("last", id).apply(); }

    /** Texto corto para Ayuda: «conectado», «sin señal, reintentando», «no disponible»… */
    static String status(Context c) { return p(c).getString("status", ""); }
    static void setStatus(Context c, String s) { p(c).edit().putString("status", s).apply(); }

    static boolean batteryAsked(Context c) { return p(c).getBoolean("battery", false); }
    static void setBatteryAsked(Context c) { p(c).edit().putBoolean("battery", true).apply(); }
}
