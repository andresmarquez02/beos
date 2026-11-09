<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\DTOs\StoreProductPriceData;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreProductPriceRequest;
use App\Http\Resources\V1\ProductPriceResource;
use App\Models\Product;
use App\Services\ProductPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProductPriceController extends Controller
{
    public function __construct(private readonly ProductPriceService $prices) {}

    /**
     * Listar los precios de un producto
     *
     * Precios adicionales en otras divisas, ordenados por código de divisa. No incluye el precio base.
     */
    public function index(Product $product): AnonymousResourceCollection
    {
        Gate::authorize('view', $product);

        return ProductPriceResource::collection($this->prices->list($product));
    }

    /**
     * Registrar un precio en una divisa
     *
     * La divisa debe ser distinta de la divisa base del producto (`422`). Si ya existe un precio en esa divisa, responde `409`. Responde `201` con la cabecera `Location` apuntando a la lista de precios del producto.
     */
    public function store(StoreProductPriceRequest $request, Product $product): JsonResponse
    {
        $price = $this->prices->create($product, StoreProductPriceData::fromValidated($request->validated()));

        return (new ProductPriceResource($price))
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('v1.products.prices.index', $product, false));
    }
}
