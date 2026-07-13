# Promosoluciones - paquete para App Store / TestFlight

Fecha local: 2026-07-05

## Build

- App: Promosoluciones
- Bundle ID: `com.promosoluciones.checadas`
- Version: `0.1.4`
- Build: `10`
- Plataforma preparada: iPhone
- API productiva: `https://staraz.site/sealy/api`
- Politica de privacidad: `https://staraz.site/sealy/admin/politicas/`
- Team Apple detectado en Xcode: `6N6XUW5K99`

## Artefactos locales

- App iOS compilada sin firma: `work/check50m_piloto/build/ios/iphoneos/Runner.app`
- IPA no firmada, solo evidencia tecnica: `outputs/ios_unsigned/promosoluciones_0.1.4_5_unsigned.ipa`
- IPA firmada para App Store Connect/TestFlight: `outputs/ios_appstore/promosoluciones_0.1.4_10_appreview_2026-07-12.ipa`
- Capturas App Store iPhone 6.5 aceptadas por App Store Connect: `outputs/app_store_ios_promosoluciones_iphone_65_1284x2778_2026-07-05/`
- Capturas originales Play Store: `outputs/play_store_real_final_promosoluciones_2026-06-29/`
- Icono iOS 1024: `work/check50m_piloto/ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-1024x1024@1x.png`
- Respaldo de iconos iOS originales con alpha: `outputs/app_icon_ios_original_alpha_backup_2026-07-05/`
- Icono Play Store 512: `outputs/promosoluciones_icono_app_512.png`
- PDF politica: `outputs/politica_privacidad_promosoluciones_2026-06-29.pdf`

## Estado de firma y subida

La IPA firmada ya se genero correctamente para App Store Connect.

Verificacion local:

- Bundle ID: `com.promosoluciones.checadas`
- Version: `0.1.4`
- Build: `10`
- Team: `6N6XUW5K99`
- Perfil: `iOS Team Store Provisioning Profile: com.promosoluciones.checadas`
- Firma: `Apple Distribution: Rafael Rojas Aguilar (6N6XUW5K99)`

Nota del icono:

- El build `5` se habia subido correctamente, pero el App Icon iOS conservaba canal alpha.
- Se aplano el set completo de iconos iOS a PNG opaco.
- Verificacion del icono marketing: `1024x1024`, `hasAlpha: no`.

Nota privacidad iOS:

- El build `6` fue rechazado con error `90683` por faltar `NSLocationAlwaysAndWhenInUseUsageDescription`.
- Se agrego `NSLocationAlwaysAndWhenInUseUsageDescription` en `ios/Runner/Info.plist`.
- Verificacion del build `7`: contiene `NSLocationWhenInUseUsageDescription`, `NSLocationAlwaysAndWhenInUseUsageDescription` y `NSCameraUsageDescription`.
- El build `8` agrega `ITSAppUsesNonExemptEncryption=false` para indicar que la app no usa encriptacion no exenta y evitar el flujo de CCATS cuando Apple lo detecte desde el binario.
- Los builds `9` y `10` agregan un modo aislado para App Review con tienda virtual de 50 m, contenido sintetico, sesiones temporales y descarte inmediato de selfies de prueba. El build `10` tambien elimina la sesion del servidor al cerrar sesion desde la app.

Subida a App Store Connect:

- Estado: subida exitosa.
- Hora local build 5: 2026-07-05 20:12.
- Hora local build 6 corregido: 2026-07-05 20:21.
- Hora local build 7 privacidad corregida: 2026-07-05 20:27.
- Hora local build 8 export compliance corregido: 2026-07-07 16:01.
- Hora local build 9 modo App Review: 2026-07-12 19:00.
- Hora local build 10 modo App Review con cierre remoto: 2026-07-12 19:07.
- Resultado Xcode: `Upload succeeded. Uploaded Runner`.

El build corregido `10` puede tardar algunos minutos en aparecer como disponible mientras Apple lo procesa. Usar el build `10` para TestFlight y para la version de App Store.

## Para que aparezca el icono en App Store Connect

El icono de la app no se sube en la seccion de capturas. App Store Connect lo toma del build que se sube desde Xcode.

Pasos:

1. Esperar a que Apple termine de procesar el build `0.1.4 (10)`.
2. Ir a App Store Connect > Apps > Promosoluciones > TestFlight > iOS y confirmar que aparece el build `10`.
3. Ir a Distribucion > iOS > version en preparacion.
4. En la seccion Build, seleccionar el build `0.1.4 (10)`.
5. Guardar.

Al guardar el build seleccionado, App Store Connect debe mostrar el icono de la app. Si no aparece de inmediato, esperar unos minutos y recargar la pagina.

Comando usado para subir:

```bash
cd /Users/starazagora/Documents/sealy-checadas/work/check50m_piloto
xcodebuild -exportArchive \
  -archivePath build/ios/archive/Runner_0.1.4_10_appreview.xcarchive \
  -exportPath build/ios/upload_appstore_0.1.4_10_appreview \
  -exportOptionsPlist /Users/starazagora/Documents/sealy-checadas/outputs/ExportOptions_AppStoreConnect_Upload.plist \
  -allowProvisioningUpdates
```

## Revision Apple

El build `10` ya incluye el modo aislado solicitado por Apple. Antes de reenviar:

1. Ejecutar manualmente la migracion `202607120001_add_app_review_sessions.sql` en phpMyAdmin.
2. Reemplazar solamente `public_html/sealy/api/index.php` y activar las entradas de App Review en la configuracion privada.
3. No ejecutar `install_initial.php` ni `migrate.php` en produccion.
4. Probar el login y una checada con las credenciales privadas guardadas fuera del repositorio.
5. Capturar las mismas credenciales en Beta App Review Information.
6. Seleccionar el build `10` y responder al mensaje de revision.

Verificacion productiva del 2026-07-12: API, login de revision, tienda virtual de
50 m, aviso sintetico, checada de prueba, historial y cierre de sesion respondieron
correctamente. La sesion se elimino al terminar la prueba.

## Metadatos sugeridos

Nombre:

```text
Promosoluciones
```

Subtitulo:

```text
Checadas con foto y GPS
```

Categoria sugerida:

```text
Business
```

Texto promocional:

```text
Registro de asistencia para promotores con foto, ubicacion y sincronizacion.
```

Descripcion:

```text
Promosoluciones permite a promotores registrar sus checadas laborales desde el celular usando fotografia obligatoria y ubicacion GPS.

La app valida la tienda activa mas cercana, registra las fases de la jornada, conserva registros pendientes cuando no hay conexion y sincroniza la informacion al recuperar internet.

Funciones principales:
- Inicio de sesion para promotores.
- Checadas de ingreso, comida, regreso y salida.
- Foto obligatoria como evidencia.
- Validacion de ubicacion dentro del radio autorizado.
- Historial de registros.
- Cola offline y sincronizacion.
- Cambio de contrasena.
- Avisos internos para promotores.

El acceso esta destinado a personal autorizado de Promosoluciones.
```

Keywords:

```text
checadas,asistencia,promotores,gps,foto,tiendas,supervision,offline
```

Copyright:

```text
Copyright © 2026 Promosoluciones
```

URL de soporte sugerida:

```text
https://staraz.site/sealy/admin/politicas/
```

## Privacidad sugerida

La app no usa publicidad ni tracking.

Declarar datos vinculados al usuario para funcionalidad de la app:

- Nombre.
- Email o usuario de acceso.
- Numero de empleado/RFC si se muestra o se usa en cuenta.
- Identificador de usuario.
- Identificador de dispositivo.
- Ubicacion precisa.
- Fotos tomadas durante la checada.
- Registros de asistencia y horarios.

Usos:

- App Functionality.
- Account Management.
- Security/Fraud Prevention, si se declara por validacion de GPS falso, duplicados y auditoria.

Permisos:

- Camera: evidencia fotografica de asistencia.
- Precise Location: validar presencia dentro del radio autorizado de tienda.

## TestFlight

Flujo recomendado:

1. Subir build firmado a App Store Connect.
2. Esperar procesamiento.
3. Agregar internal testers para prueba rapida.
4. Crear grupo external testers si se requiere distribuir fuera del equipo.
5. Enviar a Beta App Review para external testers.
6. Compartir link publico o invitar por correo despues de aprobacion beta.
