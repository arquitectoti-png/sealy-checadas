# Capacidad y concurrencia para 10 admins, 30 supervisores y 1500 empleados

## Respuesta corta

El sistema no tiene una limitante funcional por cantidad de usuarios para:

- 10 administradores.
- 30 supervisores.
- 1500 empleados.

La limitante real seria la infraestructura donde se publique, principalmente si se usa hosting compartido de GoDaddy.

## Carga esperada

Cada empleado tiene 4 checadas al dia:

```text
1500 empleados x 4 checadas = 6000 checadas diarias
```

En base de datos, 6000 registros diarios no es un problema grande para MySQL si hay indices correctos.

Lo pesado no son las filas de BD. Lo pesado es:

- Subida de fotos.
- Picos de checada a la misma hora.
- Dashboard consultando muchos datos.
- Reportes por rango de fechas.
- Almacenamiento mensual de evidencias.

## Fotos y almacenamiento

Si cada foto pesa aproximadamente:

```text
300 KB x 6000 fotos = 1.8 GB diarios
700 KB x 6000 fotos = 4.2 GB diarios
1 MB x 6000 fotos = 6 GB diarios
```

Al mes:

```text
1.8 GB diarios x 30 = 54 GB mensuales
4.2 GB diarios x 30 = 126 GB mensuales
6 GB diarios x 30 = 180 GB mensuales
```

Por eso para produccion conviene:

- Comprimir foto en app.
- Limitar resolucion.
- Guardar fotos como archivo, no dentro de MySQL.
- Considerar storage separado si el volumen crece.
- Definir politica de retencion, por ejemplo 3, 6 o 12 meses.

## Concurrencia esperada

El caso mas fuerte seria cuando muchos empleados checan al mismo tiempo.

Ejemplo:

```text
1500 empleados intentando ingreso entre 8:55 y 9:05
```

Eso genera:

- Muchas peticiones de login/bootstrap si todos abren app al mismo tiempo.
- Muchas subidas de foto.
- Muchas escrituras en `check_records`.

El modo offline ayuda porque:

- Si no hay datos, el empleado no se bloquea.
- La app guarda localmente.
- Sincroniza despues.
- Se reduce presion de red en campo.

Pero si todos estan en linea y suben foto a la vez, el hosting debe aguantarlo.

## GoDaddy hosting compartido

Para piloto:

- Si sirve.
- Bueno para validar flujo.
- Bueno para probar con algunos usuarios.
- Bueno para demo interna.

Para 1500 empleados en operacion real:

- Puede quedarse corto.
- Puede limitar procesos PHP.
- Puede limitar conexiones MySQL.
- Puede ser lento subiendo fotos.
- Puede fallar en picos de entrada/salida.

## Recomendacion de infraestructura

### Piloto

GoDaddy compartido + PHP + MySQL esta bien.

Objetivo:

- Validar app.
- Validar GPS.
- Validar fotos.
- Validar roles.
- Validar reportes basicos.

### Produccion inicial

Recomendado:

- VPS o hosting administrado con recursos garantizados.
- MySQL/MariaDB con indices y backups.
- API PHP/Laravel o Node.
- Storage para fotos separado o disco con buen espacio.
- SSL valido.
- Monitoreo de errores.

### Produccion con 1500 empleados

Recomendado:

- VPS 4 vCPU / 8 GB RAM como punto de partida.
- MySQL optimizado.
- Almacenamiento para fotos dimensionado.
- Backups automaticos.
- CDN o storage externo para evidencias si crece.
- Jobs/colas para procesar sincronizaciones offline.

## Ajustes necesarios antes de produccion

- Corregir SSL de `https://staraz.site/api`.
- Cambiar app de HTTP a HTTPS.
- Enviar fotos como multipart, no base64 JSON.
- Paginacion en reportes.
- Filtros obligatorios por fecha.
- Indices en `check_records` por fecha, tienda, usuario, supervisor y estado.
- Limite de tamano de foto.
- Compresion de imagen en app.
- Auditoria de cambios.
- Backups de BD y fotos.
- Politica de retencion de fotos.

## Conclusion

Como esta hoy, el proyecto sirve para piloto y prueba practica.

Para 10 admins, 30 supervisores y 1500 empleados, la arquitectura logica esta bien, pero no recomendaria operar produccion final en hosting compartido sin pruebas de carga y sin optimizar fotos, HTTPS, almacenamiento y reportes.
