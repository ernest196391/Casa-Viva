package company.casaviva.palla;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Al encender el teléfono (o al actualizar la app) vuelve a escuchar los avisos, sin tener que abrir Pa'llá. */
public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        if (Prefs.ready(context)) { AvisosService.start(context); }
    }
}
