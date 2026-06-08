# Contrato inicial de API

Base URL sugerida:

```text
https://staraz.site/api
```

Alternativa en un solo dominio:

```text
https://staraz.site/api
```

Todas las rutas privadas deben recibir:

```text
Authorization: Bearer <token>
```

## Autenticacion

### POST /auth/login

Inicia sesion para app movil o web.

Request:

```json
{
  "login": "ana.lopez@empresa.com",
  "password": "12345678",
  "client_type": "mobile"
}
```

Response:

```json
{
  "token": "jwt_o_token_seguro",
  "user": {
    "id": 10,
    "full_name": "Ana Lopez",
    "role": "staff"
  }
}
```

Reglas:

- `client_type=mobile` solo acepta rol `staff`.
- `client_type=web` acepta `supervisor` y `admin`.

### POST /auth/logout

Cierra sesion.

### POST /auth/reset-password

Solicita recuperacion o cambio de contrasena.

## Perfil y configuracion movil

### GET /me

Devuelve datos del usuario autenticado.

### GET /me/mobile-bootstrap

Devuelve la informacion necesaria para operar online/offline.

Response:

```json
{
  "user": {
    "id": 10,
    "full_name": "Ana Lopez",
    "role": "staff"
  },
  "assigned_stores": [
    {
      "id": 1,
      "name": "Tienda Centro",
      "latitude": 19.432608,
      "longitude": -99.133209,
      "allowed_radius_meters": 50
    }
  ],
  "today_checks": [
    {
      "phase": "ingreso",
      "checked_at": "2026-06-04T08:58:00-06:00",
      "status": "valid"
    }
  ]
}
```

La app movil debe cachear esta respuesta para poder operar sin internet.

## Checadas

### POST /checks

Registra una checada online.

Request:

```json
{
  "store_id": 1,
  "phase": "salida_comer",
  "captured_at_device": "2026-06-04T13:05:00-06:00",
  "latitude": 19.432700,
  "longitude": -99.133100,
  "device_id": "device-uuid",
  "offline": false,
  "photo_base64": "base64_o_multipart"
}
```

Response valido:

```json
{
  "status": "valid",
  "check_id": 2001,
  "distance_meters": 37,
  "message": "Checada registrada"
}
```

Response fuera de rango:

```json
{
  "status": "blocked_out_of_range",
  "distance_meters": 62,
  "allowed_radius_meters": 50,
  "message": "Estas fuera del rango permitido"
}
```

### POST /checks/sync

Sincroniza checadas capturadas offline.

Request:

```json
{
  "device_id": "device-uuid",
  "items": [
    {
      "local_id": "local-001",
      "store_id": 1,
      "phase": "salida_comer",
      "captured_at_device": "2026-06-04T13:05:00-06:00",
      "latitude": 19.432700,
      "longitude": -99.133100,
      "distance_meters_client": 37,
      "photo_base64": "base64_o_multipart"
    }
  ]
}
```

Response:

```json
{
  "synced": [
    {
      "local_id": "local-001",
      "server_id": 2002,
      "status": "synced"
    }
  ],
  "rejected": []
}
```

Reglas:

- El backend recalcula distancia.
- El backend evita duplicados por usuario, fecha, tienda y fase.
- Si detecta hora sospechosa, marca `manual_review`.
- Si ya existe la fase, responde duplicado.

### GET /checks/my?start=YYYY-MM-DD&end=YYYY-MM-DD

Historial del usuario autenticado.

## Supervision

### GET /supervisor/team-status?date=YYYY-MM-DD

Devuelve estado diario del personal asignado al supervisor.

### GET /supervisor/incidents?start=YYYY-MM-DD&end=YYYY-MM-DD

Devuelve incidencias del equipo.

## Administracion

### GET /admin/dashboard?date=YYYY-MM-DD

Dashboard global.

### GET /admin/users

Lista usuarios.

### POST /admin/users

Crea usuario.

### PATCH /admin/users/{id}

Edita usuario, rol, supervisor, estado o datos.

### POST /admin/users/{id}/reset-password

Resetea contrasena o genera enlace.

### GET /admin/stores

Lista tiendas.

### POST /admin/stores

Crea tienda.

### PATCH /admin/stores/{id}

Edita tienda.

### DELETE /admin/stores/{id}

Desactiva tienda. No debe borrar historial.

### GET /admin/reports/checks.csv

Descarga reporte CSV.

Parametros:

```text
start=YYYY-MM-DD
end=YYYY-MM-DD
store_id=opcional
supervisor_id=opcional
status=opcional
```

## Estados recomendados

Checada:

- `valid`
- `pending_sync`
- `synced`
- `blocked_out_of_range`
- `manual_review`
- `rejected`

Intento:

- `out_of_range`
- `gps_unavailable`
- `duplicate_phase`
- `invalid_order`
- `device_time_suspicious`

## Fases

- `ingreso`
- `salida_comer`
- `entrada_comer`
- `salida`

