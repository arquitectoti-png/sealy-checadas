# Arquitectura usando hosting GoDaddy

## Resumen

Si ya tienes hosting en GoDaddy, se puede usar para alojar:

- La version web administrativa.
- La API del sistema.
- La base de datos MySQL.
- Opcionalmente las fotos, aunque es mejor almacenarlas en un storage separado si el volumen crece.

La app movil no debe conectarse directamente a MySQL. La app movil y la web deben conectarse a una API por HTTPS.

```text
App movil personal
  |
  | HTTPS
  v
API en tu dominio
  |
  v
Base de datos MySQL en GoDaddy

Web supervisor/admin
  |
  | HTTPS
  v
API en tu dominio
  |
  v
Base de datos MySQL en GoDaddy
```

## Por que no conectar la app directo a la BD

No es recomendable que la app movil se conecte directo a MySQL porque:

- Las credenciales de la BD quedarian dentro de la app.
- Un usuario podria extraer esas credenciales del APK o IPA.
- No habria control fino de permisos por rol.
- Seria mas dificil bloquear usuarios o sesiones.
- La app podria intentar escribir datos incorrectos o duplicados.
- App Store y Play Store esperan comunicacion segura por HTTPS.
- El modo offline necesita una sincronizacion controlada, no escritura directa.

## Componentes recomendados

### 1. Base de datos

Si tu hosting GoDaddy es cPanel/shared hosting, lo mas comun es usar MySQL o MariaDB.

La BD guardara:

- Usuarios.
- Roles.
- Tiendas.
- Asignaciones.
- Checadas.
- Intentos fuera de rango.
- Eventos de sincronizacion.
- Auditoria.

### 2. API backend

La API sera el puente entre app/web y la base de datos.

Opciones viables en GoDaddy:

- PHP puro con estructura REST.
- Laravel si tu plan permite Composer y version de PHP compatible.
- Node.js solo si tu plan de GoDaddy lo soporta.

Para maxima compatibilidad con hosting compartido, la opcion mas segura es PHP + MySQL. Si el plan permite Laravel, Laravel seria mejor para seguridad, estructura y mantenimiento.

### 3. Web administrativa

Se puede publicar en:

- `https://staraz.site/admin`
- `https://staraz.site/admin`

La web no debe hablar directo con MySQL. Igual que la app, debe usar la API.

### 4. App movil

La app movil se publicara en App Store y Google Play.

Cuando tenga internet:

- Hace login contra la API.
- Descarga tiendas asignadas.
- Descarga fases ya completadas.
- Envia checadas.
- Sincroniza registros pendientes.

Cuando no tenga internet:

- Usa sesion cacheada.
- Usa tiendas cacheadas.
- Guarda checadas pendientes localmente.
- Sincroniza al recuperar conexion.

## Dominios sugeridos

Opcion simple:

- Web admin: `https://staraz.site/admin`
- API: `https://staraz.site/api`

Opcion mas ordenada:

- Web admin: `https://staraz.site/admin`
- API: `https://staraz.site/api`

La opcion con subdominios es mas limpia, pero requiere configurar DNS/subdominios en GoDaddy.

## Flujo de login

```text
Usuario ingresa correo/telefono y contrasena
  |
App o web envia POST /api/auth/login
  |
API valida credenciales
  |
API responde token seguro + datos del usuario + rol
  |
Frontend muestra modulos segun rol
```

Reglas:

- App movil solo permite rol `staff`.
- Web permite `supervisor` y `admin`.
- La API siempre valida permisos, aunque el frontend oculte botones.

## Flujo de checada online

```text
Personal presiona "Tomar foto y checar"
  |
App obtiene GPS
  |
App calcula distancia preliminar
  |
App toma foto
  |
App envia POST /api/checks
  |
API recalcula distancia en servidor
  |
API valida fase, tienda, usuario y radio permitido
  |
API guarda registro valido o incidencia
```

## Flujo de checada offline

```text
Personal no tiene datos
  |
App usa tiendas cacheadas
  |
App obtiene GPS
  |
App calcula distancia contra tienda cacheada
  |
Si esta dentro del rango, guarda foto y checada en cola local
  |
Cuando vuelve internet, app envia POST /api/checks/sync
  |
API revalida distancia, duplicados y orden de fases
  |
API marca como sincronizado, observado o rechazado
```

## Seguridad minima

- Dominio con SSL activo: `https://`.
- Contrasenas con hash seguro, nunca texto plano.
- Tokens de sesion con expiracion.
- Validacion de permisos en cada endpoint.
- Fotos protegidas, no publicas sin control.
- Limites de tamano de foto.
- Auditoria de cambios de tiendas, roles y usuarios.
- Recalculo de distancia en backend.
- Bloqueo de checadas duplicadas por usuario, fecha y fase.

## Publicacion

La web administrativa se sube al hosting GoDaddy.

La app movil se conecta al dominio de API configurado, por ejemplo:

```text
API_BASE_URL=https://staraz.site/api
```

Para pruebas se puede usar:

```text
API_BASE_URL=https://staraz.site/api
```

## Recomendacion final

Si tu GoDaddy es hosting compartido:

- BD: MySQL.
- API: PHP/Laravel o PHP REST simple.
- Web admin: HTML/React compilado o plantilla PHP.
- App movil: Flutter.

Si tu GoDaddy es VPS:

- BD: MySQL o PostgreSQL.
- API: Node.js/NestJS o Laravel.
- Web admin: React/Next.js.
- App movil: Flutter.

