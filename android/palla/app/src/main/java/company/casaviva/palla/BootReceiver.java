package company.casaviva.palla;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Al encender el teléfono (o al actualizar la app) vuelve a escuchar los avisos, sin tener que abrir Pa'llá. */
public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        if (Prefs.needsService(context)) { AvisosService.start(context); } // 1.3: desconectado, no arranca al encender
    }
}
