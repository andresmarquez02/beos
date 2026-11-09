<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\DTOs\ProductData;
use App\DTOs\ProductFilterData;
use App\Models\Product;
use App\Repositories\Contracts\ProductPriceRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\ProductService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionParameter;
use Tests\TestCase;

class ProductServiceTest extends TestCase
{
    public function test_list_delegates_to_the_repository(): void
    {
        $filters = new ProductFilterData(search: 'lap');
        $page = new LengthAwarePaginator([], 0, 15);

        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->expects($this->once())->method('paginate')->with($filters)->willReturn($page);

        $service = new ProductService($products, $this->createStub(ProductPriceRepositoryInterface::class));

        $this->assertSame($page, $service->list($filters));
    }

    public function test_create_delegates_to_the_repository(): void
    {
        $data = $this->data(currencyId: 1);
        $created = new Product;

        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->expects($this->once())->method('create')->with($data)->willReturn($created);

        $service = new ProductService($products, $this->createStub(ProductPriceRepositoryInterface::class));

        $this->assertSame($created, $service->create($data));
    }

    public function test_update_rejects_new_base_currency_that_already_has_a_price(): void
    {
        $product = $this->product(id: 10, currencyId: 1);

        $prices = $this->createMock(ProductPriceRepositoryInterface::class);
        $prices->expects($this->once())->method('existsForProductAndCurrency')->with(10, 2)->willReturn(true);
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->expects($this->never())->method('update');

        $service = new ProductService($products, $prices);

        try {
            $service->update($product, $this->data(currencyId: 2));
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('currency_id', $e->errors());
        }
    }

    public function test_update_allows_new_base_currency_without_a_price(): void
    {
        $product = $this->product(id: 10, currencyId: 1);
        $data = $this->data(currencyId: 2);

        $prices = $this->createStub(ProductPriceRepositoryInterface::class);
        $prices->method('existsForProductAndCurrency')->willReturn(false);
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->expects($this->once())->method('update')->with($product, $data)->willReturn($product);

        $this->assertSame($product, (new ProductService($products, $prices))->update($product, $data));
    }

    public function test_update_skips_the_price_check_when_base_currency_is_unchanged(): void
    {
        $product = $this->product(id: 10, currencyId: 1);
        $data = $this->data(currencyId: 1);

        $prices = $this->createMock(ProductPriceRepositoryInterface::class);
        $prices->expects($this->never())->method('existsForProductAndCurrency');
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->expects($this->once())->method('update')->with($product, $data)->willReturn($product);

        (new ProductService($products, $prices))->update($product, $data);
    }

    public function test_delete_delegates_to_the_repository(): void
    {
        $product = $this->product(id: 10, currencyId: 1);

        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->expects($this->once())->method('delete')->with($product);

        (new ProductService($products, $this->createStub(ProductPriceRepositoryInterface::class)))->delete($product);
    }

    public function test_service_depends_only_on_repository_interfaces(): void
    {
        $constructor = (new ReflectionClass(ProductService::class))->getConstructor();
        $this->assertNotNull($constructor);

        $types = array_map(
            fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters(),
        );

        $this->assertSame([ProductRepositoryInterface::class, ProductPriceRepositoryInterface::class], $types);
    }

    private function product(int $id, int $currencyId): Product
    {
        return Product::factory()->make(['id' => $id, 'currency_id' => $currencyId]);
    }

    private function data(int $currencyId): ProductData
    {
        return new ProductData('Name', null, '10.00', $currencyId, '1.00', '2.00');
    }
}
