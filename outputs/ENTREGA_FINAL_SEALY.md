# Entrega final Sealy

Fecha: 2026-06-17

## Archivos principales

- `sealy_servidor_completo_produccion_2026-06-17.zip`: subir y extraer en `public_html`.
- `sealy-produccion-https.apk`: instalar en Android para operacion con HTTPS.

## Rutas esperadas en servidor

```text
public_html/sealy/admin/
public_html/sealy/api/
public_html/sealy/database/
```

URLs:

```text
https://staraz.site/sealy/admin/
https://staraz.site/sealy/api/
```

## Configuracion privada recomendada

Crear fuera de `public_html`:

```text
/home/USUARIO_CPANEL/sealy_config.php
```

Ejemplo:

```php
<?php
return [
  'db_host' => 'localhost',
  'db_name' => 'TU_BASE_DE_DATOS',
  'db_user' => 'TU_USUARIO',
  'db_pass' => 'TU_PASSWORD',
  'cors_origin' => '*',
  'token_ttl_days' => 30,
  'upload_dir' => __DIR__ . '/sealy_uploads',
  'setup_key' => 'TU_CLAVE_SEGURA',
  'allow_destructive_migrations' => false,
];
```

## Instalacion inicial

Ejecutar una sola vez:

```text
https://staraz.site/sealy/api/install_initial.php?key=TU_CLAVE_SEGURA
```

Al terminar correctamente, eliminar o renombrar:

```text
public_html/sealy/api/install_initial.php
```

## Usuarios iniciales

Contrasena inicial:

```text
Cambiar123!
```

Usuarios:

```text
admin@staraz.site
supervisor1@staraz.site
supervisor2@staraz.site
supervisor3@staraz.site
promotor1@staraz.site ... promotor30@staraz.site
```

RFC:

- El RFC es obligatorio en altas y ediciones de usuarios.
- La carga masiva de promotores usa columnas `nombre,email,numero_empleado,rfc,telefono,supervisor,contrasena`.
- Los usuarios se inactivan desde el panel para conservar historico de checadas.

## Validacion local realizada

- App movil configurada con `https://staraz.site/sealy/api`.
- `flutter analyze`: sin errores.
- `flutter build apk --release`: correcto.
- APK release generada correctamente.

Nota: PHP no esta instalado localmente en esta maquina, por lo que la validacion final del backend debe hacerse en GoDaddy ejecutando `install_initial.php` y probando login.
