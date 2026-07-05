# Contexto de trabajo - Promosoluciones / Sealy

Este repositorio contiene el piloto de checadas con foto y GPS para promotores, panel web para administradores/supervisores y una web app provisional para promotores en iPhone.

## Rutas principales

- API GoDaddy: `outputs/godaddy_prueba/api/`
- Panel administrador/supervisor: `outputs/godaddy_prueba/admin/`
- Web app provisional promotor: `outputs/godaddy_prueba/admin/promotor/`
- Flutter app Android/iOS: `work/check50m_piloto/`
- Scripts de layouts Excel: `scripts/`

## Rutas en producción

- API: `https://staraz.site/sealy/api`
- Panel web: `https://staraz.site/sealy/admin/`
- Web app promotor iPhone provisional: `https://staraz.site/sealy/admin/promotor/`
- Política de privacidad: `https://staraz.site/sealy/admin/politicas/`

## Estado funcional relevante

- La app móvil usa `/sealy/api`.
- La web app de promotor calcula la ruta del API desde `/admin/`, por lo que apunta a `/sealy/api`.
- Las fotos se suben comprimidas en JPEG.
- Las fotos de checadas se consultan por endpoint protegido, no por ruta pública directa.
- La cola offline se conserva localmente y los rechazados se ocultan después de 48 horas.
- La hora final de checada la controla el backend, respetando zona horaria congelada por tienda/registro.
- Los promotores tienen RFC obligatorio.
- Las tiendas tienen cadena y zona horaria.

## Flutter

Directorio:

```powershell
cd work/check50m_piloto
```

Comandos comunes:

```powershell
flutter pub get
flutter build appbundle --release
flutter build apk --release
```

Versión Android actual en `pubspec.yaml`:

```yaml
version: 0.1.4+5
```

`versionCode` debe subir en cada carga nueva a Google Play.

## Archivos no versionados intencionalmente

No se deben subir a Git:

- `.aab`, `.apk`, `.ipa`
- `android/key.properties`
- llaves `.jks` / `.keystore`
- configuraciones privadas del API
- uploads/fotos reales

La llave de firma Android y sus credenciales viven fuera del repo localmente.

## Artefactos versionados como contexto

- Capturas finales para Play Store: `outputs/play_store_real_final_promosoluciones_2026-06-29/`
- PDF de política de privacidad: `outputs/politica_privacidad_promosoluciones_2026-06-29.pdf`
- Render de revisión del PDF: `outputs/politica_privacidad_promosoluciones_render/`
- Icono para Play Store: `outputs/promosoluciones_icono_app_512.png`

## Despliegue web en GoDaddy

Para subir todo desde cero al servidor:

1. Copiar `outputs/godaddy_prueba/api/` hacia `/sealy/api/`.
2. Copiar `outputs/godaddy_prueba/admin/` hacia `/sealy/admin/`.
3. Mantener configuración privada del API fuera de Git.
4. Ejecutar migraciones si hay cambios de base de datos.

Para subir solo la web app provisional de promotor:

1. Copiar `outputs/godaddy_prueba/admin/promotor/`.
2. Colocarla en `/sealy/admin/promotor/`.

## Git

Repositorio remoto:

```text
https://github.com/arquitectoti-png/sealy-checadas.git
```

Rama principal:

```text
main
```
