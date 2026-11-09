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
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->char('code', 3)->unique('currencies_code_unique');
            $table->string('name', 100)->unique('currencies_name_unique');
            $table->string('symbol', 8);
            $table->decimal('exchange_rate', 18, 6);
        });

        DB::statement("ALTER TABLE currencies ADD CONSTRAINT currencies_code_format CHECK (code ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE currencies ADD CONSTRAINT currencies_exchange_rate_positive CHECK (exchange_rate > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
