# Migraciones de base de datos

Este directorio es para cambios futuros de estructura sin borrar datos.

## Regla

- Nunca edites datos productivos directo sin respaldo.
- Cada cambio nuevo debe ir en un archivo SQL nuevo dentro de `migrations/`.
- El nombre debe iniciar con fecha y consecutivo:

```text
YYYYMMDDNNNN_descripcion.sql
```

Ejemplo:

```text
202606150001_add_store_region.sql
```

## Como se aplica

Sube los archivos al hosting y abre:

```text
https://TU_DOMINIO/TU_RUTA/api/migrate.php?key=TU_SETUP_KEY
```

Para revisar sin aplicar:

```text
https://TU_DOMINIO/TU_RUTA/api/migrate.php?key=TU_SETUP_KEY&dry_run=1
```

El sistema crea la tabla `schema_migrations` y ejecuta solo archivos que no se hayan aplicado antes.

## Seguridad

Por default se bloquean migraciones con operaciones destructivas:

- `DROP TABLE`
- `TRUNCATE`
- `DROP COLUMN`

Si alguna vez se necesita algo destructivo, primero debe hacerse respaldo y habilitarse temporalmente en config:

```php
'allow_destructive_migrations' => true,
```

Despues de aplicar, vuelve a dejarlo en `false` o elimina esa opcion.
