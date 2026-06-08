# Regla de tienda cercana en app movil

## Cambio aplicado

La app movil ya no depende de una tienda fija asignada al promotor para permitir checar.

Ahora el flujo es:

1. El administrador crea o modifica tiendas en la web.
2. La API entrega a la app movil todas las tiendas activas.
3. La app guarda esas tiendas localmente para poder operar offline.
4. Al momento de checar, la app obtiene GPS.
5. La app calcula la tienda mas cercana.
6. Si la tienda mas cercana esta dentro del radio permitido, por defecto 50 m, permite checar.
7. Si ninguna tienda esta dentro del radio, bloquea la checada.
8. El backend vuelve a validar distancia y tienda activa antes de guardar.

## Implicacion

Un promotor puede checar en cualquier tienda activa siempre que este fisicamente dentro del radio permitido de esa tienda.

## Actualizacion de tiendas en movil

Cuando se da de alta una tienda en el panel admin:

- En linea: la app movil la recibe al actualizar o al volver a abrir sesion.
- Offline: la app usa la lista cacheada anterior hasta que vuelva internet y sincronice.

## Seguridad

La validacion se hace dos veces:

- En la app: para dar respuesta inmediata al promotor.
- En el servidor: para evitar registros manipulados.

Ademas se mantiene:

- Bloqueo de ubicacion simulada cuando Android lo reporta.
- Bloqueo de GPS impreciso.
- Revision por hora sospechosa contra hora del servidor.
