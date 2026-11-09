# 01 - Requisitos: API de Productos (Laravel)

| | |
|---|---|
| Producto | API REST de gestión de productos con precios multi-divisa |
| Stack | Laravel 13.40, PHP 8.5, PostgreSQL 18, Sanctum, Scramble (OpenAPI 3.1) |
| Base URL | `/api/v1` |
| Estado | Aprobado para diseño técnico |

Convención: **RN-xx** = regla de negocio (sección 3), **T-xx** = criterio transversal (sección 2.1), **RNF-xx** = requisito no funcional (sección 4).

---

## 1. Resumen y alcance

**Objetivo.** Entregar una API RESTful JSON que gestione productos (CRUD) y permita registrar el precio de cada producto en distintas divisas. Debe demostrar dominio de la estructura de Laravel (capas, validación, autorización, recursos, tests, documentación), sin sobreingeniería.

**Arquitectura acordada.** `Controller delgado → FormRequest → DTO → Service → RepositoryInterface → EloquentRepository → Model`. Salida con API Resources, autorización con Policies, versionado `/api/v1`. El binding Interface → Eloquent se registra en un ServiceProvider.

### In-scope

- CRUD de productos (los 5 endpoints del enunciado).
- Listar y registrar precios de un producto por divisa (2 endpoints del enunciado).
- Divisas: tabla, seeder y `GET /currencies` de solo lectura (RN-01).
- Autenticación Sanctum en todos los endpoints de negocio y endpoint público `POST /auth/login` con rate limit estricto.
- Autorización por rol/abilities (admin escribe, viewer solo lee).
- Validación, errores JSON unificados, paginación/filtros/orden en `GET /products`.
- Documentación: OpenAPI 3.1 (Scramble), colección Postman, README.
- Tests automatizados (feature + unit).

### Out-of-scope (explícito)

- Registro/gestión de usuarios (los usuarios se crean por seeder o `artisan`).
- CRUD de divisas y sincronización de tipos de cambio con servicios externos.
- Restaurar productos eliminados (no hay endpoint de restore).
- Categorías, stock, imágenes, carrito, pedidos, multi-tenant, i18n de mensajes.
- OAuth2/JWT, refresh tokens, idempotency keys, caché HTTP/ETag.
- Cálculo contable (márgenes, redondeo por divisa según ISO 4217 minor units).

---

## 2. Historias de usuario y criterios de aceptación

Roles: **admin** (lectura y escritura), **viewer** (solo lectura), **anónimo** (solo `POST /auth/login`).

### 2.1 Criterios transversales (aplican a todo endpoint salvo que se indique)

| ID | Criterio |
|---|---|
| T-01 | **Dado** una petición sin token, con token inválido, expirado o revocado, **cuando** llama a cualquier endpoint excepto `POST /auth/login`, **entonces** responde `401` con `code: "unauthenticated"`. |
| T-02 | **Dado** un token sin la ability requerida (o un usuario cuya Policy lo deniega), **cuando** llama al endpoint, **entonces** responde `403` con `code: "forbidden"` y no se modifica ningún dato. |
| T-03 | **Dado** un cliente que supera el límite de peticiones (RN-14), **cuando** envía otra, **entonces** responde `429` con `code: "too_many_requests"`, cabecera `Retry-After` y cabeceras `X-RateLimit-*`. |
| T-04 | **Cuando** se llama a cualquier ruta bajo `/api/v1`, **entonces** la respuesta (éxito o error) es JSON aunque el cliente no envíe `Accept: application/json`, e incluye la cabecera `X-Request-Id`. |
| T-05 | **Cuando** se llama a una ruta inexistente o con método no permitido, **entonces** responde `404 not_found` / `405 method_not_allowed` en JSON (nunca HTML). |
| T-06 | **Dado** un error inesperado, **cuando** ocurre, **entonces** responde `500` con mensaje genérico y `request_id`, sin stack trace, SQL ni rutas de archivo; el detalle va solo al log. |
| T-07 | **Cuando** `{id}` en la ruta no es entero positivo (o excede el rango de bigint), **entonces** responde `404`, nunca `500`. |
| T-08 | **Dado** un usuario sin permiso de escritura y un body inválido, **cuando** llama a un endpoint de escritura, **entonces** recibe `403` (la autorización se evalúa antes que la validación). |
| T-09 | El orden de evaluación es: `401` → `403`/`404` → `422` → `409`. El orden relativo entre `403` y `404` en rutas con modelo no es contractual (el catálogo es legible por cualquier usuario autenticado). |

### 2.2 Matriz de códigos HTTP por endpoint

| Endpoint | Éxito | 401 | 403 | 404 | 409 | 422 | 429 |
|---|---|---|---|---|---|---|---|
| `POST /auth/login` (público) | 200 | credenciales inválidas | - | - | - | sí | sí |
| `POST /auth/logout` | 204 | sí | - | - | - | - | sí |
| `GET /currencies` | 200 | sí | sí | - | - | - | sí |
| `GET /products` | 200 | sí | sí | - | - | sí (query) | sí |
| `POST /products` | 201 | sí | sí | - | - | sí | sí |
| `GET /products/{id}` | 200 | sí | sí | sí | - | - | sí |
| `PUT /products/{id}` | 200 | sí | sí | sí | - | sí | sí |
| `DELETE /products/{id}` | 204 | sí | sí | sí | - | - | sí |
| `GET /products/{id}/prices` | 200 | sí | sí | sí | - | - | sí |
| `POST /products/{id}/prices` | 201 | sí | sí | sí | sí | sí | sí |
| `PUT /products/{id}/prices/{currency}` (Should) | 200 | sí | sí | sí | - | sí | sí |

### 2.3 Historias

**US-01 Obtener token** - Como cliente de la API quiero autenticarme para consumir los endpoints.
- AC-01.1 **Dado** un usuario válido, **cuando** `POST /auth/login` con `email` y `password` correctos, **entonces** `200` con `data.token`, `token_type: "Bearer"`, `expires_at` y `abilities` acordes a su rol (RN-15).
- AC-01.2 **Dado** credenciales incorrectas (usuario inexistente o password errónea), **cuando** se envía el login, **entonces** `401` con el mismo mensaje genérico en ambos casos (sin enumeración de usuarios).
- AC-01.3 **Cuando** falta `email`/`password` o `email` no es un email, **entonces** `422` con `errors` por campo.
- AC-01.4 **Dado** 5 intentos fallidos en 1 minuto desde la misma combinación email+IP, **cuando** llega el 6.º, **entonces** `429` con `Retry-After`, incluso si el 6.º intento usa credenciales correctas.
- AC-01.5 La respuesta nunca incluye hash de password ni el campo `remember_token`.

**US-02 Cerrar sesión (Should)** - Como usuario quiero revocar mi token.
- AC-02.1 **Dado** un token vigente, **cuando** `POST /auth/logout`, **entonces** `204` y el mismo token recibe `401` en la siguiente petición.

**US-03 Consultar divisas** - Como cliente quiero conocer las divisas disponibles para usar su `id`.
- AC-03.1 **Dado** un usuario autenticado, **cuando** `GET /currencies`, **entonces** `200` con `data[]` ordenado por `code`, cada ítem con `id, code, name, symbol, exchange_rate` (string con 6 decimales). Sin paginación (RN-01).
- AC-03.2 `POST/PUT/DELETE /currencies` responden `405` (la API no expone escritura de divisas).

**US-04 Listar productos** - Como cliente quiero listar productos con paginación, filtros y orden.
- AC-04.1 **Dado** 20 productos, **cuando** `GET /products` sin parámetros, **entonces** `200` con 15 ítems (`per_page` por defecto), `links` y `meta` (`current_page, last_page, per_page, total`) de Laravel, ordenados por `-id` (RN-11).
- AC-04.2 **Cuando** `?per_page=5&page=2`, **entonces** devuelve el segundo bloque de 5.
- AC-04.3 **Cuando** `?per_page=101` o `per_page=0`, `page=0`, **entonces** `422` (máximo 100).
- AC-04.4 **Cuando** `?search=lap`, **entonces** solo productos cuyo `name` contiene "lap" sin distinguir mayúsculas; `%` y `_` se tratan como literales.
- AC-04.5 **Cuando** `?currency_id=2&min_price=10&max_price=50`, **entonces** solo productos con esa divisa base y `price` en el rango (inclusive).
- AC-04.6 **Cuando** `?sort=-price` o `?sort=name`, **entonces** se ordena por ese campo; un campo fuera de la lista blanca (`id, name, price, created_at`) o `min_price > max_price` devuelve `422`.
- AC-04.7 No se listan productos eliminados (soft delete).
- AC-04.8 La consulta ejecuta un número fijo de queries independiente del tamaño de página (sin N+1; ver RNF-08).

**US-05 Crear producto** - Como admin quiero registrar un producto.
- AC-05.1 **Dado** un admin y body válido, **cuando** `POST /products`, **entonces** `201`, cabecera `Location: /api/v1/products/{id}`, `data` con `id`, los campos enviados, `currency` embebida y montos como string con 2 decimales; la fila existe en BD.
- AC-05.2 **Cuando** falta `name`, `price`, `currency_id`, `tax_cost` o `manufacturing_cost`, o `price <= 0`, o costos `< 0`, o más de 2 decimales, o `currency_id` inexistente, **entonces** `422` con `errors` por campo y no se crea fila.
- AC-05.3 **Cuando** el body incluye `id`, `created_at`, `deleted_at` u otros campos no permitidos, **entonces** se ignoran (mass assignment) y la fila usa valores generados.
- AC-05.4 **Dado** un viewer, **cuando** `POST /products`, **entonces** `403` y no se crea fila (T-08).

**US-06 Ver producto** - Como cliente quiero consultar un producto.
- AC-06.1 **Dado** un producto existente, **cuando** `GET /products/{id}`, **entonces** `200` con `data` (mismos campos que AC-05.1).
- AC-06.2 **Dado** un id inexistente o producto eliminado, **entonces** `404 not_found`.

**US-07 Actualizar producto** - Como admin quiero modificar un producto.
- AC-07.1 **Dado** un producto y body válido completo, **cuando** `PUT /products/{id}`, **entonces** `200` con el recurso actualizado y `updated_at` modificado (RN-09: PUT es reemplazo total).
- AC-07.2 **Cuando** el body es inválido (mismas reglas que AC-05.2) o falta un campo requerido, **entonces** `422` y la fila no cambia.
- AC-07.3 **Dado** que el producto ya tiene un precio registrado en la divisa X, **cuando** se intenta cambiar su `currency_id` base a X, **entonces** `422` (RN-05).
- AC-07.4 **Dado** un id inexistente o eliminado, **entonces** `404`. **Dado** un viewer, **entonces** `403`.

**US-08 Eliminar producto** - Como admin quiero eliminar un producto.
- AC-08.1 **Dado** un producto existente, **cuando** `DELETE /products/{id}`, **entonces** `204` sin body; la fila sigue en BD con `deleted_at` informado (RN-06).
- AC-08.2 **Dado** el producto eliminado, **cuando** `GET /products/{id}`, `GET /products/{id}/prices` o un segundo `DELETE`, **entonces** `404`; sus precios no aparecen en ningún endpoint.
- AC-08.3 **Dado** un viewer, **entonces** `403` y el producto no se elimina.

**US-09 Listar precios de un producto** - Como cliente quiero ver los precios de un producto en otras divisas.
- AC-09.1 **Dado** un producto con 2 precios, **cuando** `GET /products/{id}/prices`, **entonces** `200` con `data[]` de 2 ítems (`id, product_id, currency_id, currency, price`), ordenados por `currency.code`, sin paginación (RN-12). No incluye el precio base (RN-04).
- AC-09.2 **Dado** un producto sin precios adicionales, **entonces** `200` con `data: []`.
- AC-09.3 **Dado** un producto inexistente o eliminado, **entonces** `404`.

**US-10 Registrar precio en una divisa** - Como admin quiero fijar el precio de un producto en otra divisa.
- AC-10.1 **Dado** un admin, un producto y una divisa distinta de la base sin precio previo, **cuando** `POST /products/{id}/prices` con `currency_id` y `price` válidos, **entonces** `201` con el recurso creado; la fila existe en `product_prices`.
- AC-10.2 **Dado** que el producto ya tiene precio en esa divisa, **cuando** se repite el POST, **entonces** `409 conflict` y el precio existente no cambia (RN-03). Una colisión concurrente (violación de unique en BD) también produce `409`, nunca `500`.
- AC-10.3 **Cuando** `currency_id` es la divisa base del producto, **entonces** `422` en `errors.currency_id` (RN-04).
- AC-10.4 **Cuando** falta `currency_id`/`price`, `currency_id` no existe, `price <= 0` o tiene más de 2 decimales, **entonces** `422`.
- AC-10.5 **Dado** producto inexistente/eliminado, **entonces** `404`. **Dado** un viewer, **entonces** `403`.
- AC-10.6 El body no puede fijar `product_id` (se toma de la ruta).

**US-11 Corregir un precio (Should)** - Como admin quiero corregir un precio ya registrado.
- AC-11.1 **Dado** un precio existente, **cuando** `PUT /products/{id}/prices/{currency}` con `price` válido, **entonces** `200` con el precio actualizado.
- AC-11.2 **Dado** que no existe precio para esa divisa en ese producto, **entonces** `404`.

---

## 3. Reglas de negocio y decisiones

### 3.1 Modelo de datos (resultado de las decisiones)

| Tabla | Campo | Tipo PostgreSQL | Restricciones |
|---|---|---|---|
| `currencies` | id | bigint PK | |
| | code | char(3) | unique, mayúsculas, ISO 4217 (**extensión mínima al enunciado**) |
| | name | varchar(100) | unique, not null |
| | symbol | varchar(8) | not null |
| | exchange_rate | numeric(18,6) | not null, `> 0` |
| `products` | id | bigint PK | |
| | name | varchar(255) | not null, indexado |
| | description | text | nullable |
| | price | numeric(15,2) | not null, `> 0`, indexado |
| | currency_id | bigint FK `currencies` | not null, `ON DELETE RESTRICT`, indexado |
| | tax_cost | numeric(15,2) | not null, `>= 0` |
| | manufacturing_cost | numeric(15,2) | not null, `>= 0` |
| | created_at / updated_at / deleted_at | timestamptz | `deleted_at` nullable (SoftDeletes) |
| `product_prices` | id | bigint PK | |
| | product_id | bigint FK `products` | not null, `ON DELETE RESTRICT` |
| | currency_id | bigint FK `currencies` | not null, `ON DELETE RESTRICT` |
| | price | numeric(15,2) | not null, `> 0` |
| | created_at / updated_at | timestamptz | |
| | | | **unique `(product_id, currency_id)`** |
| `users` | role | varchar | `admin` \| `viewer` (enum PHP) |

Los `id` son enteros autoincrementales (bigint), compatibles con el `int` del enunciado.

### 3.2 Decisiones

| ID | Ambigüedad | Decisión |
|---|---|---|
| RN-01 | ¿Cómo se crean/gestionan las divisas? | Se cargan con **seeder** (USD, EUR, GBP, COP, MXN como mínimo). La API expone solo `GET /currencies` (lista completa, sin paginación, ≤ ~200 filas por ISO 4217). Sin CRUD de divisas: no está en el enunciado y evita una superficie de ataque y de tests sin valor para el objetivo. |
| RN-02 | Unicidad y formato de divisa | `name` único (texto legible, ej. "US Dollar"). Se **añade `code`** char(3) único (ISO 4217, mayúsculas) como identificador inequívoco para humanos y Postman. Los campos del enunciado se mantienen tal cual. |
| RN-03 | `POST /products/{id}/prices` con divisa ya registrada | **`409 Conflict`**, sin sobrescribir. Evita pisar datos por error y mantiene POST como creación. Unicidad garantizada por índice unique en BD (no solo en app). Para corregir precios se ofrece `PUT /products/{id}/prices/{currency}` (Should, US-11). |
| RN-04 | ¿Precio en la divisa base? | **Prohibido** (`422`). El precio base vive en `products.price`; duplicarlo crea dos fuentes de verdad. `GET .../prices` devuelve solo precios adicionales. |
| RN-05 | Cambio de `currency_id` base en `PUT` | Permitido, **sin conversión automática** del `price` (el cliente envía el precio ya en la nueva divisa). Si la nueva base ya tiene fila en `product_prices` para ese producto, `422` (rompería RN-04). |
| RN-06 | Soft delete | `Product` usa `SoftDeletes`. `DELETE` marca `deleted_at`; el producto y sus precios dejan de ser accesibles (`404`). Los precios **no se borran ni se les hace cascade** (conservan histórico y permiten un futuro restore). Sin endpoint de restore. `product_prices` y `currencies` no usan soft delete. |
| RN-07 | Divisas y borrado | Las FKs son `RESTRICT`: una divisa con productos/precios no se puede borrar (de todos modos no hay endpoint). |
| RN-08 | Precisión decimal | Dinero `numeric(15,2)` (máx. `9999999999999.99`); `exchange_rate` `numeric(18,6)` (máx. 12 enteros). **Nunca `float`**: cast Eloquent `decimal:2` / `decimal:6`, DTOs con `string`. En JSON los montos salen como **string** (`"19.99"`) para no perder precisión. La entrada acepta string o number con hasta 2 decimales. |
| RN-09 | `PUT` | Reemplazo total: todos los campos requeridos deben venir; no se acepta actualización parcial. `PATCH` no se documenta ni se garantiza. |
| RN-10 | Reglas de campos | Ver tabla 3.3. `tax_cost` y `manufacturing_cost` son **requeridos** (el cliente envía `0` explícito); no se valida que `tax_cost + manufacturing_cost <= price` (pueden existir ventas bajo costo). `name` no es único. |
| RN-11 | Paginación, filtros, orden en `GET /products` | Paginación por páginas de Laravel: `page`, `per_page` (def. 15, máx. 100). Filtros: `search` (ILIKE sobre `name`), `currency_id`, `min_price`, `max_price` (comparan **solo el precio base**; entre divisas distintas no son comparables y se documenta). Orden: `sort=<campo>` / `sort=-<campo>` con lista blanca `id, name, price, created_at`; defecto `-id`. Parámetros desconocidos se ignoran; valores inválidos dan `422`. |
| RN-12 | `GET /products/{id}/prices` | Sin paginación: está acotado por el número de divisas. Orden por `currency.code`. |
| RN-13 | ¿Se convierte con `exchange_rate`? | **No se calcula ni se persiste ninguna conversión.** El precio por divisa se registra manualmente (es decisión comercial, no un cálculo). `exchange_rate` es dato de referencia: unidades de esa divisa por 1 USD (USD = `1.000000`; valores del seeder ilustrativos, no en vivo). Opcional (Could): campo calculado `suggested_price` en la respuesta de precios = `price_base / rate(base) * rate(destino)` con `bcmath`, nunca persistido. |
| RN-14 | Rate limits | `login`: 5/min por email+IP. API autenticada: 60/min por usuario (por IP si no hay usuario). Ambos configurables por `.env` para poder testearlos. |
| RN-15 | Roles y abilities | `admin` → token con `products:read`, `products:write`. `viewer` → solo `products:read`. `GET /currencies` requiere `products:read`. La Policy exige el rol **y** la ability del token (el token nunca supera al rol). Token expira a las 8 h (configurable). Un solo seeder crea `admin@example.com` y `viewer@example.com` (credenciales en README, solo entornos no productivos). |
| RN-16 | Idioma | Documentación en español; mensajes de error del framework en inglés (sin paquete de traducciones); el cliente debe usar `code` y `errors.<campo>`, que son estables. |

### 3.3 Validación de campos

| Recurso | Campo | Regla |
|---|---|---|
| Producto | `name` | requerido, string, trim, 2-255 caracteres |
| | `description` | nullable, string, máx. 2000 |
| | `price` | requerido, numérico, `>= 0.01`, máx. 2 decimales, `<= 9999999999999.99` |
| | `currency_id` | requerido, entero, existe en `currencies` |
| | `tax_cost`, `manufacturing_cost` | requeridos, numéricos, `>= 0`, máx. 2 decimales, mismo tope |
| Precio | `currency_id` | requerido, entero, existe, distinto de la base del producto, no registrado aún (→ `409`) |
| | `price` | igual que `products.price` |
| Login | `email` | requerido, email, máx. 255 |
| | `password` | requerido, string, máx. 255 |
| | `device_name` | opcional, string, máx. 100 (nombre del token) |
| Query productos | `page` / `per_page` | enteros `>= 1` / `1..100` |
| | `search` | string, máx. 100 |
| | `currency_id`, `min_price`, `max_price` | entero existente / numéricos `>= 0`, `min <= max` |
| | `sort` | en lista blanca (RN-11) |

---

## 4. Requisitos no funcionales

### 4.1 Seguridad

| ID | Requisito |
|---|---|
| RNF-01 | **Autenticación** (OWASP API2): Sanctum con tokens personales, expiración configurada, passwords con hash (bcrypt/argon), login con mensaje único ante fallo, sin registro público. |
| RNF-02 | **Autorización** (API1/API3/API5): Policies + abilities (RN-15). El dominio no tiene propiedad por usuario (catálogo compartido), por lo que BOLA se reduce al control por rol. Los Resources exponen una lista blanca de campos (no `deleted_at`, no `$hidden` del modelo). |
| RNF-03 | **Mass assignment**: `$fillable` explícito en cada modelo; prohibido `$guarded = []` y `$request->all()`; los DTOs se construyen solo desde `$request->validated()`. |
| RNF-04 | **Inyección SQL**: solo Eloquent/query builder con bindings; prohibido `whereRaw`/`orderByRaw` con input del usuario; `sort` y filtros por lista blanca; `%`/`_` escapados en `search`. |
| RNF-05 | **Consumo de recursos** (API4): rate limits (RN-14); `per_page <= 100`; body JSON máximo 64 KB (`413` si lo excede, a nivel servidor o middleware); `password`/`search` con longitud máxima. |
| RNF-06 | **Configuración** (API8): `APP_DEBUG=false` en producción; CORS por `config/cors.php` limitado a `api/*`, orígenes desde `.env` (`CORS_ALLOWED_ORIGINS`), sin `*` combinado con credenciales; cabeceras en toda respuesta de la API: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store`, `Content-Security-Policy: default-src 'none'` (excluidas las rutas de la UI de docs) y HSTS cuando hay HTTPS. |
| RNF-07 | **Inventario** (API9): rutas versionadas `/api/v1`; OpenAPI generado y publicado; sin endpoints sin documentar. No aplican API6 (flujos sensibles), API7 (SSRF) ni API10 (consumo de APIs externas). Secretos solo en `.env` (`.env.example` sin valores reales). |

### 4.2 Rendimiento

| ID | Requisito |
|---|---|
| RNF-08 | **Sin N+1**: `with('currency')` en listados y `with('currency')` en precios; Resources usan `whenLoaded`. `Model::preventLazyLoading()` activo fuera de producción; un test verifica que `GET /products` y `GET /products/{id}/prices` ejecutan un número fijo de queries. |
| RNF-09 | **Índices**: PK, FKs, `unique(currencies.code)`, `unique(currencies.name)`, `unique(product_prices.product_id, currency_id)`, `products(name)`, `products(price)`. Ruta de evolución para `ILIKE '%x%'`: índice `pg_trgm` GIN (Could). |
| RNF-10 | **Integridad en BD**: restricciones `CHECK` para `> 0` / `>= 0` y los unique/FK de 3.1 (defensa en profundidad: la app valida y la BD garantiza). |
| RNF-11 | Objetivo orientativo: p95 < 200 ms en `GET /products` con 10 000 productos, en entorno local. |

### 4.3 Contrato de respuestas

- **Éxito**: Resource estándar de Laravel. Un ítem: `{ "data": {...} }`. Lista: `{ "data": [...], "links": {...}, "meta": {...} }` (paginada) o `{ "data": [...] }`. `DELETE`/`logout`: `204` sin body. `POST` crea con `201` + `Location`.
- **Fechas**: ISO 8601 UTC. **Montos**: string decimal (RN-08).
- **Error** (único formato, incluidos los de validación, auth y 5xx):

```json
{
  "message": "The given data was invalid.",
  "code": "validation_error",
  "errors": { "price": ["The price field must be greater than 0."] },
  "request_id": "9f1c2b7e-3c1a-4b8e-9d52-1c0a7e5d1f10"
}
```

| HTTP | `code` |
|---|---|
| 401 | `unauthenticated` |
| 403 | `forbidden` |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 409 | `conflict` |
| 413 | `payload_too_large` |
| 422 | `validation_error` (con `errors`) |
| 429 | `too_many_requests` |
| 500 | `server_error` |

`errors` solo aparece en `422`. Los mensajes `404` no revelan si la causa es "no existe" o "eliminado".

### 4.4 Observabilidad mínima

- Middleware de **request id**: genera UUID por petición (acepta `X-Request-Id` entrante solo si es un UUID válido), lo devuelve en la cabecera `X-Request-Id` y en el cuerpo de todo error, y lo añade al contexto de log (`Log::withContext`).
- Los errores `5xx` y los intentos `401/403/429` se registran con `request_id`, user id e IP; nunca se loguean passwords ni tokens.

### 4.5 Documentación (parte del producto, criterio de evaluación)

- **OpenAPI 3.1** con Scramble en `/docs/api` (habilitado en local y con flag `.env` para evaluación); archivo exportado y versionado en `docs/openapi.json` (`php artisan scramble:export`). Debe describir esquema bearer, los códigos de error de la matriz 2.2 y ejemplos.
- **Colección Postman** (`docs/postman/`) con variables `base_url` y `token`; la petición de login guarda el token automáticamente (script de test); una carpeta por recurso con casos de éxito y error clave.
- **README**: requisitos, instalación (`composer install`, `.env`, `migrate --seed`), usuarios de prueba, ejecución de tests, mapa de capas, enlaces a docs y resumen de las decisiones RN-xx.

---

## 5. Definition of Done y criterios de QA

### 5.1 Definition of Done (por endpoint/historia)

- [ ] Cumple todos los AC de la historia y los criterios transversales T-xx.
- [ ] Respeta las capas acordadas: el controller solo orquesta; no hay lógica ni queries Eloquent en controllers/Resources; el Service depende de la interfaz del repositorio.
- [ ] Validación en FormRequest, autorización (Policy + ability) antes de validar, salida solo por API Resource.
- [ ] Migración con tipos y constraints de 3.1; `migrate:fresh --seed` funciona desde cero.
- [ ] Tests feature y unit pasan (5.2); sin tests omitidos ni `skip`.
- [ ] `vendor/bin/pint --test` sin diferencias; análisis estático (Larastan nivel ≥ 5) sin errores (Should).
- [ ] Endpoint visible y correcto en OpenAPI y en Postman; README actualizado.
- [ ] Sin secretos en el repo, `APP_DEBUG=false` verificado en config de producción.

### 5.2 Criterios de QA / tests

**Entorno**: tests contra una BD **PostgreSQL de test** (no SQLite: se validan `numeric`, `ILIKE`, `CHECK` y unique reales) con `RefreshDatabase`. Framework: el que traiga el esqueleto (Pest/PHPUnit), factories para `User`, `Currency`, `Product`, `ProductPrice`.

| Nivel | Qué se cubre (mínimo) |
|---|---|
| **Feature, por endpoint** | Camino feliz con estructura JSON exacta; `401` sin token; `403` con viewer y con token sin ability; `404` (id inexistente, eliminado, no numérico); `422` por cada regla de 3.3 (`dataset`/`data provider`); `409` en precio duplicado; `429` (límite bajado por config en el test); formato de error unificado y `X-Request-Id`; cabeceras de seguridad; mass assignment ignorado; persistencia real en BD. |
| **Feature, listado** | Paginación, `per_page` máximo, `search`, filtros, `sort` válido/ inválido, exclusión de soft-deleted, número fijo de queries. |
| **Feature, auth** | Login OK/KO sin enumeración, abilities por rol, token expirado, logout revoca, rate limit de login. |
| **Unit, Services** | `ProductService` y `ProductPriceService` con repositorio mockeado: delegación correcta, regla "divisa distinta de base" (RN-04), regla de cambio de base (RN-05), conflicto de duplicado (RN-03), no usan `Request` ni Eloquent directo. |
| **Unit, otros** | DTOs (mapeo desde datos validados, decimales como string), Policies (matriz rol × acción), Resources (campos expuestos, `whenLoaded`). |

**Umbral**: cobertura de líneas ≥ 80 % en `app/Services`, `app/Http` y `app/Repositories` (PCOV/Xdebug); 100 % de endpoints de la matriz 2.2 con al menos un test por código marcado.

**Criterio de salida**: 0 tests fallidos, 0 bugs abiertos de severidad alta/crítica (datos incorrectos, fallo de seguridad, `500` en entrada de usuario), documentación verificada importando la colección Postman contra una instancia limpia.

---

## 6. Priorización MoSCoW

| Prioridad | Elementos |
|---|---|
| **Must** | Los 7 endpoints del enunciado · tablas `currencies`, `products`, `product_prices` con migraciones, FKs, unique y tipos numeric · seeders (divisas + usuarios) · `GET /currencies` · login Sanctum + auth en todos los endpoints + rate limit de login · rol admin/viewer con Policies y abilities · FormRequests, DTOs, Services, Repository (interfaz + Eloquent), API Resources · paginación de `GET /products` · soft delete de productos · formato de error JSON unificado (T-01 a T-08) · rate limit global de API · mass assignment y bindings seguros · OpenAPI (Scramble) · colección Postman · README · tests feature por endpoint y unit de services · Pint |
| **Should** | Filtros y orden de `GET /products` (RN-11) · `POST /auth/logout` · `PUT /products/{id}/prices/{currency}` · middleware de request id + logging con contexto · cabeceras de seguridad y CORS configurado · `CHECK` en BD · límite de tamaño de payload · `preventLazyLoading` + test de queries · Larastan · reporte de cobertura ≥ 80 % · `docs/openapi.json` exportado |
| **Could** | `DELETE /products/{id}/prices/{currency}` · `suggested_price` calculado (RN-13) · índice `pg_trgm` · caché de `GET /currencies` · Sail/Docker Compose · workflow de CI (pint + tests) · `PATCH` parcial |
| **Won't (esta entrega)** | Registro/gestión de usuarios · CRUD de divisas · sync de tipos de cambio externos · restore de productos · categorías/stock/imágenes · multi-tenant · OAuth2/JWT/refresh tokens · idempotency keys · i18n de mensajes |

**Riesgos y mitigación**: (1) sobreingeniería de capas → un Service por agregado (`ProductService`, `ProductPriceService`) y un repositorio por agregado, sin genéricos; (2) ambigüedad 403 vs 422 → T-08 con test dedicado; (3) pérdida de precisión monetaria → decimales como string de extremo a extremo (RN-08); (4) carrera en precio duplicado → unique en BD y mapeo a `409`.
