<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\DTOs\StoreProductPriceData;
use App\Exceptions\ConflictException;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Repositories\Contracts\ProductPriceRepositoryInterface;
use App\Services\ProductPriceService;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionParameter;
use Tests\TestCase;

class ProductPriceServiceTest extends TestCase
{
    public function test_list_delegates_to_the_repository(): void
    {
        $product = $this->product();
        $prices = new Collection;

        $repository = $this->createMock(ProductPriceRepositoryInterface::class);
        $repository->expects($this->once())->method('listByProduct')->with($product)->willReturn($prices);

        $this->assertSame($prices, (new ProductPriceService($repository))->list($product));
    }

    public function test_create_persists_a_price_in_a_different_currency(): void
    {
        $product = $this->product();
        $data = new StoreProductPriceData(2, '10.00');
        $created = new ProductPrice;

        $repository = $this->createMock(ProductPriceRepositoryInterface::class);
        $repository->expects($this->once())->method('existsForProductAndCurrency')->with(10, 2)->willReturn(false);
        $repository->expects($this->once())->method('create')->with($product, $data)->willReturn($created);

        $this->assertSame($created, (new ProductPriceService($repository))->create($product, $data));
    }

    public function test_create_rejects_the_product_base_currency_before_any_lookup(): void
    {
        $repository = $this->createMock(ProductPriceRepositoryInterface::class);
        $repository->expects($this->never())->method('existsForProductAndCurrency');
        $repository->expects($this->never())->method('create');

        try {
            (new ProductPriceService($repository))->create($this->product(), new StoreProductPriceData(1, '10.00'));
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('currency_id', $e->errors());
        }
    }

    public function test_create_throws_conflict_when_a_price_already_exists(): void
    {
        $repository = $this->createMock(ProductPriceRepositoryInterface::class);
        $repository->method('existsForProductAndCurrency')->willReturn(true);
        $repository->expects($this->never())->method('create');

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('A price already exists for this product and currency.');

        (new ProductPriceService($repository))->create($this->product(), new StoreProductPriceData(2, '10.00'));
    }

    public function test_create_maps_a_unique_violation_race_to_conflict(): void
    {
        $race = new UniqueConstraintViolationException('pgsql', 'insert', [], new Exception('duplicate key'));

        $repository = $this->createStub(ProductPriceRepositoryInterface::class);
        $repository->method('existsForProductAndCurrency')->willReturn(false);
        $repository->method('create')->willThrowException($race);

        try {
            (new ProductPriceService($repository))->create($this->product(), new StoreProductPriceData(2, '10.00'));
            $this->fail('Expected a ConflictException.');
        } catch (ConflictException $e) {
            $this->assertSame($race, $e->getPrevious());
        }
    }

    public function test_service_depends_only_on_the_repository_interface(): void
    {
        $constructor = (new ReflectionClass(ProductPriceService::class))->getConstructor();
        $this->assertNotNull($constructor);

        $types = array_map(
            fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters(),
        );

        $this->assertSame([ProductPriceRepositoryInterface::class], $types);
    }

    private function product(): Product
    {
        return Product::factory()->make(['id' => 10, 'currency_id' => 1]);
    }
}
