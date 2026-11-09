# 02 - Diseño de la API: contrato para implementación

| | |
|---|---|
| Entrada | `docs/01-requirements.md` (fuente de verdad; RN-xx / T-xx / RNF-xx se citan, no se repiten) |
| Destinatario | Laravel Specialist. Este documento no deja decisiones abiertas; si algo choca con el 01, gana el 01 |
| Stack verificado en `vendor/` | Laravel 13.40, Sanctum 4, Scramble 0.13, Pint, PHPUnit 12, Larastan 3 (nivel 6). Archivos añadidos tras QA (SafeString, PersonalAccessToken, RejectMalformedJson, ApiRequest, ErrorResponsesDocumentation): ver §10 |

---

## 1. Arquitectura y estructura de carpetas

### 1.1 Flujo y reglas por capa

```
Request -> Middleware globales -> Controller -> FormRequest (authorize + rules) -> DTO (readonly)
        -> Service -> RepositoryInterface -> EloquentRepository -> Model -> Resource -> Response
```

| Capa | Regla dura |
|---|---|
| Controller | Un método = orquestar: `FormRequest` -> `DTO::fromValidated($request->validated())` -> `Service` -> `Resource`. Sin Eloquent, sin reglas de negocio. Si el endpoint no tiene FormRequest, primera línea `Gate::authorize(...)`. |
| FormRequest | `authorize()` llama a la Policy (`$this->user()->can(...)`); `rules()` solo validación de forma. Se evalúa `authorize` antes que `rules` (T-08). |
| DTO | `final readonly class`, constructor promovido, solo escalares/enums, montos `string`. `fromValidated(array $validated): self` es el único constructor estático. Nunca se construye desde `$request->all()` (RNF-03). |
| Service | `final class` concreta (**sin interfaz**). Depende de interfaces de repositorio. Aloja RN-03/04/05. No conoce `Request`, `Auth` ni Eloquent estático. Lanza `ValidationException::withMessages()` (422) o `ConflictException` (409). |
| Repository | Única capa con queries. Interfaz en `Contracts/`, implementación en `Eloquent/`. Recibe DTOs o modelos; devuelve modelos / paginator / colecciones. Sin lógica de negocio. |
| Resource | Lista blanca de campos; relaciones con `whenLoaded`. Nunca expone `deleted_at`. |
| Policy | Rol **y** ability del token (RN-15). |

Decisiones de estructura (una línea cada una):

- **Sin `BaseRepository` genérico**: los 3 repositorios tienen firmas distintas; un CRUD genérico añadiría métodos que nadie usa.
- **Sin `CurrencyService`**: `GET /currencies` es una lectura sin regla de negocio; `CurrencyController` inyecta `CurrencyRepositoryInterface` (es además el punto natural del decorator de caché Could de MoSCoW).
- **Sin `UserRepository`**: auth es infraestructura del framework; `AuthService` usa `User::query()` directo (único Service que lo hace; un repositorio de 1 método sería boilerplate).
- **Capa HTTP versionada** (`Controllers/V1`, `Requests/V1`, `Resources/V1`); dominio (Services, Repositories, DTOs, Models, Policies, Enums) sin versión.
- **Atributo `#[Fillable([...])]`** (convención de Laravel 13 y del `User` del skeleton) equivale a `$fillable` explícito. Prohibido `#[Guarded]`/`$guarded = []`.

### 1.2 Árbol de archivos nuevos (todo bajo `app/` salvo indicación)

```
app/
├── DTOs/
│   ├── LoginData.php                  email, password, deviceName?            <- LoginRequest
│   ├── AuthTokenData.php              SALIDA de AuthService: plainTextToken, expiresAt, abilities[]
│   ├── ProductData.php                name, description?, price, currencyId, taxCost, manufacturingCost (store y update)
│   ├── ProductFilterData.php          search?, currencyId?, minPrice?, maxPrice?, sort(ProductSortField), descending, perPage, page
│   ├── StoreProductPriceData.php      currencyId, price
│   └── UpdateProductPriceData.php     price
├── Enums/
│   ├── ErrorCode.php                  backed string: valores estables de `code` (sec. 3.3)
│   ├── ProductSortField.php           Id|Name|Price|CreatedAt; allowedValues() => id,-id,name,-name,price,-price,created_at,-created_at
│   ├── TokenAbility.php               ProductsRead='products:read', ProductsWrite='products:write'
│   └── UserRole.php                   Admin|Viewer; abilities(): TokenAbility[] por rol
├── Exceptions/
│   └── ConflictException.php          RuntimeException de dominio -> 409 (único conflicto: precio duplicado)
├── Http/
│   ├── Controllers/
│   │   ├── Controller.php             (existente, sin cambios)
│   │   └── V1/
│   │       ├── AuthController.php         login, logout
│   │       ├── CurrencyController.php     index
│   │       ├── ProductController.php      index, store, show, update, destroy
│   │       └── ProductPriceController.php index, store, update
│   ├── Middleware/
│   │   ├── AssignRequestId.php        UUID entrante válido o nuevo; header X-Request-Id; Log::shareContext
│   │   ├── ForceJsonResponse.php      setea Accept: application/json si la ruta es api/*
│   │   ├── SecurityHeaders.php        cabeceras RNF-06 en api/*
│   │   └── LimitPayloadSize.php       413 si el body supera config('api.max_payload_kb')
│   ├── Requests/V1/
│   │   ├── LoginRequest.php
│   │   ├── ListProductsRequest.php    valida query + authorize viewAny
│   │   ├── StoreProductRequest.php    authorize create + reglas de producto
│   │   ├── UpdateProductRequest.php   extends StoreProductRequest; solo cambia authorize (update sobre route('product'))
│   │   ├── StoreProductPriceRequest.php   authorize update(product) + currency_id, price
│   │   └── UpdateProductPriceRequest.php  authorize update(product) + price
│   └── Resources/V1/
│       ├── AuthTokenResource.php      {token, token_type, expires_at, abilities}
│       ├── CurrencyResource.php
│       ├── ProductResource.php
│       └── ProductPriceResource.php
├── Models/
│   ├── Currency.php                   #[WithoutTimestamps]; cast exchange_rate decimal:6; hasMany products/prices
│   ├── Product.php                    SoftDeletes; casts decimal:2; belongsTo currency; hasMany prices
│   ├── ProductPrice.php               cast price decimal:2; belongsTo product, currency
│   └── User.php                       (existente) + HasApiTokens, cast role => UserRole; `role` NO fillable
├── Policies/                          (auto-descubiertas por convención de nombre)
│   ├── CurrencyPolicy.php             viewAny
│   └── ProductPolicy.php              viewAny, view, create, update, delete (también protege precios, ver 1.4)
├── Providers/
│   ├── AppServiceProvider.php         (existente) rate limiters, route pattern/bind, Model::shouldBeStrict, gate docs
│   └── RepositoryServiceProvider.php  único lugar con bind() interfaz -> Eloquent
├── Repositories/
│   ├── Contracts/
│   │   ├── CurrencyRepositoryInterface.php
│   │   ├── ProductRepositoryInterface.php
│   │   └── ProductPriceRepositoryInterface.php
│   └── Eloquent/
│       ├── EloquentCurrencyRepository.php
│       ├── EloquentProductRepository.php
│       └── EloquentProductPriceRepository.php
├── Services/
│   ├── AuthService.php                login(LoginData): AuthTokenData ; logout(User): void
│   ├── ProductService.php             list, create, update (RN-05), delete
│   └── ProductPriceService.php        list, create (RN-04, RN-03), update
└── Support/
    ├── ApiError.php                   for(...) y fromHttpException(...): JSON de error unificado (+ log de 401/403/429); null fuera de api/*
    └── Money.php                      normalize(int|float|string): string; string intacto, int/float -> number_format(2 dec)

config/
├── api.php                            (nuevo) rate_limits.login|api, max_payload_kb, docs_enabled   [lee .env]
├── cors.php                           (publicar: `php artisan config:publish cors`)
├── scramble.php                       (publicar: `vendor:publish --tag=scramble-config`)
├── sanctum.php                        (editar, sec. 5.1)
└── database.php                       (editar: pgsql.timezone = 'UTC')

database/
├── factories/  CurrencyFactory, ProductFactory, ProductPriceFactory, UserFactory(+ states admin(), viewer())
├── migrations/ 2026_11_09_140000_create_currencies_table, ..._140100_create_products_table, ..._140200_create_product_prices_table  (+ editar users, sec. 7)
└── seeders/    CurrencySeeder, UserSeeder, DatabaseSeeder (llama a ambos)
routes/api.php (reescribir) · bootstrap/app.php (editar) · bootstrap/providers.php (+ RepositoryServiceProvider)
phpstan.neon (nuevo) · phpunit.xml (editar) · .env.example (editar)
```

### 1.3 Interfaces de repositorio (firmas normativas)

```php
interface CurrencyRepositoryInterface {
    /** @return Collection<int, Currency> ordenado por code */
    public function all(): Collection;
}

interface ProductRepositoryInterface {
    public function paginate(ProductFilterData $filters): LengthAwarePaginator; // with('currency'), withQueryString()
    public function findOrFail(int $id): Product;                              // with('currency'); excluye soft-deleted
    public function create(ProductData $data): Product;                        // devuelve con 'currency' cargada
    public function update(Product $product, ProductData $data): Product;     // fill+save, luego ->load('currency') (recarga, no loadMissing)
    public function delete(Product $product): void;                            // soft delete; NO toca product_prices
}

interface ProductPriceRepositoryInterface {
    /** @return Collection<int, ProductPrice> con 'currency', orden currencies.code */
    public function listByProduct(Product $product): Collection;               // join currencies + select('product_prices.*') + with('currency')
    public function findByProductAndCurrencyOrFail(int $productId, int $currencyId): ProductPrice; // with('currency')
    public function existsForProductAndCurrency(int $productId, int $currencyId): bool;
    public function create(Product $product, StoreProductPriceData $data): ProductPrice; // dentro de DB::transaction (savepoint si hay tx externa); puede lanzar UniqueConstraintViolationException; devuelve con 'currency'
    public function update(ProductPrice $price, UpdateProductPriceData $data): ProductPrice; // solo cambia price
}
```

Reglas de implementación del repositorio:

- Mapear DTO -> array **explícito** con las claves `fillable` (nunca `toArray()` genérico).
- Preparado para decorator de caché (no implementarlo): los consumidores dependen **siempre de la interfaz** (nunca de `Eloquent*` ni de `Product::query()` fuera del repo); los métodos de lectura (`all`, `paginate`, `findOrFail`, `listByProduct`, `find...`) no tienen efectos secundarios y sus argumentos son DTOs/escalares inmutables (clave de caché = `md5(serialize($dto))`); los de escritura son los únicos puntos de invalidación. Un decorator futuro `CachedProductRepository implements ProductRepositoryInterface` envuelve a `EloquentProductRepository` y se activa cambiando **solo** el `bind()` en `RepositoryServiceProvider`.
- `ProductRepositoryInterface::paginate` usa `paginate($perPage, ['*'], 'page', $page)` con los valores del DTO (no depende de `request()`).
- Orden de `paginate`: `orderBy(campo, dir)` + desempate `orderBy('id', 'desc')` si el campo no es `id` (paginación determinista).
- `search`: `where('name', 'ILIKE', '%'.addcslashes($search, '\\%_').'%')`. Filtros de precio: `where('price', '>=', $min)` / `'<='` con el string tal cual (binding). Prohibido `whereRaw`/`orderByRaw` con input.

### 1.4 Servicios: lógica normativa

**`ProductService`** (deps: `ProductRepositoryInterface`, `ProductPriceRepositoryInterface`)
- `list(ProductFilterData): LengthAwarePaginator` -> `products->paginate`.
- `create(ProductData): Product` -> `products->create`.
- `update(Product, ProductData): Product` -> **RN-05**: si `$data->currencyId !== $product->currency_id` y `prices->existsForProductAndCurrency($product->id, $data->currencyId)` => `ValidationException::withMessages(['currency_id' => ['The selected currency already has a registered price for this product.']])`. Si no, `products->update`.
- `delete(Product): void`.

**`ProductPriceService`** (deps: `ProductPriceRepositoryInterface`)
- `list(Product): Collection`.
- `create(Product, StoreProductPriceData): ProductPrice`. Orden (T-09: 422 antes que 409):
  1. `currencyId === $product->currency_id` => `ValidationException::withMessages(['currency_id' => ['The currency must differ from the product base currency.']])` (RN-04).
  2. `existsForProductAndCurrency` => `ConflictException('A price already exists for this product and currency.')` (RN-03).
  3. `repo->create`; si lanza `Illuminate\Database\UniqueConstraintViolationException` (carrera) => relanzar como la misma `ConflictException`.
- `update(ProductPrice, UpdateProductPriceData): ProductPrice` -> `repo->update`.

**`AuthService`** (sin interfaz de repo, ver 1.1)
- `login(LoginData)`: buscar `User` por email (`strtolower`); si no existe, ejecutar igualmente `Hash::make($password)` (iguala tiempos; evita enumeración por timing) y fallar; si existe, `Hash::check`. Fallo => `throw new AuthenticationException('Invalid credentials.')` (mismo mensaje en ambos casos, AC-01.2). Éxito => `$user->createToken($deviceName ?? 'api', $user->role->abilities() como strings, now()->addMinutes((int) config('sanctum.expiration')))` y devolver `AuthTokenData`.
- `logout(User)`: `$user->currentAccessToken()->delete()`.

**Policies** (`$user->tokenCan()` devuelve `false` si no hay token):

| Método | `ProductPolicy` | `CurrencyPolicy` |
|---|---|---|
| `viewAny`, `view` | `tokenCan('products:read')` | `viewAny`: `tokenCan('products:read')` |
| `create`, `update`, `delete` | `$user->role === UserRole::Admin && tokenCan('products:write')` | - |

Los endpoints de precios no tienen policy propia: lectura -> `ProductPolicy::view($product)`; escritura -> `ProductPolicy::update($product)`.

### 1.5 `routes/api.php` (contrato de rutas)

El prefijo `api` lo aplica `withRouting(api:)`; `v1` se declara en el archivo de rutas.

```
Route::prefix('v1')->name('v1.')->group(function () {
    POST   auth/login                       AuthController@login            name auth.login            middleware throttle:login   (público)
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        POST   auth/logout                  AuthController@logout           name auth.logout
        GET    currencies                   CurrencyController@index        name currencies.index
        GET    products                     ProductController@index         name products.index
        POST   products                     ProductController@store         name products.store
        GET    products/{product}           ProductController@show          name products.show
        PUT    products/{product}           ProductController@update       name products.update
        DELETE products/{product}           ProductController@destroy       name products.destroy
        GET    products/{product}/prices    ProductPriceController@index    name products.prices.index
        POST   products/{product}/prices    ProductPriceController@store    name products.prices.store
        PUT    products/{product}/prices/{price}  ProductPriceController@update  name products.prices.update
    });
});
```

- Rutas **explícitas** (no `apiResource`): `apiResource` registra también `PATCH` y RN-09 dice que PATCH no se ofrece. `PATCH /products/{id}` => 405.
- Nombres finales: `v1.auth.login`, `v1.products.show`, `v1.products.prices.update`, etc.
- `{price}` = **id de la divisa** del precio (el segmento `{currency}` del 01). Se llama `price` porque el binder lo resuelve a `ProductPrice`.
- En `AppServiceProvider::boot()`:

```php
Route::pattern('product', '[1-9][0-9]{0,17}');   // T-07: no entero positivo o >18 dígitos => no hay ruta => 404 (nunca llega a PG, que lanzaría "out of range" => 500)
Route::pattern('price',   '[1-9][0-9]{0,17}');
Route::bind('product', fn (string $id) => app(ProductRepositoryInterface::class)->findOrFail((int) $id));            // pasa por el repo (cacheable) y excluye soft-deleted
Route::bind('price', fn (string $id, Route $route) => app(ProductPriceRepositoryInterface::class)
        ->findByProductAndCurrencyOrFail($route->parameter('product')->id, (int) $id));                              // 404 antes que 422 (T-09); depende de que {product} se resuelva antes (orden de URI)
```

`ModelNotFoundException` => 404 por el mapeo de excepciones (sec. 3.3). Los middleware `auth` y `throttle` corren **antes** que `SubstituteBindings` (prioridad del Kernel verificada), así que 401 precede a 404.

---

## 2. Convenciones

| Tema | Regla |
|---|---|
| Base URL / versión | `/api/v1`. Un breaking change crea `/api/v2` + carpetas `V2`; v1 se depreca con cabecera `Deprecation`/`Sunset`. |
| Recursos | Plural, minúsculas, sustantivos: `products`, `prices`, `currencies`. Anidado solo `products/{product}/prices`. |
| Nombres de ruta | `v1.<recurso>.<acción>` (sec. 1.5). |
| JSON | `snake_case` en todas las claves (request, response, query). |
| IDs | Enteros (`bigint`), nunca string. En JSON salen como `number`. |
| Montos | **String decimal**: dinero 2 decimales (`"19.99"`), `exchange_rate` 6 decimales (`"1.000000"`). Entrada: string o number con hasta 2 decimales (recomendado string; un number JSON con más de 14 cifras significativas puede ser rechazado por precisión de float de PHP). |
| Fechas | ISO 8601 UTC con `Z`: `2026-11-09T14:30:00.000000Z` (Carbon serializa así por defecto; `config/app.php` timezone = `UTC` y `pgsql.timezone = UTC`). |
| Envoltorio | `JsonResource` con wrap `data` (no llamar a `withoutWrapping`). |
| Verbos | `GET` lee, `POST` crea (201 + `Location`), `PUT` reemplazo total (RN-09), `DELETE` 204. Sin `PATCH`. |
| `Location` | En 201 de producto: `/api/v1/products/{id}` (`route('v1.products.show', $p, false)`, relativa). En 201 de precio: `/api/v1/products/{id}/prices/{currency_id}` (`route('v1.products.prices.update', [...], false)`). |
| Idioma | Docs en español; mensajes de error en inglés (RN-16); los clientes usan `code` y `errors.<campo>`. |

---

## 3. Contrato estándar de respuestas

### 3.1 Éxito

- Recurso único: `{ "data": { ... } }`.
- Lista paginada (`GET /products`): `{ "data": [...], "links": {first,last,prev,next}, "meta": {current_page, from, last_page, links[], path, per_page, to, total} }` (formato nativo de `ResourceCollection` sobre `LengthAwarePaginator`).
- Lista sin paginar (`currencies`, `prices`): `{ "data": [...] }`.
- `DELETE` y `logout`: `204` sin body.

### 3.2 Error unificado (único formato, también 401/403/404/405/413/429/5xx)

```json
{
  "message": "The given data was invalid.",
  "code": "validation_error",
  "errors": { "price": ["The price field must be at least 0.01."] },
  "request_id": "9f1c2b7e-3c1a-4b8e-9d52-1c0a7e5d1f10"
}
```

- `errors` **solo** en 422. `request_id` siempre (el mismo que la cabecera `X-Request-Id`).
- `message` es un texto **fijo por código** (ver tabla) para no filtrar texto del framework (p. ej. `No query results for model [App\Models\Product]`). Excepciones: `unauthenticated` usa el mensaje de la excepción (`Unauthenticated.` / `Invalid credentials.`) y `conflict` usa el de `ConflictException`.
- Ni siquiera con `APP_DEBUG=true` el cuerpo de `/api/*` lleva trace, SQL ni rutas de archivo (T-06); el detalle va a `storage/logs/laravel.log` con `request_id`.

### 3.3 Catálogo de `code` y mapeo de excepciones

`App\Enums\ErrorCode` (valores estables, minúsculas, los del 01 sec. 4.3 + `bad_request`):

| `code` | HTTP | `message` fijo | Excepción que lo produce (vista por los callbacks `render`, o sea **después** de `prepareException`) |
|---|---|---|---|
| `validation_error` | 422 | `The given data was invalid.` | `ValidationException` (con `errors = $e->errors()`) |
| `unauthenticated` | 401 | de la excepción | `AuthenticationException`. Añadir `WWW-Authenticate: Bearer`. |
| `forbidden` | 403 | `This action is unauthorized.` | `AccessDeniedHttpException` (Laravel convierte `AuthorizationException`, incl. `MissingAbilityException`) |
| `not_found` | 404 | `Resource not found.` | `NotFoundHttpException` (Laravel convierte `ModelNotFoundException`; también ruta inexistente / patrón `where` no cumplido) |
| `method_not_allowed` | 405 | `Method not allowed.` | `MethodNotAllowedHttpException`. Copiar header `Allow`. |
| `conflict` | 409 | de la excepción | `App\Exceptions\ConflictException` |
| `payload_too_large` | 413 | `Payload too large.` | `PostTooLargeException` (la lanza `LimitPayloadSize`; también la de `ValidatePostSize`) |
| `too_many_requests` | 429 | `Too many requests.` | `ThrottleRequestsException`. Copiar `Retry-After` y `X-RateLimit-*` de `$e->getHeaders()`. |
| `bad_request` | status | `Bad request.` | Cualquier otra `HttpExceptionInterface` 4xx (400, 415...) |
| `server_error` | 500 (o status 5xx de la excepción) | `Server error.` | Otra `HttpExceptionInterface` 5xx (503...) y todo `Throwable` (`QueryException`, `Error`...) |

Regla común de los callbacks: copiar `$e->getHeaders()` cuando la excepción sea `HttpExceptionInterface` (así salen `Allow`, `Retry-After`, `X-RateLimit-*`). `ApiError::for()` devuelve `null` si la ruta no es `api/*`, para que `/docs/api` y `/up` conserven el render por defecto. `ApiError` registra con `Log::warning` (campos `code`, `status`, `method`, `path`, `ip`, `user_id`; **nunca** headers, body, password ni token) los códigos `unauthenticated`, `forbidden` y `too_many_requests`. Los 5xx los reporta el handler por defecto (el `request_id` ya viaja en el contexto compartido).

### 3.4 `bootstrap/app.php` (esqueleto normativo)

```php
->withRouting(
    api: __DIR__.'/../routes/api.php',          // sin `web:`; se elimina routes/web.php
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
->withMiddleware(function (Middleware $middleware): void {
    // GLOBALES (no del grupo api): un 404/405 no ejecuta middleware de grupo, y T-04/T-05 exigen X-Request-Id y JSON ahí también.
    // Orden = el del array: request-id primero (lo necesitan todos los errores), LimitPayloadSize al final.
    $middleware->prepend([AssignRequestId::class, ForceJsonResponse::class, SecurityHeaders::class, LimitPayloadSize::class]);
    $middleware->redirectGuestsTo(fn () => null);   // nunca intentar route('login')
})
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*'));
    // Orden importa: Laravel usa el PRIMER callback cuya firma coincide y que devuelva no-null.
    $exceptions->render(fn (ValidationException $e, Request $r) => ApiError::for($r, 422, ErrorCode::ValidationError, 'The given data was invalid.', $e->errors()));
    $exceptions->render(fn (AuthenticationException $e, Request $r) => ApiError::for($r, 401, ErrorCode::Unauthenticated, $e->getMessage()));
    $exceptions->render(fn (ConflictException $e, Request $r) => ApiError::for($r, 409, ErrorCode::Conflict, $e->getMessage()));
    $exceptions->render(fn (HttpExceptionInterface $e, Request $r) => ApiError::fromHttpException($r, $e));   // match por getStatusCode() según la tabla 3.3; copia headers
    $exceptions->render(fn (Throwable $e, Request $r) => ApiError::for($r, 500, ErrorCode::ServerError, 'Server error.'));   // SIEMPRE el último
})
```

### 3.5 Middleware: orden y comportamiento

| Orden (global) | Middleware | Comportamiento |
|---|---|---|
| 1 | `AssignRequestId` | Toma `X-Request-Id` entrante solo si `Str::isUuid()`; si no, `Str::uuid()`. Guarda en `$request->attributes['request_id']`, `Log::shareContext(['request_id' => $id])`, y al volver pone `X-Request-Id` en la respuesta (también en errores: el pipeline de Laravel convierte la excepción en respuesta antes de salir de cada middleware). |
| 2 | `ForceJsonResponse` | Si `$request->is('api/*')`, `headers->set('Accept', 'application/json')`. |
| 3 | `SecurityHeaders` | Solo `api/*`, sobreescribe con `headers->set` (Laravel pone `Cache-Control: no-cache, private` por defecto). Ver 5.4. |
| 4 | `LimitPayloadSize` | Solo `api/*`. Si `Content-Length` o `strlen($request->getContent())` > `config('api.max_payload_kb') * 1024` => `throw new PostTooLargeException`. |
| (luego) | Los de Laravel (`HandleCors`, `TrimStrings`, `ConvertEmptyStringsToNull`...) y de ruta: `auth:sanctum` -> `throttle:api` -> `SubstituteBindings` (prioridad del Kernel). |

---

## 4. Endpoints

Convenciones de esta sección: «Auth» = middleware de ruta; «Autz» = Policy evaluada (ability del token + rol). Todos los endpoints salvo login llevan `Authorization: Bearer <token>`. Todos pueden devolver además `413`, `429`, `500` y `405` (método incorrecto) con el formato de 3.2; no se repiten en cada tabla salvo `429` donde es relevante.

### 4.0 Piezas compartidas

**Reglas de producto** (`StoreProductRequest`; `UpdateProductRequest` las hereda). `bail` primero en cada campo (una sola falla por campo; evita ejecutar `exists` con un entero fuera de rango):

```php
'name'               => ['bail', 'required', 'string', 'min:2', 'max:255'],           // TrimStrings ya recorta
'description'        => ['bail', 'nullable', 'string', 'max:2000'],
'price'              => ['bail', 'required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999999.99'],
'currency_id'        => ['bail', 'required', 'integer', 'min:1', 'exists:currencies,id'],
'tax_cost'           => ['bail', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
'manufacturing_cost' => ['bail', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
```

Mass assignment (AC-05.3): `id`, `created_at`, `deleted_at`, etc. no están en `rules()` => no aparecen en `validated()` => se ignoran.

**`ProductResource`** (lista blanca; sin `deleted_at`):

```json
{
  "id": 21,
  "name": "Laptop Pro 14",
  "description": null,
  "price": "1299.90",
  "currency_id": 1,
  "currency": { "id": 1, "code": "USD", "name": "US Dollar", "symbol": "$", "exchange_rate": "1.000000" },
  "tax_cost": "249.00",
  "manufacturing_cost": "800.00",
  "created_at": "2026-11-09T14:30:00.000000Z",
  "updated_at": "2026-11-09T14:30:00.000000Z"
}
```

`currency` con `whenLoaded('currency')` (los repositorios siempre la cargan).

**`ProductPriceResource`** (campos exactos de AC-09.1; sin timestamps):

```json
{ "id": 7, "product_id": 21, "currency_id": 2,
  "currency": { "id": 2, "code": "EUR", "name": "Euro", "symbol": "€", "exchange_rate": "0.920000" },
  "price": "1199.00" }
```

**Ejemplo de 422** (usado en varios endpoints): ver 3.2. **Ejemplo de 404**: `{"message":"Resource not found.","code":"not_found","request_id":"..."}`.

### 4.1 `POST /api/v1/auth/login`

| | |
|---|---|
| Auth / ability | Público. `throttle:login`. Sin ability. |
| Controller | `AuthController@login(LoginRequest)` -> `LoginData::fromValidated` -> `AuthService::login` -> `AuthTokenResource` |
| `LoginRequest::authorize()` | `true` |

```php
'email'       => ['bail', 'required', 'string', 'email', 'max:255'],
'password'    => ['bail', 'required', 'string', 'max:255'],
'device_name' => ['bail', 'nullable', 'string', 'max:100'],     // nombre del token; default 'api'
```

`200`:

```json
{ "data": { "token": "1|kZ3x...plaintext", "token_type": "Bearer",
            "expires_at": "2026-11-09T22:30:00.000000Z", "abilities": ["products:read", "products:write"] } }
```

Errores: `401 unauthenticated` (`Invalid credentials.`, idéntico para usuario inexistente y password errónea), `422 validation_error`, `429 too_many_requests`. Nunca se serializa `User` (AC-01.5).

### 4.2 `POST /api/v1/auth/logout`

| | |
|---|---|
| Auth / ability | `auth:sanctum`, `throttle:api`. Cualquier token válido (sin ability). |
| Controller | `AuthController@logout(Request)` -> `AuthService::logout($request->user())` -> `response()->noContent()` |

`204` sin body; el token queda borrado (el siguiente uso => 401). Errores: `401`, `429`.

### 4.3 `GET /api/v1/currencies`

| | |
|---|---|
| Autz | `CurrencyPolicy::viewAny` (`products:read`). Controller: `Gate::authorize('viewAny', Currency::class)`. |
| Controller | `CurrencyController@index(CurrencyRepositoryInterface)` -> `CurrencyResource::collection($repo->all())` |
| Request | Sin parámetros. |

`200`:

```json
{ "data": [
  { "id": 4, "code": "COP", "name": "Colombian Peso", "symbol": "$", "exchange_rate": "4100.000000" },
  { "id": 2, "code": "EUR", "name": "Euro", "symbol": "€", "exchange_rate": "0.920000" }
] }
```

Orden por `code`. Errores: `401`, `403`, `429`. `POST/PUT/DELETE /currencies` => `405` (no existen esas rutas; Laravel devuelve 405 porque la URI existe con GET).

### 4.4 `GET /api/v1/products`

| | |
|---|---|
| Autz | `ProductPolicy::viewAny` en `ListProductsRequest::authorize()` |
| Controller | `ProductController@index(ListProductsRequest)` -> `ProductFilterData::fromValidated` -> `ProductService::list` -> `ProductResource::collection($paginator)` |

Query (todas opcionales; `nullable` => `?x=` vacío equivale a ausente; parámetros desconocidos se ignoran):

```php
'page'        => ['bail', 'nullable', 'integer', 'min:1', 'max:100000'],   // max añadido: evita overflow del OFFSET => 500
'per_page'    => ['bail', 'nullable', 'integer', 'min:1', 'max:100'],      // default 15 (aplicado en el DTO)
'search'      => ['bail', 'nullable', 'string', 'max:100'],
'currency_id' => ['bail', 'nullable', 'integer', 'min:1', 'exists:currencies,id'],
'min_price'   => ['bail', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99',
                  Rule::when($this->filled('max_price'), ['lte:max_price'])],   // lte solo si hay max_price (si no, falla)
'max_price'   => ['bail', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
'sort'        => ['bail', 'nullable', 'string', Rule::in(ProductSortField::allowedValues())],   // default '-id'
```

`200` (ejemplo con `per_page=2`; `meta.links` abreviado):

```json
{
  "data": [ { "id": 21, "name": "Laptop Pro 14", "...": "ProductResource completo (4.0)" },
            { "id": 20, "name": "Laptop Air 13", "...": "..." } ],
  "links": { "first": "http://localhost/api/v1/products?per_page=2&page=1", "last": "http://localhost/api/v1/products?per_page=2&page=10",
             "prev": null, "next": "http://localhost/api/v1/products?per_page=2&page=2" },
  "meta": { "current_page": 1, "from": 1, "last_page": 10, "links": ["..."], "path": "http://localhost/api/v1/products",
            "per_page": 2, "to": 2, "total": 20 }
}
```

Queries ejecutadas por petición (excluida la auth): `count`, `select ... limit`, `select currencies where id in (...)` = **3, independiente de `per_page`** (RNF-08). Errores: `401`, `403`, `422`, `429`. Nota de contrato: `min_price`/`max_price` comparan solo el precio base (RN-11).

### 4.5 `POST /api/v1/products`

| | |
|---|---|
| Autz | `ProductPolicy::create` en `StoreProductRequest::authorize()` |
| Controller | `ProductController@store(StoreProductRequest)` -> `ProductData::fromValidated($request->validated())` -> `ProductService::create` -> `ProductResource` con `201` + `Location` |
| Body | Reglas de 4.0 (todas requeridas salvo `description`). |

Request:

```json
{ "name": "Laptop Pro 14", "description": null, "price": "1299.90", "currency_id": 1, "tax_cost": 249, "manufacturing_cost": "800.00" }
```

`201` + `Location: /api/v1/products/21` + `{ "data": <ProductResource> }`. Errores: `401`, `403`, `422`, `429`, `413`.

### 4.6 `GET /api/v1/products/{product}`

Autz: `Gate::authorize('view', $product)`. Controller: `ProductController@show(Product $product)` -> `new ProductResource($product)` (el binder ya cargó `currency`). `200 { "data": <ProductResource> }`. Errores: `401`, `403`, `404` (inexistente, soft-deleted o `{product}` no entero positivo), `429`.

### 4.7 `PUT /api/v1/products/{product}`

Autz: `ProductPolicy::update($product)` en `UpdateProductRequest::authorize()`. Body: **mismas reglas y todos los campos requeridos** (RN-09; sin campos => 422). Controller: `update(UpdateProductRequest, Product)` -> `ProductService::update($product, ProductData::fromValidated(...))` -> `ProductResource`. Cambio de `currency_id` base: sin conversión de `price` (RN-05); si la nueva divisa ya tiene precio => `422` con `errors.currency_id`.

`200 { "data": <ProductResource> }` con `updated_at` nuevo y `currency` recargada. Errores: `401`, `403`, `404`, `422`, `429`.

### 4.8 `DELETE /api/v1/products/{product}`

Autz: `Gate::authorize('delete', $product)`. Controller: `destroy(Product)` -> `ProductService::delete` -> `response()->noContent()`. `204`. Soft delete; `product_prices` intactos (RN-06). Segundo DELETE => 404 (el binder excluye eliminados). Errores: `401`, `403`, `404`, `429`.

### 4.9 `GET /api/v1/products/{product}/prices`

Autz: `Gate::authorize('view', $product)`. Controller: `ProductPriceController@index(Product)` -> `ProductPriceService::list` -> `ProductPriceResource::collection`. `200 { "data": [<ProductPriceResource>...] }`, orden `currencies.code`, sin paginar, sin precio base, `[]` si no hay. Queries: 2 (precios con join + currencies). Errores: `401`, `403`, `404`, `429`.

### 4.10 `POST /api/v1/products/{product}/prices`

Autz: `ProductPolicy::update($product)` en `StoreProductPriceRequest::authorize()`.

```php
'currency_id' => ['bail', 'required', 'integer', 'min:1', 'exists:currencies,id'],
'price'       => ['bail', 'required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999999.99'],
```

`product_id` del body se ignora (no está en `rules()`; AC-10.6). Controller: `store(StoreProductPriceRequest, Product)` -> `StoreProductPriceData::fromValidated` -> `ProductPriceService::create($product, $dto)` -> `ProductPriceResource` `201` + `Location: /api/v1/products/21/prices/2`.

Request `{ "currency_id": 2, "price": "1199.00" }` -> `201 { "data": <ProductPriceResource> }`.

Errores: `401`, `403`, `404`, `422` (campos; **o** `errors.currency_id` si es la divisa base, RN-04), `409` (RN-03 / carrera):

```json
{ "message": "A price already exists for this product and currency.", "code": "conflict", "request_id": "..." }
```

### 4.11 `PUT /api/v1/products/{product}/prices/{price}` (Should, US-11)

`{price}` = id de la divisa. Autz: `ProductPolicy::update($product)` en `UpdateProductPriceRequest::authorize()` (`$this->route('product')`).

```php
'price' => ['bail', 'required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999999.99'],
```

Cualquier otro campo (`currency_id`, `product_id`) se ignora. Controller: `update(UpdateProductPriceRequest, Product, ProductPrice $price)` -> `ProductPriceService::update($price, UpdateProductPriceData::fromValidated(...))` -> `ProductPriceResource`. `200 { "data": <ProductPriceResource> }`. Errores: `401`, `403`, `404` (producto inexistente/eliminado **o** sin precio en esa divisa; lo resuelve el binder `price`), `422`, `429`.

### 4.12 Matriz de verificación rápida (cruza con 01 sec. 2.2)

| Endpoint | Éxito | 401 | 403 | 404 | 409 | 422 |
|---|---|---|---|---|---|---|
| login | 200 | sí | - | - | - | sí |
| logout | 204 | sí | - | - | - | - |
| currencies | 200 | sí | sí | - | - | - |
| products index | 200 | sí | sí | - | - | sí |
| products store | 201 | sí | sí | - | - | sí |
| products show | 200 | sí | sí | sí | - | - |
| products update | 200 | sí | sí | sí | - | sí |
| products destroy | 204 | sí | sí | sí | - | - |
| prices index | 200 | sí | sí | sí | - | - |
| prices store | 201 | sí | sí | sí | sí | sí |
| prices update | 200 | sí | sí | sí | - | sí |

(`429` aplica a todos; `405`/`413`/`500` transversales.)

---

## 5. Seguridad

### 5.1 Sanctum

- `config/sanctum.php`: `'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 480)` (minutos = 8 h, RN-15) y **`'guard' => []`**. Con `['web']` (valor por defecto) Sanctum aceptaría un usuario de sesión como `TransientToken` con todas las abilities y se saltaría `products:write`. La API es 100 % stateless: no se llama a `statefulApi()`.
- Abilities: `Admin => [products:read, products:write]`, `Viewer => [products:read]` (`UserRole::abilities()`). El token nunca supera al rol: `ProductPolicy` exige rol Admin **y** ability para escribir.
- `createToken($name, $abilities, $expiresAt)` recibe siempre `expires_at` explícito para que `expires_at` de la respuesta coincida con la columna.
- Caducidad: Sanctum rechaza por `expires_at` y por `created_at + expiration`. Limpieza: en `routes/console.php`, `Schedule::command('sanctum:prune-expired --hours=24')->daily()`.
- `auth:sanctum` fija el guard por defecto en `sanctum`: dentro de limiters, policies y FormRequest basta `$request->user()`.
- Usuarios creados solo por seeder (`UserSeeder`, no corre en producción: `if (app()->isProduction()) return;`). `users.role` **no** es `fillable`; las factories lo fijan con states.

### 5.2 Rate limiting (`AppServiceProvider::boot`, valores desde `config/api.php` <- `.env`)

Los closures leen `config()` **en cada petición** (no al registrar) para que los tests puedan bajar el límite con `config(['api.rate_limits.login' => 2])`.

| Limiter | Aplicado en | Definición | `.env` (default) |
|---|---|---|---|
| `login` | `POST auth/login` | `Limit::perMinute(n)->by(strtolower(email string o '') . '\|' . $request->ip())` (si `email` no es string, usar `''`) | `RATE_LIMIT_LOGIN=5` |
| `api` | grupo autenticado | `Limit::perMinute(n)->by($request->user() ? 'user:'.$request->user()->id : 'ip:'.$request->ip())` | `RATE_LIMIT_API=60` |

- Se cuentan **todos** los intentos de login, no solo los fallidos: el 6.º en un minuto da `429` aunque sea correcto (AC-01.4).
- `ThrottleRequests` añade `X-RateLimit-Limit` y `X-RateLimit-Remaining` a toda respuesta, y en 429 `Retry-After` y `X-RateLimit-Reset`; `ApiError` los conserva (3.3).
- Límite conocido: `auth:sanctum` corre antes que `throttle`, así que los 401 por token inválido no consumen cuota del limiter `api`. Aceptado; ruta de mejora: `$middleware->prependToPriorityList(...)`.
- Store: `CACHE_STORE=database` (tabla `cache` existente); en tests `array`.

### 5.3 CORS (`config/cors.php`)

```php
'paths' => ['api/*'],
'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', ''))),   // vacío => ningún origen
'allowed_origins_patterns' => [],
'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Request-Id'],
'exposed_headers' => ['X-Request-Id', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After', 'Location'],
'max_age' => 600,
'supports_credentials' => false,   // Bearer, sin cookies: jamás '*' con credentials (RNF-06)
```

### 5.4 Cabeceras de seguridad (`SecurityHeaders`, solo `api/*`, toda respuesta incl. errores)

| Cabecera | Valor |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `no-referrer` |
| `Cache-Control` | `no-store` |
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'` (el 01 pide `default-src 'none'`; `frame-ancestors` es refuerzo) |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` solo si `$request->isSecure()` (detrás de proxy configurar `$middleware->trustProxies(at: ...)`) |

La UI de docs (`/docs/api`) queda fuera: la ruta no empieza por `api/`. Recomendar `expose_php=Off` en php.ini.

### 5.5 Resto de controles

| Riesgo | Control |
|---|---|
| Payload | `LimitPayloadSize` 64 KB (`API_MAX_PAYLOAD_KB`) => 413 `payload_too_large`. Además `client_max_body_size`/`post_max_size` en el servidor. |
| Mass assignment | `#[Fillable]` explícito por modelo; DTO desde `validated()`; repositorio mapea claves explícitas. |
| SQL injection | Solo Eloquent/bindings. `sort` por `Rule::in` + enum; `currency_id` entero validado; `search` con `addcslashes('\\%_')`. Prohibido `whereRaw`/`orderByRaw`/`DB::raw` con input. |
| Overflow numérico | `Route::pattern` de 18 dígitos; `integer` + `bail` antes de `exists`; `page <= 100000`. Garantiza que ninguna entrada de usuario llega a PG fuera de rango (=> 500). |
| Enumeración de usuarios | Mismo 401/mensaje y `Hash::make` ficticio en login. 404 sin distinguir "eliminado". |
| Exposición de datos | Resources con lista blanca; `$hidden` de `User` intacto; sin `deleted_at`. |
| Trazabilidad | `X-Request-Id` en toda respuesta y en el cuerpo de todo error; contexto de log compartido; logs de 401/403/429/5xx sin secretos. |
| Strict mode | `Model::shouldBeStrict(! app()->isProduction())` (lazy loading, atributos descartados, atributos inexistentes). `DB::prohibitDestructiveCommands(app()->isProduction())`. |
| N+1 | `with('currency')` en `paginate`, `findOrFail`, `listByProduct`; Resources con `whenLoaded`; test de nº de queries (sec. 8). |
| Config | `APP_DEBUG=false` en producción; `.env.example` sin secretos; token prefix opcional `SANCTUM_TOKEN_PREFIX`. |

### 5.6 Documentación OpenAPI (Scramble)

`config/scramble.php`:

- `'api_path' => 'api/v1'` (server `/api/v1`, rutas documentadas sin prefijo).
- `'middleware' => [RestrictedDocsAccess::class]` (quitar `web`: no hay sesión/CSRF en la API).
- `'security_strategy' => \Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy::class` (bearer automático para rutas con `auth:*`; login queda público sin anotar nada).
- `info.version` = `env('API_VERSION', '1.0.0')`.
- `AppServiceProvider`: `Gate::define('viewApiDocs', fn ($user = null) => (bool) config('api.docs_enabled'))`; `RestrictedDocsAccess` ya permite `local` sin gate. `API_DOCS_ENABLED=false` por defecto en `config/api.php`, `true` en `.env.example`.
- Exportar: `php artisan scramble:export --path=docs/openapi.json`.
- Scramble documenta 401/403/404/422 en su formato por defecto; el Specialist debe sobreescribir el schema de error con el de 3.2 (componente `Error` en `Scramble::afterOpenApiGenerated`) y añadir ejemplos. Los endpoints de `/up` y `/docs/*` no forman parte de la API.

---

## 6. Esquema de base de datos (PostgreSQL 18)

Migraciones nuevas, en este orden. Conexión `pgsql` con `'timezone' => 'UTC'` en `config/database.php` (el connector emite `SET time zone`). Los `CHECK` se crean con `DB::statement('ALTER TABLE ... ADD CONSTRAINT ...')` dentro de `up()`.

### `currencies` (sin timestamps)

| Columna | Tipo | Restricciones |
|---|---|---|
| `id` | `bigserial` | PK |
| `code` | `char(3)` | NOT NULL, `UNIQUE currencies_code_unique`, `CHECK currencies_code_format (code ~ '^[A-Z]{3}$')` |
| `name` | `varchar(100)` | NOT NULL, `UNIQUE currencies_name_unique` |
| `symbol` | `varchar(8)` | NOT NULL |
| `exchange_rate` | `numeric(18,6)` | NOT NULL, `CHECK currencies_exchange_rate_positive (exchange_rate > 0)` |

### `products`

| Columna | Tipo | Restricciones |
|---|---|---|
| `id` | `bigserial` | PK |
| `name` | `varchar(255)` | NOT NULL, `INDEX products_name_index` |
| `description` | `text` | NULL |
| `price` | `numeric(15,2)` | NOT NULL, `CHECK products_price_positive (price > 0)`, `INDEX products_price_index` |
| `currency_id` | `bigint` | NOT NULL, FK `currencies(id)` `ON DELETE RESTRICT`, **`INDEX products_currency_id_index` explícito** (PG no indexa FKs automáticamente) |
| `tax_cost` | `numeric(15,2)` | NOT NULL, `CHECK products_tax_cost_non_negative (tax_cost >= 0)` |
| `manufacturing_cost` | `numeric(15,2)` | NOT NULL, `CHECK products_manufacturing_cost_non_negative (manufacturing_cost >= 0)` |
| `created_at`, `updated_at`, `deleted_at` | `timestamptz` | NULL (`timestampsTz()`, `softDeletesTz()`) |

### `product_prices`

| Columna | Tipo | Restricciones |
|---|---|---|
| `id` | `bigserial` | PK |
| `product_id` | `bigint` | NOT NULL, FK `products(id)` `ON DELETE RESTRICT` |
| `currency_id` | `bigint` | NOT NULL, FK `currencies(id)` `ON DELETE RESTRICT`, `INDEX product_prices_currency_id_index` explícito |
| `price` | `numeric(15,2)` | NOT NULL, `CHECK product_prices_price_positive (price > 0)` |
| `created_at`, `updated_at` | `timestamptz` | NULL |
| | | `UNIQUE product_prices_product_id_currency_id_unique (product_id, currency_id)` (cubre también las búsquedas por `product_id`) |

### `users` (editar la migración existente, sec. 7)

Añadir `role varchar(20) NOT NULL DEFAULT 'viewer'` + `CHECK users_role_valid (role IN ('admin','viewer'))`. Se mantienen `personal_access_tokens` (Sanctum) y `cache`/`cache_locks`.

### Seeders

- `CurrencySeeder`: `Currency::upsert($rows, ['code'], ['name','symbol','exchange_rate'])` (idempotente). Filas: USD `US Dollar` `$` `1.000000`; EUR `Euro` `€` `0.920000`; GBP `Pound Sterling` `£` `0.790000`; COP `Colombian Peso` `$` `4100.000000`; MXN `Mexican Peso` `$` `17.000000` (ilustrativos, RN-13).
- `UserSeeder`: `admin@example.com` (role admin) y `viewer@example.com` (role viewer), password `password` documentada en README, solo no-producción. Usa `User::factory()->admin()` / `->viewer()` (las factories ignoran `fillable`).

---

## 7. Plan de limpieza del skeleton

Verificado contra el árbol actual del proyecto. **Borrar:**

| Archivo / carpeta | Motivo |
|---|---|
| `vite.config.js` | Sin frontend |
| `package.json` | Sin frontend (no existen `package-lock.json` ni `node_modules`) |
| `resources/js/` (`app.js`, `bootstrap.js`) | Sin frontend |
| `resources/css/` (`app.css`) | Sin frontend |
| `resources/views/welcome.blade.php` | Vista de bienvenida (dejar `resources/` vacío; si `GET /up` fallara por falta de vistas, conservar `resources/views/.gitkeep`) |
| `routes/web.php` | Sin rutas web; quitar `web:` de `withRouting` |
| `database/migrations/0001_01_01_000002_create_jobs_table.php` | `jobs`, `job_batches`, `failed_jobs`: no hay colas (`QUEUE_CONNECTION=sync`) |
| `tests/Feature/ExampleTest.php` | Pide `GET /` (ya no existe) |
| `tests/Unit/ExampleTest.php` | Placeholder |

**Editar (no borrar):**

| Archivo | Cambio |
|---|---|
| `routes/api.php` | Quitar la ruta `/user`; reemplazar por 1.5 |
| `routes/console.php` | Quitar el comando `inspire`; añadir el schedule de `sanctum:prune-expired` |
| `bootstrap/app.php` | Sec. 3.4 |
| `bootstrap/providers.php` | Añadir `RepositoryServiceProvider` |
| `database/migrations/0001_01_01_000000_create_users_table.php` | Quitar tablas `password_reset_tokens` y `sessions` (de `up()` y `down()`); añadir `role` + CHECK |
| `database/seeders/DatabaseSeeder.php` | Quitar el usuario `test@example.com`; llamar a `CurrencySeeder` y `UserSeeder` |
| `database/factories/UserFactory.php` | Añadir `role` por defecto `viewer` y states `admin()`, `viewer()` |
| `app/Models/User.php` | `use HasApiTokens`; cast `role` => `UserRole`; **no** añadir `role` a `#[Fillable]` |
| `composer.json` | Scripts: en `setup` quitar `npm install`/`npm run build`; en `dev` quitar `npx concurrently`/`queue:listen`/`npm run dev` (dejar `php artisan serve`); borrar `post-create-project-cmd` (toca sqlite); añadir `lint` (`pint --test`), `analyse` (`phpstan analyse --memory-limit=1G`), `check` (lint + analyse + test). Añadir `larastan/larastan` en `require-dev`. |
| `phpunit.xml` | Sec. 8.1 |
| `.env.example` | Quitar `VITE_APP_NAME`, `AWS_*`, `MAIL_*`, `REDIS_*`, `MEMCACHED_HOST`, `BROADCAST_CONNECTION`; `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`; añadir las variables nuevas (abajo). Sincronizar el `.env` local (hoy `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`). |
| `README.md` | Sustituir el README de Laravel por el del proyecto (requisito 01 sec. 4.5) |

**No borrar** `config/mail.php`, `queue.php`, `session.php`, `filesystems.php`, `services.php`: Laravel 13 no aplica defaults si falta el archivo y algún componente puede leerlos. `.gitignore` (entradas `node_modules`, `public/build`) es inofensivo.

Variables nuevas de `.env.example`:

```
APP_NAME="Beos Products API"
SANCTUM_TOKEN_EXPIRATION=480
RATE_LIMIT_LOGIN=5
RATE_LIMIT_API=60
API_MAX_PAYLOAD_KB=64
CORS_ALLOWED_ORIGINS=http://localhost:3000
API_DOCS_ENABLED=true
API_VERSION=1.0.0
```

---

## 8. Estrategia de tests y calidad

### 8.1 Entorno

- PHPUnit 12: métodos `test_*` y atributo `#[DataProvider('...')]` (las anotaciones docblock `@dataProvider`/`@test` ya no existen). Sin Pest.
- BD **PostgreSQL** `beos_products_test` (crearla una vez: `CREATE DATABASE beos_products_test;`). `phpunit.xml`: `DB_CONNECTION=pgsql`, `DB_DATABASE=beos_products_test` con `force="true"` (**obligatorio**: `RefreshDatabase` vaciaría `beos_products` si el valor lo sobreescribe el `.env`), `DB_URL=""`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `BCRYPT_ROUNDS=4`, `API_DOCS_ENABLED=true`.
- `<source><include>` limitado a `app/Services`, `app/Http`, `app/Repositories` (umbral 80 % del 01). Cobertura: `php artisan test --coverage --min=80` (PCOV/Xdebug).
- Features: `use RefreshDatabase;` por clase. Trait `Tests\Concerns\ActsAsApiUser` con `actingAsAdmin()` / `actingAsViewer()` (`Sanctum::actingAs($user, $abilities)`) y `actingWithAbilities(array)`.
- Login/logout/expiración usan **tokens reales** (`createToken` + header `Authorization`), no `actingAs`. Tras `logout`, llamar `$this->app['auth']->forgetGuards()` antes de la segunda petición (el guard cachea el usuario dentro del mismo test).
- Datos: factories `User` (admin/viewer), `Currency`, `Product`, `ProductPrice`. `ProductFactory` genera montos como string de 2 decimales (`number_format(..., 2, '.', '')`).

### 8.2 Feature (por endpoint)

```
tests/Feature/
├── Auth/{LoginTest, LogoutTest}.php
├── Currency/IndexCurrenciesTest.php
├── Product/{ListProductsTest, StoreProductTest, ShowProductTest, UpdateProductTest, DeleteProductTest}.php
├── ProductPrice/{ListPricesTest, StorePriceTest, UpdatePriceTest}.php
└── Http/{ErrorFormatTest, SecurityHeadersTest, RequestIdTest, RateLimitTest, PayloadLimitTest, CorsTest}.php
```

Cada clase cubre lo que marca el 01 sec. 5.2: camino feliz con **estructura JSON exacta** (`assertExactJson`/`assertJsonStructure`), `401` (sin token / inválido / expirado), `403` (viewer y token sin ability), `404` (inexistente, eliminado, `abc`, `0`, `-1`, `99999999999999999999`), `422` por cada regla de 3.3 vía data provider, `409` precio duplicado (y carrera simulada con repo que lanza `UniqueConstraintViolationException`), `429` (límite bajado por `config()`), formato de error unificado + `X-Request-Id`, cabeceras de seguridad, mass assignment ignorado, persistencia en BD (`assertDatabaseHas`, `assertSoftDeleted`).

Casos específicos a no olvidar:

- T-08: viewer + body inválido => `403`, no `422`.
- T-09: producto inexistente + body inválido => `404`; precio inexistente (`PUT .../prices/{price}`) + body inválido => `404`; RN-04 (422) antes que RN-03 (409).
- AC-07.3 / RN-05: cambiar base a divisa con precio => `422 errors.currency_id`.
- AC-08.2: tras `DELETE`, `GET`/`GET prices`/`DELETE` => `404`; filas de `product_prices` siguen en BD.
- AC-04.4: `search=100%` y `search=a_b` literales (productos "100% algodón", "a_b" y "axb").
- Listado: `per_page=101|0`, `page=0|100001`, `min_price > max_price`, `sort=password` => `422`; queries fijas: sembrar 3 y 30 productos, `DB::enableQueryLog()`, mismo nº de queries en ambos (idem `GET prices`).
- Payload: body > 64 KB => `413 payload_too_large` con JSON; `PATCH /products/1` => `405`; `GET /api/v1/nope` => `404` JSON con `X-Request-Id` y cabeceras de seguridad; `X-Request-Id` entrante válido se respeta, inválido se reemplaza.
- 500: ruta de test que lanza excepción => `{message:'Server error.', code:'server_error', request_id}` sin `trace`/`file`/`exception`, con `APP_DEBUG=true`.
- Login: usuario inexistente y password errónea => cuerpo idéntico; 6.º intento => `429` con `Retry-After`; respuesta sin `password`/`remember_token`.
- CORS: preflight `OPTIONS` desde origen permitido y no permitido.
- DB: violaciones directas de `CHECK` y `UNIQUE` (insert por `DB::table`) lanzan `QueryException`, para probar la defensa en profundidad (RNF-10).

### 8.3 Unit

```
tests/Unit/
├── Services/{ProductServiceTest, ProductPriceServiceTest}.php   # repos con createMock() (con expectations) / createStub() (sin ellas; PHPUnit 12 avisa de mocks sin expectativas); sin BD
├── Dtos/{ProductDataTest, ProductFilterDataTest, StoreProductPriceDataTest}.php
├── Policies/{ProductPolicyTest, CurrencyPolicyTest}.php         # matriz rol x ability x acción
├── Resources/{ProductResourceTest, ProductPriceResourceTest}.php
└── Support/MoneyTest.php
```

- Services: delegación correcta; RN-04 (`ValidationException` con `currency_id`), RN-03 (`ConflictException` si `exists`), carrera (repo lanza `UniqueConstraintViolationException` => `ConflictException`), RN-05 (solo valida si cambia la base), orden 422 antes que 409. Verificar con `never()` que no se llama a `create` cuando falla una regla.
- DTOs: `fromValidated` mapea; montos quedan `string` según `Money::normalize(int|float|string): string` (string: se devuelve tal cual; int/float: `number_format((float) $v, 2, '.', '')`, o sea `19.99` -> `"19.99"`, `20` -> `"20.00"`, `"19.9"` -> `"19.9"`); defaults de `ProductFilterData` (`perPage=15`, `page=1`, `sort=id`, `descending=true`).
- Policies: viewer con `products:write` en el token sigue denegado; admin con solo `products:read` denegado en escritura; usuario sin token denegado. Se crean con `User::factory()->make([...])->withAccessToken(new PersonalAccessToken(['abilities' => [...]]))` (extienden `Tests\TestCase`; no necesitan BD).
- Resources: claves exactas, sin `deleted_at`, `currency` ausente si no está cargada (`whenLoaded`).
- `Money`: float con muchos dígitos (`9999999999999.99`) no pasa por `(string)` (`precision=14` lo convertiría en `1.0E+13`); usar `number_format((float) $v, 2, '.', '')` para float/int y dejar los string como están.

### 8.4 Herramientas de calidad

- **Laravel Pint**: preset `laravel` por defecto (sin `pint.json`). CI/local: `vendor/bin/pint --test`.
- **Larastan**: `composer require --dev larastan/larastan`. `phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon
parameters:
    level: 6          # el 01 exige >= 5; 6 obliga a tipar iterables (@param array<string, mixed>), vale la pena en DTOs/repos
    paths:
        - app
```

  Sin baseline. Si el nivel 6 bloquea más de 1 h, bajar a 5 (cumple el DoD) y dejarlo anotado en el README.
- Comprobación local completa: `composer check` (pint --test + phpstan + tests).

---

## 9. Orden de implementación sugerido

1. Limpieza (sec. 7) + `composer require --dev larastan/larastan` + publicar `cors` y `scramble` + `config/api.php` + `.env.example`.
2. Migraciones, modelos, factories, seeders; `migrate:fresh --seed` limpio; crear `beos_products_test`.
3. Enums, `Support/{ApiError,Money}`, `ConflictException`, middleware, `bootstrap/app.php`; test de formato de error y cabeceras.
4. Auth (`LoginRequest`, DTOs, `AuthService`, controller, resource, limiter `login`) + tests.
5. Repositorios + `RepositoryServiceProvider` + binders de ruta.
6. Currencies, Products (CRUD), Prices; Policies junto a cada endpoint; tests feature y unit.
7. Scramble (sec. 5.6), `docs/openapi.json`, colección Postman, README; Pint + Larastan en verde.

---

## 10. Desviaciones

Cambios respecto al contrato de las secciones 1 a 9, descubiertos durante la implementacion. Hasta la fila 21 ninguno alteraba el comportamiento observable descrito en el 01. **La fila 22 si lo hace: reduce el alcance a los 7 endpoints del enunciado.** Las secciones 1 a 9 y las filas 3, 7 y 11 describen codigo retirado por esa fila (login, logout, divisas, correccion de precios) y se conservan solo como historial de diseno.

| # | Contrato | Implementado | Motivo |
|---|---|---|---|
| 1 | `Cache-Control: no-store` exacto (5.4) | La cabecera sale como `no-store, private` | Symfony (`ResponseHeaderBag`) anade `private` cuando no hay `public`/`private`/`s-maxage`. Es mas restrictivo, no menos; `no-store` sigue presente. Los tests comprueban la presencia de `no-store` |
| 2 | Preflight CORS desde origen no permitido: sin cabeceras CORS (implicito en 5.3) | Con un unico origen configurado, `Access-Control-Allow-Origin` devuelve siempre ese origen configurado (nunca el del solicitante ni `*`) | Comportamiento del middleware CORS de Laravel/Symfony; el navegador rechaza la respuesta porque el origen no coincide. El test verifica que jamas se concede el origen ajeno ni `*` |
| 3 | `expires_at` calculado con `now()->addMinutes(...)` (5.1) | Se trunca a segundos (`startOfSecond()`) | La columna `personal_access_tokens.expires_at` es `timestamp` sin fraccion; sin truncar, el valor de la respuesta diferia de la columna (el contrato exige que coincidan) |
| 4 | `phpstan.neon` con `level: 6` y `paths: app` (8.4) | Se anade `parseModelCastsMethod: true` | Sin esa opcion Larastan ignora el metodo `casts()` (Laravel 11+) y tipa `User::$role` como `string`, lo que produce falsos positivos en `ProductPolicy` y `AuthService`. Nivel 6 sin baseline, sin bajar de nivel |
| 5 | `User` con `HasApiTokens` anadido (7) | Ademas se retira el trait `Notifiable` | La API no envia notificaciones ni correo; era codigo muerto del skeleton |
| 6 | Scramble: sobreescribir el schema de error (5.6) | Transformer `App\Support\ErrorResponsesDocumentation` registrado en `AppServiceProvider` | Define los schemas `Error` y `ValidationError`, sustituye las respuestas 401/403/404/422 por defecto, anade 409 (alta de precio), 413 (operaciones con body) y 429 (con `Retry-After` y `X-RateLimit-*`) con ejemplos, y elimina de `components.responses` las respuestas genericas de Scramble |
| 7 | Binder `price` usa `$route->parameter('product')->id` (1.5) | Comprueba `instanceof Product` y lanza `NotFoundHttpException` si no se resolvio | Tipado estricto exigido por Larastan; en la practica `{product}` siempre se resuelve antes por orden de URI |
| 8 | `composer.json`: `larastan/larastan` en `require-dev` (7) | `^3.14` | Composer lo habia fijado como `*` por `minimum-stability: dev` |
| 9 | Tests (8.2/8.3) | Se anaden `Feature/Database/ConstraintsTest`, `Feature/Http/DocsTest` y `Unit/ArchitectureTest` | Cubren RNF-10 (CHECK/UNIQUE/FK), RNF-07 (todas las rutas `api/v1` documentadas, flag de docs) y las reglas de capas del DoD (sin queries en controllers/Resources, DTOs `final readonly`, sin `$guarded`/`$request->all()`/SQL crudo) |
| 10 | Cobertura >= 80 % (8.1) | Medida con Xdebug: 99,7 % sobre todo `app/` (el `<source>` de `phpunit.xml` pasa de `Services`/`Http`/`Repositories` a `app`) y 100 % en esas tres carpetas | Comando: `XDEBUG_MODE=coverage php artisan test --coverage --min=80` (documentado en el README) |
| 11 | `AuthService::login` ejecuta `Hash::make($password)` ficticio si el email no existe (1.4) | Ejecuta `Hash::check($password, TIMING_HASH)` contra un hash bcrypt constante (coste 12) y nunca hashea la entrada del usuario | Con PHP 8.4+ bcrypt lanza al recibir un password con NUL: el email inexistente daba `500` y el existente `401` (enumeracion de usuarios). Ademas `LoginRequest` rechaza NUL con `422` |
| 12 | Reglas de campos string sin regla de contenido (3.3, 4.0, 4.1, 4.4) | Nueva regla `App\Rules\SafeString` (UTF-8 valido, sin NUL) en `email`, `password`, `device_name`, `name`, `description` y `search` | PostgreSQL rechaza bytes invalidos y NUL (`SQLSTATE 22021` => `500`); un NUL en `name` se truncaba en BD pero la respuesta `201` devolvia el valor sin truncar. Ahora ambos casos dan `422` con `errors.<campo>` |
| 13 | Reglas de dinero repetidas en cada FormRequest (4.0, 4.4, 4.10, 4.11) | `Money::rules(string $min = '0')` y `Money::MAX` en un unico lugar | Menos duplicacion; el OpenAPI generado es identico |
| 14 | Sanctum con el modelo por defecto (5.1) | `App\Models\PersonalAccessToken` sobrescribe `findToken()` y exige el prefijo `^[1-9][0-9]{0,17}\|`; registrado con `Sanctum::usePersonalAccessTokenModel()` en `AppServiceProvider` | Un Bearer con id fuera de bigint (`99999999999999999999\|x`) llegaba a PostgreSQL (`SQLSTATE 22003` => `500`). Ahora `401 unauthenticated` |
| 15 | Middleware globales de 3.5 | Se anade `RejectMalformedJson` (tras `LimitPayloadSize`): si el body de una ruta `api/*` es JSON y no valida, lanza `BadRequestHttpException` | Un JSON malformado daba `422` "name is required"; el contrato (3.3) pide `400 bad_request`. Un body vacio sigue llegando a la validacion (`422`) |
| 16 | `Request::is('api/*')` para detectar rutas de API (3.4, 3.5) | `App\Support\ApiRequest::matches()` (prefijo `api/` sobre el path decodificado, comparacion por bytes) | `Request::is()` usa una regex con `/u` y devuelve `false` con rutas que no son UTF-8 valido (`/api/v1/products/%ff`): la respuesta salia en HTML y sin cabeceras de seguridad (T-04). Ahora sale `400 bad_request` en JSON |
| 17 | `ConflictException` se reportaba como error (3.4) | `$exceptions->dontReport(ConflictException::class)` en `bootstrap/app.php` | Un `409` es un resultado de negocio esperado; no debe generar `ERROR` con stack trace en el log |
| 18 | Rutas del skeleton y de paquetes | `config/sanctum.php`: `'routes' => false` (desactiva `/sanctum/csrf-cookie`, que emite cookies de sesion; Sanctum 4.3 no tiene `Sanctum::ignoreRoutes()`). `config/filesystems.php`: disco `local` con `'serve' => false` (sin `/storage/{path}`). `config/scramble.php`: `dev_tools.enabled` solo con `SCRAMBLE_DEV_TOOLS=true` (ya no sigue a `APP_DEBUG`, que publicaba `/_scramble/dev-tools/devtools.js`) | `route:list` muestra solo las 11 rutas de API, `docs/api`, `docs/api.json` y `up`; un test lo fija |
| 19 | Sin proteccion del header `Host` (5.5) | `Request::setTrustedHosts()` en `AppServiceProvider::boot()` con el host de `APP_URL` y sus subdominios (y `127.0.0.1` / `[::1]` en `local` y `testing`). Un `Host` ajeno recibe `400 bad_request` | Evita que `Host: evil.test` se refleje en `links` y `meta.path` de la paginacion. No se usa el middleware `TrustHosts` del framework porque se desactiva en `local` y en tests. En produccion `APP_URL` debe ser la URL publica real |
| 20 | Codigos del catalogo 3.3 | `bad_request` (400) tambien cubre JSON malformado, URL malformada y host no confiable; se documenta `400` en las operaciones con body | Coherencia con la fila `bad_request` de 3.3 |
| 21 | Limpieza del skeleton (7) | `composer.json`: `name` `beos/products-api` y descripcion propia; `config/auth.php`: sin broker de passwords (`passwords => []`, no hay reset y apuntaba a la tabla inexistente `password_reset_tokens`); eliminado `public/favicon.ico` (vacio) | Restos del skeleton sin uso |
| 22 | **Alcance (1.5, 4.1 a 4.3, 4.11, 5.2)**: 11 endpoints | **Solo los 7 del enunciado**: `GET/POST /products`, `GET/PUT/DELETE /products/{product}`, `GET/POST /products/{product}/prices`, todos con `auth:sanctum`, `throttle:api`, abilities y Policies | Cambio de alcance pedido por el usuario. Se eliminan `POST /auth/login`, `POST /auth/logout`, `GET /currencies` y `PUT /products/{product}/prices/{price}` junto con su codigo: `AuthController`, `AuthService`, `LoginRequest`, `LoginData`, `AuthTokenData`, `AuthTokenResource`, `CurrencyController`, `CurrencyPolicy`, `CurrencyRepositoryInterface` y su implementacion, `UpdateProductPriceRequest`, `UpdateProductPriceData`, el binder y patron de ruta `price`, los metodos `update` y `findByProductAndCurrencyOrFail` de servicio y repositorio de precios, el limiter `login` y `RATE_LIMIT_LOGIN`. Se conservan `Currency`, su migracion, `CurrencySeeder` y `CurrencyResource` (la divisa viaja embebida en producto y precio). El login y el `PUT` de precios llegaron a implementarse y probarse antes de retirarse |
| 23 | Autenticacion con login y tokens de 8 h (5.1) | Sin login. `UserSeeder` (solo fuera de `production`) crea un token demo por usuario con el secreto de `DEMO_ADMIN_TOKEN` / `DEMO_VIEWER_TOKEN` (`config('api.demo_tokens')`) y `php artisan api:token {email} {--name=}` emite tokens nuevos con las abilities del rol e imprime el valor completo | Sin login hace falta otra via para obtener un token. El valor que envia el cliente es `{id}\|{secreto}`; en una base recien sembrada los ids son `1` (admin) y `2` (viewer). El seeder imprime los valores completos |
| 24 | `sanctum.expiration` = 480 min (5.1, RN-15) | `sanctum.expiration` = `null`; cada token lleva su `expires_at`. `api:token` usa `API_TOKEN_TTL_MINUTES` (480 por defecto, sustituye a `SANCTUM_TOKEN_EXPIRATION`); los tokens demo tienen `expires_at` nulo y no caducan | Con `expiration` configurado Sanctum tambien caduca por `created_at`, lo que invalidaria los tokens demo a las 8 h de sembrar. Los tokens demo no existen en produccion |
| 25 | `Location` del `201` de precio: `/api/v1/products/{id}/prices/{currency_id}` (2) | `/api/v1/products/{id}/prices` | Ya no existe una ruta por precio; se apunta al listado |
| 26 | Rate limit de login 5/min por email+IP (RN-14, 5.2) | Eliminado; queda solo `throttle:api` (60/min por usuario) | Sin endpoint de login no hay nada que limitar |
| 27 | `UserRole::abilities()` devolvia `TokenAbility[]` (1.4) | Devuelve `list<string>` | Lo consumen el comando `api:token` y el seeder, ambos necesitan los strings |
| 28 | Ids de divisa sin definir (6, Seeders) | Sin `GET /currencies`, los ids salen del orden del seeder: USD 1, EUR 2, GBP 3, COP 4, MXN 5 en una base recien migrada (`migrate:fresh --seed`) | Es la unica forma de que el cliente conozca el `currency_id`; la coleccion Postman usa `usd_id=1` y `eur_id=2` y un test fija el orden |

Notas de entorno (no son desviaciones del contrato):

- El `.env` local conservaba un BOM UTF-8 que rompia la lectura de la primera variable (`APP_NAME`); se elimino. `.env.example` se entrega sin BOM.
- Con `APP_ENV=local`, `RestrictedDocsAccess` publica `/docs/api` aunque `API_DOCS_ENABLED=false` (comportamiento de Scramble, ya previsto en 5.6).
- `APP_URL` define el unico host aceptado (ver desviacion 19). Con `php artisan serve` basta `APP_URL=http://localhost:8000`; `127.0.0.1` tambien funciona en `local`.
