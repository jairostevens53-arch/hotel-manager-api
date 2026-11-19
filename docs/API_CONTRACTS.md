# API para la app móvil

Base de producción: `https://hotel-manager-api-ttg7.onrender.com/api`.

Las rutas privadas requieren `Authorization: Bearer <token>`, `Accept: application/json` y, al enviar JSON, `Content-Type: application/json`. El token vence a los 30 días por defecto; el valor se puede cambiar con `SANCTUM_EXPIRATION` (minutos).

## Sesión

- `POST /login`: `{ "email": "...", "password": "...", "device_name": "flutter" }`. Responde `{ "token": "...", "user": { "id": 1, "name": "...", "email": "...", "role": "admin" } }`.
- `GET /me`: devuelve los datos del usuario del token.
- `POST /logout`: revoca el token actual.

Roles válidos: `admin`, `recepcionista`, `limpieza`, `mantenimiento`.

## Huéspedes

Solo `admin` y `recepcionista`:

- `GET /guests?q=ana&per_page=20`: busca por nombre, documento, teléfono o correo; devuelve paginación.
- `POST /guests`: `{ "name": "Ana Pérez", "document": "...", "phone": "...", "email": "...", "notes": "..." }`.
- `GET /guests/{id}`, `PUT/PATCH /guests/{id}`: consulta y actualiza.
- `DELETE /guests/{id}`: archiva si no tiene reservas ni estadías históricas.

## Reservas

Solo `admin` y `recepcionista`:

- `GET /reservations?status=confirmada&from=2026-12-01&to=2027-01-01&room_id=2`.
- `POST /reservations`: requiere `guest_id`, `room_id`, `rate_id`, `check_in_at` y `expected_checkout_at`; opcionalmente `guests` y `notes`. El precio se toma de la tarifa actual y se guarda como precio acordado.
- `GET /reservations/{id}`.
- `POST /reservations/{id}/cancel`.
- `POST /reservations/{id}/check-in`: requiere `payment_method` (`efectivo`, `tarjeta` o `transferencia`) y admite `items` con `product_id` y `quantity`. Convierte la reserva en estadía y conserva la tarifa y el precio acordado.

La API rechaza cruces de horario con reservas confirmadas o estadías activas para la misma habitación.

## Tareas

- `GET /tasks`: administración/recepción ve las tareas; limpieza y mantenimiento solo ven las asignadas a su usuario.
- `POST /tasks` (admin/recepción): `{ "room_id": 2, "type": "limpieza", "priority": "normal", "description": "...", "assigned_to": 8 }`. `assigned_to` es opcional y debe tener el rol que corresponde al tipo.
- `PATCH /tasks/{id}/assign` (admin/recepción): `{ "assigned_to": 8 }`.
- `PATCH /tasks/{id}/status`: `{ "status": "en_progreso" }`, después `completada` o `cancelada`; `result` es opcional. Personal de limpieza/mantenimiento solo puede actualizar tareas propias y asignadas.

Al completar limpieza o mantenimiento, la habitación pasa a disponible. El check-out crea automáticamente una tarea pendiente de limpieza.

## Inventario

- `GET /inventory/movements?product_id=4&type=salida_venta&per_page=20` (admin).
- El stock inicial, ajustes manuales, compras, ventas, consumos de estadía y anulaciones generan movimientos con cantidad firmada: entradas positivas y salidas negativas.

El historial comienza con estos cambios; no se reconstruyen movimientos anteriores a la migración.

## Otros endpoints existentes

Productos, categorías, habitaciones, tarifas, estadías, turnos, ventas y gastos mantienen sus rutas actuales. Las operaciones de caja, ventas, gastos y estadías están disponibles para administración/recepción; limpieza y mantenimiento solo tienen acceso operativo a habitaciones y tareas.

La API todavía no implementa un libro separado de facturas/pagos parciales ni aislamiento por varios hoteles. Esos contratos deben definirse antes de conectar esas funciones del cliente.
