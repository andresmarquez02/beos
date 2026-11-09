<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\DTOs\ProductData;
use App\DTOs\ProductFilterData;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\ListProductsRequest;
use App\Http\Requests\V1\StoreProductRequest;
use App\Http\Requests\V1\UpdateProductRequest;
use App\Http\Resources\V1\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    /**
     * Listar productos
     *
     * Paginado, con filtros y ordenamiento. `min_price` y `max_price` comparan solo el precio base del producto.
     * No incluye productos eliminados.
     */
    public function index(ListProductsRequest $request): AnonymousResourceCollection
    {
        return ProductResource::collection(
            $this->products->list(ProductFilterData::fromValidated($request->validated())),
        );
    }

    /**
     * Crear un producto
     *
     * Requiere el rol `admin` y la ability `products:write`. Responde `201` con la cabecera `Location`.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->products->create(ProductData::fromValidated($request->validated()));

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('v1.products.show', $product, false));
    }

    /**
     * Ver un producto
     */
    public function show(Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return new ProductResource($product);
    }

    /**
     * Reemplazar un producto
     *
     * `PUT` es un reemplazo total: hay que enviar todos los campos obligatorios. Cambiar `currency_id` no convierte `price`.
     * Si el producto ya tiene un precio registrado en la nueva divisa base, responde `422`.
     */
    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        return new ProductResource(
            $this->products->update($product, ProductData::fromValidated($request->validated())),
        );
    }

    /**
     * Eliminar un producto
     *
     * Borrado lógico: sus precios se conservan pero dejan de ser accesibles.
     */
    public function destroy(Product $product): Response
    {
        Gate::authorize('delete', $product);

        $this->products->delete($product);

        return response()->noContent();
    }
}
