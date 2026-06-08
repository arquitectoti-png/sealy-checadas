# Ruta para generar APK de prueba

## Estado de esta maquina

En esta maquina se detecto:

- Java: instalado.
- Flutter: no instalado.
- Gradle: no instalado.
- ADB/Android SDK: no instalado.
- PHP local: no instalado.

Por eso no se puede compilar un APK real desde aqui todavia.

## Que se puede probar ya

Con el paquete `godaddy_prueba` puedes probar:

- API en GoDaddy.
- Base MySQL.
- Login.
- Cliente movil web/PWA desde celular.
- Foto requerida.
- GPS.
- Modo offline.
- Sincronizacion.
- Web admin demo.

Esto sirve para validar el flujo antes de construir el APK final.

## Para generar APK

Necesitamos instalar:

- Flutter SDK.
- Android Studio o Android command line tools.
- Android SDK Platform.
- Android SDK Build Tools.
- Gradle/Android Gradle Plugin.

Despues:

```text
flutter doctor
flutter pub get
flutter build apk --release
```

APK de prueba:

```text
build/app/outputs/flutter-apk/app-release.apk
```

Para Google Play no se sube APK, se recomienda AAB:

```text
flutter build appbundle --release
```

Archivo para Play Store:

```text
build/app/outputs/bundle/release/app-release.aab
```

## Para App Store

La version iOS debe compilarse en macOS con Xcode:

```text
flutter build ipa --release
```

No se puede generar IPA de App Store desde Windows.

## Siguiente paso recomendado

Primero sube `godaddy_prueba` a GoDaddy y prueba el flujo web/PWA con datos demo. Cuando eso funcione, se convierte el cliente movil a Flutter y se genera el APK apuntando a:

```text
https://staraz.site/api
```

