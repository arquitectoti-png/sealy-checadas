# Arquitectura: app movil + web administrativa + modo offline

## Decision principal

El sistema debe tener dos interfaces separadas:

1. App movil para personal de tienda.
2. Web administrativa para supervisores y administrador general.

Ambas interfaces usaran el mismo backend, la misma base de datos, el mismo login y las mismas reglas de permisos.

## App movil para personal

Objetivo: permitir que el personal haga sus checadas diarias en campo con foto, GPS y validacion de tienda.

Modulos:

- Login.
- Perfil del usuario.
- Tienda o ruta asignada.
- Checador diario con 4 fases:
  - Ingreso.
  - Salida a comer.
  - Entrada de comer.
  - Salida.
- Historial propio.
- Estado de sincronizacion.
- Bandeja de registros pendientes por enviar.

Permisos necesarios:

- Camara.
- Ubicacion precisa.
- Internet.
- Almacenamiento local seguro para modo offline.

Reglas:

- El personal no podra ver modulos de administracion.
- El personal solo vera sus propias checadas.
- Cada checada requiere ubicacion GPS y foto tomada en el momento.
- Si el empleado esta fuera del radio permitido, la app no registra una checada valida.
- Si esta offline, la app puede guardar la evidencia localmente, pero debe marcarla como pendiente de sincronizacion.

## Web para supervisores

Objetivo: permitir que el supervisor vea el avance de su equipo y detecte incidencias.

Modulos:

- Login.
- Dashboard de equipo.
- Personal asignado.
- Checadas del dia.
- Incidencias fuera de rango.
- Historial del personal supervisado.
- Tiendas asignadas en modo lectura o edicion limitada, segun permisos.
- Exportacion de reportes del equipo.

Reglas:

- Un supervisor solo ve personal asignado a el.
- Puede filtrar por tienda, fecha, empleado y estado.
- Puede ver evidencia de foto y ubicacion.
- No puede crear administradores.
- No puede cambiar roles globales.

## Web para administrador general

Objetivo: controlar configuracion, usuarios, tiendas, roles, reportes y dashboard global.

Modulos:

- Login.
- Dashboard global diario.
- Personal, supervisores y administradores.
- Alta y baja de usuarios.
- Cambio o reset de contrasenas.
- Asignacion de roles.
- Asignacion de personal a supervisores.
- Alta, edicion y desactivacion de tiendas.
- Configuracion de radio por tienda.
- Reportes descargables.
- Auditoria de cambios.

Reglas:

- El administrador puede ver todo el sistema.
- El administrador puede asignar roles.
- El administrador puede descargar reportes globales.
- Las eliminaciones deben manejarse como desactivacion, no borrado fisico, para conservar historial.

## Login y perfiles

Una sola tabla de usuarios, con roles separados.

Roles:

- `admin`
- `supervisor`
- `staff`

Flujo:

1. Usuario ingresa email, telefono o numero de empleado y contrasena.
2. Backend valida credenciales.
3. Backend devuelve token de sesion y rol.
4. App movil solo permite acceso si el rol es `staff`.
5. Web administrativa permite acceso a `supervisor` y `admin`.
6. Cada pantalla valida permisos en frontend y backend.

Importante: aunque se oculten menus en la interfaz, la proteccion real debe estar en el backend.

## Modo offline para personal

El modo offline aplica principalmente a la app movil del personal.

### Que se puede hacer offline

- Abrir sesion si el usuario ya habia iniciado sesion antes.
- Ver datos cacheados:
  - Perfil.
  - Tiendas asignadas.
  - Fases pendientes del dia.
- Capturar una checada con:
  - Fecha y hora del dispositivo.
  - Ubicacion GPS.
  - Foto.
  - Tienda seleccionada o asignada.
  - Distancia calculada.
- Guardar la checada en una cola local pendiente.

### Que no se debe hacer offline

- Crear usuarios.
- Cambiar contrasenas.
- Cambiar roles.
- Agregar tiendas.
- Descargar reportes globales.
- Aprobar correcciones.

### Reglas de seguridad offline

- La app debe guardar localmente una copia de las tiendas asignadas y sus coordenadas.
- La app debe calcular distancia aunque no tenga internet, usando coordenadas guardadas.
- Si no hay GPS disponible, no debe permitir una checada valida.
- Si el dispositivo cambia la hora de forma sospechosa, marcar la checada como `flagged`.
- Al volver internet, la app sincroniza la cola local.
- El backend recalcula distancia y valida contra la tienda antes de aceptar.
- Si la checada offline llega tarde, debe mostrar `sincronizada con retraso`.

### Estados de una checada

- `valid`
- `pending_sync`
- `synced`
- `blocked_out_of_range`
- `offline_flagged`
- `manual_review`
- `rejected`

## Flujo offline recomendado

```text
Usuario abre app movil
  |
  |-- Hay internet?
      |-- Si: login normal, actualizar tiendas y fases
      |-- No: usar sesion y datos cacheados
  |
Usuario intenta checar
  |
App obtiene GPS
  |
App calcula distancia contra tienda cacheada
  |
Distancia <= 50 m?
  |-- No: bloquear y guardar intento fuera de rango
  |-- Si: pedir foto
  |
Hay internet?
  |-- Si: enviar al backend
  |-- No: guardar en cola local como pending_sync
  |
Cuando vuelve internet:
  app envia cola local
  backend valida y guarda
```

## Base de datos adicional para offline

### sync_queue

Tabla local en el dispositivo, no necesariamente en el backend.

- local_id
- user_id
- store_id
- phase
- captured_at_device
- latitude
- longitude
- distance_meters
- photo_local_path
- device_id
- status
- retry_count
- last_error

### device_sessions

Tabla en backend.

- id
- user_id
- device_id
- last_login_at
- last_sync_at
- app_version
- status

### sync_events

Tabla en backend.

- id
- user_id
- device_id
- event_type
- records_count
- created_at
- metadata_json

## Tecnologia recomendada

### App movil

Recomendacion: Flutter.

Paquetes comunes:

- Camara.
- Geolocalizacion.
- SQLite local o Hive para cola offline.
- Secure storage para sesion.
- HTTP client para sincronizacion.

### Web administrativa

Recomendacion: React, Next.js o Flutter Web.

Para un panel administrativo, React/Next.js suele ser mas comodo por tablas, reportes y dashboards.

### Backend

Si ya existe hosting en GoDaddy, se puede usar una arquitectura con MySQL + API propia.

Recomendacion para GoDaddy:

- Base de datos MySQL o MariaDB.
- API en PHP/Laravel si el hosting lo permite.
- Web administrativa publicada en el mismo dominio o subdominio.
- App movil conectada a la API por HTTPS.

Importante: la app movil no debe conectarse directamente a MySQL. Debe conectarse a una API, por ejemplo `https://staraz.site/api` o `https://staraz.site/api`.

Otras opciones:

- Supabase: mejor si se quiere acelerar con auth, storage y APIs listas.
- Firebase: util si se priorizan notificaciones y ecosistema movil.

Para usar tu infraestructura actual, GoDaddy + MySQL + API es viable.

## Publicacion

La app movil se publicaria en:

- App Store.
- Google Play.

La web administrativa se publicaria en:

- Dominio propio, por ejemplo `admin.tuempresa.com`.
- Acceso privado con login.

La web administrativa no necesita publicarse en App Store ni Play Store.

## Alcance MVP actualizado

### Version movil 1

- Login staff.
- Perfil.
- Tiendas asignadas.
- Checador 4 fases.
- Foto obligatoria.
- GPS obligatorio.
- Validacion de 50 m.
- Cola offline.
- Sincronizacion al recuperar internet.
- Historial propio.

### Version web 1

- Login supervisor/admin.
- Dashboard por rol.
- Personal asignado.
- Estado diario de checadas.
- Incidencias fuera de rango.
- CRUD de tiendas para admin.
- CRUD de usuarios para admin.
- Reporte CSV.

## Riesgos a controlar

- Usuarios intentando checar con ubicacion falsa.
- Dispositivo sin GPS.
- Cambio manual de hora.
- Fotos tomadas desde galeria en lugar de camara.
- Checadas duplicadas al sincronizar.
- Personal sin internet por varias horas.
- Politicas de privacidad por uso de foto y ubicacion.

## Siguiente decision necesaria

Definir backend:

- Supabase: mejor para reportes, tablas y rapidez de MVP.
- Firebase: mejor si se prioriza ecosistema movil y notificaciones.

Mi recomendacion inicial: Supabase.

