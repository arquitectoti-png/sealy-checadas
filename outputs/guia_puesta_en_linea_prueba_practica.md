# Guia para poner el piloto en linea y probarlo en uso real

## Objetivo

Subir el piloto a GoDaddy para probar:

- App movil web/PWA desde un celular.
- API conectada a MySQL.
- Web administrativa.
- Login por roles.
- Checada con foto y GPS.
- Validacion de radio de tienda.
- Modo offline y sincronizacion.

## Archivos que vas a usar

Paquete principal:

```text
outputs/godaddy_prueba.zip
```

Ese ZIP contiene:

```text
api/
mobile/
admin/
README_INSTALACION.md
```

## 1. Subir a GoDaddy

En cPanel o administrador de archivos de GoDaddy:

1. Entra a `public_html`.
2. Sube `godaddy_prueba.zip`.
3. Extrae el ZIP dentro de `public_html`.
4. Debe quedar asi:

```text
public_html/api/
public_html/mobile/
public_html/admin/
```

URLs esperadas:

```text
https://staraz.site/api/
https://staraz.site/mobile/
https://staraz.site/admin/
```

## 2. Crear la base de datos MySQL

En GoDaddy/cPanel:

1. Crea una base de datos MySQL.
2. Crea un usuario MySQL.
3. Asigna el usuario a la base con todos los permisos.
4. Guarda:
   - Host.
   - Nombre de BD.
   - Usuario.
   - Contrasena.

En hosting compartido, el host normalmente es:

```text
localhost
```

## 3. Configurar API

En `public_html/api/`:

1. Copia `config.example.php`.
2. Renombra la copia como `config.php`.
3. Edita `config.php`.

Ejemplo:

```php
return [
    'db_host' => 'localhost',
    'db_name' => 'TU_BASE_DE_DATOS',
    'db_user' => 'TU_USUARIO_DB',
    'db_pass' => 'TU_PASSWORD_DB',
    'cors_origin' => '*',
    'token_ttl_days' => 30,
    'upload_dir' => __DIR__ . '/uploads',
    'setup_key' => 'cambia-esta-clave-123'
];
```

## 4. Instalar tablas y usuarios demo

Abre en el navegador:

```text
https://staraz.site/api/install_demo.php?key=cambia-esta-clave-123
```

Debe responder algo parecido a:

```json
{
  "ok": true,
  "message": "Demo database installed"
}
```

Despues de instalar, elimina:

```text
public_html/api/install_demo.php
```

## 5. Probar que la API este viva

Abre:

```text
https://staraz.site/api/
```

Debe responder:

```json
{
  "ok": true,
  "service": "check50m-api"
}
```

## 6. Probar app movil web desde celular

Abre desde el celular:

```text
https://staraz.site/mobile/
```

En `API URL`, escribe:

```text
https://staraz.site/api
```

Usuario demo:

```text
staff@demo.com
```

Contrasena:

```text
demo1234
```

Permite:

- Ubicacion.
- Camara.

Haz una checada.

## 7. Probar web admin

Abre:

```text
https://staraz.site/admin/
```

En `API URL`, escribe:

```text
https://staraz.site/api
```

Usuario admin demo:

```text
admin@demo.com
```

Contrasena:

```text
demo1234
```

Debe mostrar:

- Dashboard.
- Tiendas.
- Usuarios.

## 8. Ajustar ubicacion real de tienda

El instalador crea una tienda demo con coordenadas de ejemplo. Para probar en uso real, cambia las coordenadas a la ubicacion donde estas probando.

Puedes hacerlo desde phpMyAdmin en la tabla:

```text
stores
```

Campos:

```text
latitude
longitude
allowed_radius_meters
```

Ejemplo:

```text
allowed_radius_meters = 50
```

Para obtener coordenadas:

1. Abre Google Maps.
2. MantÃ©n presionado el punto exacto de la tienda.
3. Copia latitud y longitud.
4. Pegalas en `stores.latitude` y `stores.longitude`.

## 9. Prueba practica recomendada

### Prueba A: checada valida

1. Pon las coordenadas de la tienda donde estas fisicamente.
2. Abre `https://staraz.site/mobile/`.
3. Entra con `staff@demo.com`.
4. Selecciona fase `Ingreso`.
5. Toma foto.
6. Permite GPS.
7. Debe registrar la checada como valida.
8. Entra a `https://staraz.site/admin/`.
9. Revisa el dashboard.

### Prueba B: fuera de rango

1. Cambia las coordenadas de la tienda a un punto lejano.
2. Intenta checar desde el celular.
3. Debe bloquear o marcar fuera de rango.

### Prueba C: offline

1. Entra a la app movil con internet.
2. Apaga datos o activa modo avion.
3. Haz una checada.
4. Debe guardarse como pendiente.
5. Vuelve a activar internet.
6. Presiona `Sincronizar ahora`.
7. Revisa la web admin.

## 10. Cuando ya funcione la prueba web

El siguiente paso es generar APK con Flutter.

La app Flutter debe apuntar a:

```dart
const defaultApiBaseUrl = 'https://staraz.site/api';
```

Despues:

```powershell
flutter pub get
flutter run
flutter build apk --debug
```

APK de prueba:

```text
build/app/outputs/flutter-apk/app-debug.apk
```

## Errores comunes

### Error: Missing api/config.php

Falta copiar `config.example.php` como `config.php`.

### Error de conexion MySQL

Revisar:

- Nombre de BD.
- Usuario.
- Contrasena.
- Host.
- Permisos del usuario.

### La app no pide camara o GPS

En navegador movil:

- Revisa permisos del sitio.
- Asegurate de estar usando `https://`, no `http://`.

### La foto no sube

Revisar que exista o se pueda crear:

```text
public_html/api/uploads/
```

Si GoDaddy bloquea escritura, crea manualmente la carpeta `uploads` y dale permisos de escritura.

### La checada sale fuera de rango

Revisar coordenadas de tienda en tabla `stores`.

### El admin no ve cambios

Refresca dashboard o revisa que la checada no haya sido rechazada por duplicado de fase.

## Datos demo

```text
staff@demo.com / demo1234
admin@demo.com / demo1234
supervisor@demo.com / demo1234
```

