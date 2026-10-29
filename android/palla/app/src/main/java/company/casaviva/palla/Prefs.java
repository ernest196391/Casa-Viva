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

    /** 1.1: ¿tiene carreras abiertas? (lo dice la web); con carreras, la app manda el recorrido. */
    static boolean active(Context c) { return p(c).getBoolean("active", false); }
    static void setActive(Context c, boolean a) { p(c).edit().putBoolean("active", a).apply(); }

    /** 1.1: puntos del recorrido sin mandar (JSON), para cuando vuelva la señal. */
    static String trail(Context c) { return p(c).getString("trail", "[]"); }
    static void setTrail(Context c, String json) { p(c).edit().putString("trail", json).apply(); }

    static boolean batteryAsked(Context c) { return p(c).getBoolean("battery", false); }
    static void setBatteryAsked(Context c) { p(c).edit().putBoolean("battery", true).apply(); }
}
