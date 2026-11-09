<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('price', 15, 2);
            $table->timestampsTz();

            $table->index('currency_id', 'product_prices_currency_id_index');
            $table->unique(['product_id', 'currency_id'], 'product_prices_product_id_currency_id_unique');
        });

        DB::statement('ALTER TABLE product_prices ADD CONSTRAINT product_prices_price_positive CHECK (price > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
