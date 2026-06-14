# Sealy - Instalacion final en GoDaddy

Este paquete contiene la version inicial de uso para GoDaddy:

- `api/`: backend PHP REST para MySQL.
- `admin/`: panel web para administrador y supervisores.
- `database/staraz_site_bd_completa.sql`: esquema inicial final para phpMyAdmin.
- `database/migrations/`: cambios versionados para actualizar tablas sin borrar datos.

La app movil nativa Flutter usa la API. La app nunca se conecta directo a MySQL.

## Regla principal de tiendas

No existe una relacion promotor-sucursal.

Los promotores se asignan solamente a supervisores. Las tiendas activas son globales: cuando el promotor checa, la app toma su GPS, busca la tienda activa mas cercana y solo permite la checada si esta dentro del radio permitido, normalmente 50 m.

Si el promotor esta sin conexion, la app usa las tiendas activas sincronizadas desde el ultimo login/actualizacion. Si no tiene tiendas cacheadas, debe conectarse una vez para descargarlas.

## Estructura sugerida

Sube las carpetas asi:

```text
public_html/
  api/
  admin/
  database/
```

El archivo SQL puedes importarlo desde phpMyAdmin. Para actualizaciones futuras, conserva tambien `database/migrations/`.
La carpeta `database/` trae `.htaccess` para bloquear acceso web directo.

URLs esperadas:

```text
https://staraz.site/api/
https://staraz.site/admin/
```

## 1. Crear base de datos MySQL

En cPanel/GoDaddy:

1. Crea una base de datos MySQL.
2. Crea un usuario MySQL.
3. Asigna el usuario a la BD con permisos completos.
4. Guarda host, nombre de BD, usuario y contrasena.

## 2. Configurar API de forma segura

Opcion recomendada en GoDaddy:

1. No pongas las credenciales de BD dentro de una carpeta publica si puedes evitarlo.
2. Crea este archivo fuera de `public_html`:

```text
sealy_config.php
```

Ejemplo de ubicacion:

```text
/home/USUARIO_CPANEL/sealy_config.php
/home/USUARIO_CPANEL/public_html/api/
```

La API busca primero `sealy_config.php`. Si no existe, tambien acepta `check50m_config.php` para compatibilidad y luego `api/config.php` como alternativa.

Contenido:

Ejemplo:

```php
<?php
return [
'db_host' => 'localhost',
'db_name' => 'usuario_nombrebd',
'db_user' => 'usuario_db',
'db_pass' => 'password_db',
'cors_origin' => '*',
'token_ttl_days' => 30,
'upload_dir' => __DIR__ . '/sealy_uploads',
'setup_key' => 'CAMBIA_ESTA_CLAVE',
'allow_destructive_migrations' => false,
];
```

Opcion alternativa:

1. Copia `api/config.example.php`.
2. Renombralo como `api/config.php`.
3. Edita los datos de conexion.

El paquete trae `.htaccess` para bloquear descarga de `config.php`, pero la opcion fuera de `public_html` es mas segura.

## 3. Crear tablas y usuarios iniciales

Opcion recomendada con instalador PHP:

```text
https://staraz.site/api/install_initial.php?key=CAMBIA_ESTA_CLAVE
```

Si todo sale bien, veras un JSON con `ok: true`.

Despues de instalar, elimina o renombra `api/install_initial.php`.

Opcion por phpMyAdmin:

```text
database/staraz_site_bd_completa.sql
```

## 3.1. Actualizaciones futuras de base de datos sin perder datos

Cuando el sistema ya este en uso, no vuelvas a importar `staraz_site_bd_completa.sql` sobre una base productiva, porque eso es solo para instalaciones nuevas.

Para cambios futuros de tablas usa:

```text
https://staraz.site/api/migrate.php?key=CAMBIA_ESTA_CLAVE
```

Para revisar que migraciones faltan sin aplicar nada:

```text
https://staraz.site/api/migrate.php?key=CAMBIA_ESTA_CLAVE&dry_run=1
```

El actualizador crea y usa la tabla:

```text
schema_migrations
```

Asi cada cambio se ejecuta una sola vez por base de datos.

Regla importante:

- Proyectos nuevos: usa `install_initial.php` o importa el SQL inicial.
- Proyectos existentes con datos: usa `migrate.php`.
- Antes de cualquier cambio productivo: haz respaldo desde cPanel/phpMyAdmin.
- Las migraciones destructivas (`DROP TABLE`, `TRUNCATE`, `DROP COLUMN`) estan bloqueadas por default.

Si alguna vez se requiere una migracion destructiva, primero haz respaldo y habilita temporalmente en config:

```php
'allow_destructive_migrations' => true,
```

Despues vuelve a dejarlo en `false`.

## 4. Usuarios iniciales

Contrasena inicial para todos:

```text
Cambiar123!
```

Administrador:

```text
admin@staraz.site
```

Supervisores:

```text
supervisor1@staraz.site
supervisor2@staraz.site
supervisor3@staraz.site
```

Promotores:

```text
promotor1@staraz.site
...
promotor30@staraz.site
```

Los supervisores pueden ver todos los promotores. La referencia de supervisor queda solo para filtros historicos.

## 5. Tiendas

No se cargan sucursales de ejemplo. En el panel web entra a `Tiendas` y carga masivamente tiendas reales con Excel o CSV:

```text
cadena,nombre,direccion,latitud,longitud,radio,zona_horaria
Coppel,Sucursal Norte,Av ejemplo 123,19.432608,-99.133209,50,AUTO
```

Recomendado: usa `AUTO` para que el sistema calcule la zona horaria por coordenadas.

Una tienda nueva aparece en la app movil despues de que el promotor actualiza/sincroniza con internet.

## 6. Prueba app movil

1. Instala la APK en el telefono del promotor.
2. Ingresa con un promotor activo creado en el panel.
3. Permite ubicacion y camara.
4. Actualiza tiendas con internet.
5. Checa dentro del radio de una tienda.
6. Si queda alguna checada pendiente por falla de red, entra a `Sync` cuando vuelva el internet y presiona `Sincronizar ahora`.

## 7. APK y Play Store

Para uso interno de validacion se genera APK. Para Google Play se debe generar un `.aab` firmado:

```text
flutter build appbundle --release
```

Para App Store se requiere compilar y firmar desde macOS con Xcode.
