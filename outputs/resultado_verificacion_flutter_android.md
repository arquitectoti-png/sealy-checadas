# Resultado de verificacion Flutter / Android

## Resultado

Se confirmo que Flutter y Android Studio estan instalados.

Flutter no estaba disponible con el comando `flutter` porque no esta en el `PATH` de esta terminal, pero VSCode tiene configurada esta ruta:

```text
C:\Users\Usuario\Documents\Git\flutter
```

Git tambien esta instalado, pero no esta en `PATH`:

```text
C:\Program Files\Git\cmd\git.exe
```

Android Studio esta instalado en:

```text
C:\Program Files\Android\Android Studio\bin\studio64.exe
```

Android SDK esta instalado en:

```text
C:\Users\Usuario\AppData\Local\Android\Sdk
```

ADB existe en:

```text
C:\Users\Usuario\AppData\Local\Android\Sdk\platform-tools\adb.exe
```

## Flutter doctor

Flutter doctor detecto:

- Flutter 3.44.1 stable.
- Dart 3.12.1.
- Android SDK 36.1.0.
- Android Studio instalado.
- Falta Android SDK Command-line Tools.
- Falta aceptar licencias Android.

## Proyecto piloto creado

Proyecto real generado:

```text
work/check50m_piloto
```

Se agregaron permisos Android:

- Internet.
- Camara.
- Ubicacion fina.
- Ubicacion aproximada.

Se agregaron textos de permisos iOS:

- Camara.
- Ubicacion en uso.

## Verificaciones ejecutadas

```text
flutter analyze
```

Resultado:

```text
No issues found.
```

```text
flutter test
```

Resultado:

```text
All tests passed.
```

## APK generado

Se genero APK debug con Gradle directo. La version inicial usaba HTTPS.

```text
work/check50m_piloto/build/app/outputs/flutter-apk/app-debug.apk
```

Copia entregable:

```text
outputs/check50m_piloto_app-debug.apk
```

Tambien se preparo una version de piloto en linea usando:

```text
http://staraz.site/api
```

Esto es temporal para prueba practica, porque `https://staraz.site/api` presenta problema de certificado SSL y responde 500.

## Nota sobre build

El comando `flutter build apk --debug` se quedo esperando demasiado en esta sesion, pero Gradle directo si genero el APK correctamente:

```powershell
cd work/check50m_piloto/android
.\gradlew.bat assembleDebug --no-daemon --stacktrace
```

## Pendiente recomendado

Para que Flutter doctor quede completo:

1. Abre Android Studio.
2. Ve a SDK Manager.
3. Instala Android SDK Command-line Tools.
4. Ejecuta:

```powershell
$env:PATH = "C:\Program Files\Git\cmd;C:\Users\Usuario\Documents\Git\flutter\bin;C:\Users\Usuario\AppData\Local\Android\Sdk\platform-tools;$env:PATH"
flutter doctor --android-licenses
flutter doctor -v
```

Tambien conviene agregar permanentemente al PATH:

```text
C:\Program Files\Git\cmd
C:\Users\Usuario\Documents\Git\flutter\bin
C:\Users\Usuario\AppData\Local\Android\Sdk\platform-tools
```
