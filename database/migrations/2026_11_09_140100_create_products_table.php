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
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255)->index('products_name_index');
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2)->index('products_price_index');
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('tax_cost', 15, 2);
            $table->decimal('manufacturing_cost', 15, 2);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('currency_id', 'products_currency_id_index');
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_positive CHECK (price > 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_tax_cost_non_negative CHECK (tax_cost >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_manufacturing_cost_non_negative CHECK (manufacturing_cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
