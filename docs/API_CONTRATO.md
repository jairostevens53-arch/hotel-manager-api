# Contrato actual de la API Hotel Manager

Este inventario se levantó de `routes/api.php`, controladores, modelos y migraciones actuales del backend. Base de rutas: `/api`; las rutas salvo `POST /login` requieren Sanctum (`Authorization: Bearer <token>`). Los listados paginados usan el formato Laravel `{data, current_page, last_page, per_page, total, ...}`. Los listados no paginados devuelven arreglos JSON; escrituras devuelven el registro actualizado o creado (`201` en creación). Errores de validación usan `422` con `message` y `errors`; reglas de negocio usan `409`/`403`.

## Rutas y permisos

| Apartado | Método y ruta | Roles | Campos de escritura / respuesta notable |
|---|---|---|---|
| Sesión | `POST /login`, `POST /logout`, `GET /me` | público / autenticado | Login: `email`, `password`, opcional `device_name`; devuelve `token` y `user {id,name,email,role}`. Roles: `admin`, `recepcionista`, `limpieza`, `mantenimiento`. |
| Habitaciones | `GET /rooms`, `PATCH /rooms/{room}/status` | Los cuatro roles | Lista `{summary, rooms}`; `only_active`, `floor`; cuarto: `id,number,floor,type,beds,status,active,notes`, relaciones `current_stay`. Tipos `estandar/especial`; estados `disponible/ocupada/limpieza/mantenimiento`. Cambio: `status`; personal operativo solo libera su estado de tarea. |
| Catálogo de cuartos | `POST /rooms`, `PUT /rooms/{room}` | Admin | Crear: `number,floor,type,beds,notes`; actualizar esos campos y `active`. |
| Huéspedes | `GET/POST /guests`, `GET/PUT/PATCH/DELETE /guests/{guest}` | Admin, recepción | Búsqueda `q`, paginación `per_page` (1–100). Campos `name,document,phone,email,notes`; borrar archiva, y devuelve `409` si existe historial. |
| Tarifas | `GET /rates`, `POST /rates`, `PUT /rates/{rate}` | Lectura: admin/recepción; escritura: admin | `name,hours,price,extra_hour_price,room_type,active`; tipo opcional `estandar/especial`. `GET` retorna activas por defecto. |
| Reservas | `GET/POST /reservations`, `GET /reservations/{reservation}`, `POST .../payments`, `/cancel`, `/check-in` | Admin, recepción | Crear: `guest_id,room_type,beds,rate_id,rate_units,check_in_at,expected_checkout_at,guests,notes`. Respuesta incluye relaciones huésped/cuarto/tarifa y `paid_amount,balance`; estados `pendiente,confirmada,vencida,cancelada,convertida`. Abono: `amount,payment_method`. Los listados aceptan `status,room_id,from,to,per_page`; asignador automático de habitación. |
| Estadías | `GET/POST /stays`, `GET /stays/{stay}`, `POST .../extend`, `/charge`, `/checkout` | Admin, recepción | Crear directo: `room_id,rate_id,rate_units` y `guest_id` o `guest_name`; también `guest_document,guests,payment_method,notes,items[{product_id,quantity}]`. Extender `hours,payment_method`; cargo `amount` o `items`, `description,pay_now,payment_method`; checkout `payment_method`. El backend multiplica importe y duración por `rate_units`. |
| Categorías | `GET/POST /categories`, `GET/PUT/PATCH/DELETE /categories/{category}` | Lectura admin/recepción; escritura admin | `name`; eliminación devuelve `409` si tiene productos. |
| Productos/inventario | `GET/POST /products`, `GET /products/{product}`, `PUT/PATCH/DELETE /products/{product}` | Lectura admin/recepción; escritura admin | `category_id,name,price,stock,min_stock,active`; filtro `q,category_id,only_active`. Borrar desactiva. Stock inicial/ajustes generan `inventory_movements`. |
| Movimientos de inventario | `GET /inventory/movements` | Admin | Paginado; campos modelo `product_id,user_id,type,quantity,unit_cost,reason,reference_type,reference_id`, relaciones producto/usuario. |
| Compras/proveedores | `GET/POST /purchases`; `GET/POST/PUT suppliers` | Admin | Compra: `supplier_id?`, `items[{product_id,quantity,unit_cost}]`; servidor calcula total, aumenta stock y registra movimientos. Proveedor: `name,phone`. |
| Ventas | `GET/POST /sales`, `GET /sales/{sale}`, `POST /sales/{sale}/cancel` | Admin/recepción | Crear: `payment_method,items[{product_id,quantity}]`; precio/total los calcula API. Turno abierto requerido. Lista paginada; recepción ve sus ventas; anulación repone stock solo mientras el turno siga abierto. |
| Turnos | `GET /shifts/current`, `POST /shifts/open`, `/close`; `GET /shifts`, `GET /shifts/{shift}` | Propio: admin/recepción; listado/detalle admin | Abrir `opening_amount`; cerrar `counted_amount,notes`; respuesta `{shift,summary}`. Listado paginado con filtros `user_id,only_closed,with_difference`. |
| Gastos | `GET/POST /expenses` | Admin/recepción | Crear `description,amount`; turno abierto requerido. Paginado y filtrable por `shift_id`; recepción ve gastos propios. |
| Reportes | `GET /reports/dashboard` | Admin | `days` (1–90); respuesta `period,totals,sales_by_day,sales_by_method,top_products,low_stock,open_shifts`. |
| Tareas | `GET/POST /tasks`, `PATCH /tasks/{task}/assign`, `/status` | Lectura todos (operativos solo asignadas); crear/asignar admin/recepción; cambio por responsable o gerente | Crear: `room_id,type,priority,description,assigned_to`; tipos `limpieza/mantenimiento`, prioridades `baja/normal/alta/urgente`. Asignar: `assigned_to`. Estado: `en_progreso/completada/cancelada`, opcional `result`; transiciones controladas. |
| Usuarios | `GET/POST /users`, `PUT /users/{user}` | Admin | Crear: `name,email,password,role`; actualizar `name,email,password?,role,active`; roles del sistema. |

## Modelos persistidos relevantes

- `Room`: `number,floor,type,beds,status,active,notes`.
- `Rate`: `name,hours,price,extra_hour_price,room_type,active`.
- `Guest`: `name,document,phone,email,notes`.
- `Reservation`: huésped/cuarto/tarifa/creador, `room_type,beds,rate_units,check_in_at,expected_checkout_at,guests,agreed_price,status,notes`.
- `Stay`: cuarto/tarifa/turno/usuario/huésped/reserva, entrada/salida prevista/real, huéspedes, estado y notas; tiene relación `charges`.
- `Product` y `Category`; `Sale` y `SaleItem`; `Shift`; `Expense`; `Purchase` y `PurchaseItem`; `InventoryMovement`; `Task`; `Supplier`; `User`.

No hay un modelo de tipo de habitación independiente: el tipo es un campo enum/validado de `rooms.type`, `rates.room_type` y `reservations.room_type`. La API no implementa idempotency keys en reservas, ventas, abonos, gastos o compras; el cliente debe bloquear doble envío y reconciliar tras un timeout antes de repetir un POST.

## Límites detectados antes de integrar clientes

- No hay panel web en `resources/views` aparte de `welcome.blade.php`; no existe cliente web para sincronización cruzada.
- El inventario del backend no expone un endpoint separado de compra por producto: se usa `/purchases`; los ajustes manuales se hacen mediante actualización de `stock` de producto con `reason`.
- El API no define catálogo de servicios de hotel ni gastos separados de compras; cargos libres se registran en `/stays/{stay}/charge`.
- Los cambios locales previos de Flutter no prueban que el despliegue tenga estas rutas/migraciones. La verificación real requiere despliegue con migraciones aplicadas y credenciales de prueba.
