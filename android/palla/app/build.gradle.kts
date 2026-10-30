plugins {
    id("com.android.application")
}

// Firma: la llave NO está en el repo (D:\palla-firma, copia en el VPS /root/archivos/palla/firma). Sin ella: solo debug.
val firma = file((project.findProperty("palla.firma") as String?) ?: "D:/palla-firma")

android {
    namespace = "company.casaviva.palla"
    compileSdk = 36

    defaultConfig {
        applicationId = "company.casaviva.palla"
        minSdk = 24
        targetSdk = 36
        versionCode = 4
        versionName = "1.3" // 29-oct noche: 🟢 Disponible / ⚪ No disponible (desconectado se apaga del todo; se apaga sola a su hora). Antes, 1.2: la bocina se calla con volumen, encendido, «Silenciar», tocando o quitando el aviso; 30 s como mucho; si otro coge la carrera, se calla sola
    }

    signingConfigs {
        create("release") {
            if (file("$firma/palla-release.jks").exists()) {
                storeFile = file("$firma/palla-release.jks")
                storePassword = file("$firma/clave.txt").readText().trim()
                keyAlias = "palla"
                keyPassword = file("$firma/clave.txt").readText().trim()
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.getByName("release")
        }
    }

    buildFeatures { buildConfig = true }

    // Sin la revisión «lint» al compilar: baja cientos de MB y en este ordenador se corta la descarga.
    lint { checkReleaseBuilds = false }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}
