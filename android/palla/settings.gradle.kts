// Pa'llá · app de Android de los mensajeros de Casa Viva (27-oct). Abre la misma web /palla/ y avisa con la app cerrada
// por el ntfy del VPS (sin Google). Sin librerías externas: solo Android.
pluginManagement {
    repositories { google(); mavenCentral(); gradlePluginPortal() }
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories { google(); mavenCentral() }
}
rootProject.name = "palla"
include(":app")
