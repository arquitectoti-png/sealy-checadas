# Sistema de checadas con foto, ubicacion y roles

## Objetivo

Crear un sistema compuesto por dos productos:

1. Una app movil multiplataforma para iOS y Android, enfocada en el personal de tienda.
2. Una version web administrativa, enfocada en supervisores y administrador general.

Ambos productos compartiran backend, base de datos, login, roles y reglas de permisos. La app movil permitira registrar checadas diarias en tienda usando foto obligatoria y ubicacion GPS. El sistema debe validar que la persona este dentro de un rango de 50 metros de la tienda asignada antes de permitir cada registro valido.

## Roles

### Personal de tienda

Funciones principales:

- Iniciar sesion.
- Ver las tiendas o ruta asignada.
- Hacer 4 checadas diarias:
  - Ingreso.
  - Salida a comer.
  - Entrada de comer.
  - Salida.
- Tomar foto obligatoria en cada checada.
- Enviar ubicacion GPS actual.
- Ver historial propio de checadas por dia.
- Ver estado del dia: pendiente, completo, fuera de rango o con incidencia.

### Supervisor

Funciones principales:

- Ver personal asignado.
- Revisar avance de checadas del dia.
- Identificar quien no ha checado.
- Ver checadas fuera de rango o intentos bloqueados.
- Consultar tiendas asignadas.
- Revisar historial del personal supervisado.

### Administrador general

Funciones principales:

- Todo lo del supervisor.
- Crear, editar y desactivar usuarios.
- Cambiar contrasenas o enviar recuperacion.
- Asignar roles: administrador, supervisor o personal.
- Asignar personal a supervisores.
- Crear, editar y eliminar/desactivar tiendas.
- Definir coordenadas de cada tienda y radio permitido.
- Ver dashboard global diario.
- Descargar reportes de checadas.

## Modulos de la aplicacion

### Division por producto

App movil:

- Login de personal.
- Perfil.
- Checador diario.
- Mis registros.
- Estado de sincronizacion.
- Cola offline de checadas pendientes.

Web administrativa:

- Login de supervisores y administradores.
- Dashboard.
- Supervision de personal.
- Tiendas.
- Usuarios y accesos.
- Reportes.
- Incidencias.

### 1. Autenticacion

- Login con correo/telefono y contrasena.
- Recuperacion de contrasena.
- Control de sesion.
- Validacion de rol para mostrar modulos.

### 2. Checador diario

Disponible para personal de tienda.

Flujo:

1. Usuario selecciona tienda asignada o el sistema detecta tienda esperada.
2. App solicita permiso de ubicacion.
3. App obtiene latitud y longitud actual.
4. App calcula distancia contra coordenadas de la tienda.
5. Si esta dentro de 50 metros, permite tomar foto.
6. Usuario toma foto.
7. Sistema registra checada con fecha, hora, fase, tienda, foto, ubicacion y distancia.
8. Si esta fuera del rango, bloquea el check y guarda intento como incidencia.

Fases del dia:

- `ingreso`
- `salida_comer`
- `entrada_comer`
- `salida`

Reglas:

- No permitir saltar fases, salvo que un administrador autorice una correccion.
- No permitir repetir una fase completada sin autorizacion.
- Requerir foto nueva tomada desde camara, no desde galeria.
- Guardar evidencia de intentos fuera de rango.

### 3. Mis registros

Disponible para personal.

- Calendario o lista por fecha.
- Detalle de cada checada.
- Foto registrada.
- Tienda, hora, distancia y estado.
- Indicador de dia completo o incompleto.

### 4. Supervision

Disponible para supervisores y administradores.

- Lista del personal asignado.
- Estado diario por persona:
  - Sin iniciar.
  - Ingreso registrado.
  - En comida.
  - Regreso de comida.
  - Jornada completa.
  - Fuera de rango.
  - Incidencia.
- Filtros por tienda, fecha, supervisor y estado.
- Detalle de cada registro con foto y mapa.

### 5. Tiendas

Disponible para administradores; opcionalmente lectura para supervisores.

- Crear tienda.
- Editar nombre, direccion, coordenadas y radio permitido.
- Desactivar tienda.
- Ver mapa de tiendas.
- Asignar personal a tienda o ruta.

Campos clave:

- Nombre de tienda.
- Direccion.
- Latitud.
- Longitud.
- Radio permitido en metros, por defecto 50.
- Estado activo/inactivo.

### 6. Usuarios y accesos

Disponible para administradores.

- Crear usuarios.
- Editar datos.
- Asignar rol.
- Asignar supervisor.
- Asignar tiendas.
- Cambiar o resetear contrasena.
- Activar/desactivar acceso.

### 7. Dashboard global

Disponible para administradores.

Vista diaria con:

- Total de personal activo.
- Cuantos hicieron ingreso.
- Cuantos completaron las 4 fases.
- Cuantos tienen checadas incompletas.
- Cuantos intentaron checar fuera de rango.
- Checadas por tienda.
- Checadas por supervisor.
- Ultimas incidencias.

### 8. Reportes

Disponible para administradores y supervisores segun permisos.

Exportables:

- Excel/CSV por rango de fechas.
- Reporte por empleado.
- Reporte por tienda.
- Reporte por supervisor.
- Reporte de incidencias fuera de rango.
- Reporte de dias incompletos.

Columnas sugeridas:

- Fecha.
- Usuario.
- Rol.
- Supervisor.
- Tienda.
- Fase.
- Hora.
- Latitud.
- Longitud.
- Distancia a tienda.
- Estado.
- URL o referencia de foto.
- Observaciones.

## Modelo de datos propuesto

### users

- id
- full_name
- email
- phone
- role: `admin`, `supervisor`, `staff`
- supervisor_id
- status: `active`, `inactive`
- created_at
- updated_at

### stores

- id
- name
- address
- latitude
- longitude
- allowed_radius_meters
- status
- created_at
- updated_at

### user_store_assignments

- id
- user_id
- store_id
- starts_at
- ends_at
- status

### check_records

- id
- user_id
- store_id
- phase
- checked_at
- latitude
- longitude
- distance_meters
- within_range
- photo_url
- device_id
- status: `valid`, `blocked_out_of_range`, `manual_adjustment`, `flagged`
- notes
- created_at

### check_attempts

- id
- user_id
- store_id
- phase
- attempted_at
- latitude
- longitude
- distance_meters
- within_range
- reason
- device_id

### supervisor_assignments

- id
- supervisor_id
- staff_id
- status
- created_at

### audit_logs

- id
- actor_user_id
- action
- entity_type
- entity_id
- metadata_json
- created_at

## Regla de distancia

Para validar si el usuario esta dentro del rango de 50 metros se debe usar la formula Haversine:

```text
distancia = distancia_geografica(usuario_lat, usuario_lng, tienda_lat, tienda_lng)

si distancia <= radio_permitido:
  permitir check
si distancia > radio_permitido:
  bloquear check y registrar intento
```

El radio debe ser configurable por tienda, aunque el valor por defecto sera 50 metros.

## Seguridad y privacidad

La app manejara datos sensibles: ubicacion, fotos, horario laboral y datos de empleados. Requisitos minimos:

- Politica de privacidad clara antes de publicar.
- Consentimiento de uso de ubicacion y camara.
- Explicar que la foto se usa como evidencia de asistencia.
- Cifrado en transito con HTTPS.
- Reglas de acceso por rol.
- Fotos almacenadas en storage privado.
- Logs de auditoria para cambios de usuarios, tiendas y ajustes manuales.
- No usar reconocimiento facial automatico en el MVP salvo que se agreguen consentimientos y controles adicionales.

## Tecnologia recomendada

### App movil

Opcion recomendada: Flutter.

Ventajas:

- Una sola base para iOS y Android.
- Buen acceso a camara y ubicacion.
- Buen rendimiento.
- Adecuado para publicacion en App Store y Play Store.

### Backend

Opciones recomendadas:

- Supabase: rapido para MVP, base PostgreSQL, auth, storage y API.
- Firebase: rapido para login, storage y notificaciones.
- Backend propio: Node/NestJS o Django/FastAPI si se requiere control total.

Para este proyecto recomiendo Supabase o Firebase para acelerar el lanzamiento inicial.

## MVP sugerido

Primera version publicable:

- Login.
- Roles basicos.
- Checador con 4 fases.
- Validacion de rango 50 metros.
- Foto obligatoria.
- Historial personal.
- Vista de supervisor.
- Gestion de usuarios y tiendas para administrador.
- Dashboard diario.
- Exportacion CSV.

Version 2:

- Notificaciones push para checadas pendientes.
- Correcciones con aprobacion.
- Mapa avanzado de tiendas.
- Reportes PDF.
- Reconocimiento facial opcional con consentimiento explicito.
- Modo offline con sincronizacion posterior.

## Publicacion en App Store y Play Store

Materiales necesarios:

- Nombre de la app.
- Icono.
- Capturas de pantalla.
- Descripcion corta y larga.
- Politica de privacidad publicada en web.
- Terminos de uso si aplica.
- Cuentas de prueba para revision.
- Declaracion de permisos de camara y ubicacion.
- Clasificacion de contenido.

Permisos que se justificaran:

- Camara: tomar foto obligatoria durante checada.
- Ubicacion precisa: validar que el empleado este dentro del rango autorizado de la tienda.
- Internet: enviar registros, fotos y reportes.

## Preguntas pendientes para cerrar alcance

- Nombre de la empresa o app.
- Si el personal tendra una tienda fija o ruta diaria de varias tiendas.
- Si habra horarios definidos por empleado.
- Si se permitiran correcciones manuales.
- Si se requiere modo offline.
- Si los reportes deben salir en Excel, PDF o ambos.
- Si el login sera con correo, telefono o numero de empleado.
