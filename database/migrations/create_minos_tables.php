<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPinnacle\Minos\Models\PaymentMethod;

return new class extends Migration {
    public function up(): void
    {
        /** @see PaymentMethod */
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('provider');
            $table->text('settings')->nullable();
            $table
                ->unsignedInteger('sort')
                ->index()
                ->default(0);
            $table->boolean('is_online')->default(false);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $this->addTenancy($table);
        });

        /** @see PaymentPlan */
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key');
            $table->string('name');
            $table->json('parts')->default('{}');
            $table
                ->unsignedInteger('sort')
                ->index()
                ->default(0);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $this->addTenancy($table);
        });

        /** @see CreditCard */
        Schema::create('payment_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('customer');
            $table
                ->foreignIdFor(PaymentMethod::class, 'method_id')
                ->index()
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('token', 4096);
            $table->string('product')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('brand')->nullable();
            $table->string('subbrand')->nullable();
            $table->string('bin')->nullable();
            $table->string('mask')->nullable();
            $table
                ->unsignedInteger('sort')
                ->index()
                ->default(0);
            $table
                ->boolean('is_active')
                ->index()
                ->default(false);
            $table->boolean('is_default')->default(false);
            $table->dateTime('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_plans');
        Schema::dropIfExists('payment_methods');
    }

    public function getConnection(): ?string
    {
        return config('phpinnacle-minos.connection');
    }

    private function addTenancy(Blueprint $table): bool
    {
        $tenancy = config('phpinnacle-minos.tenancy');

        if (isset($tenancy['model']) && class_exists($tenancy['model'])) {
            $table
                ->foreignIdFor($tenancy['model'], 'tenant_id')
                ->after('id')
                ->index()
                ->default($tenancy['default'])
                ->constrained()
                ->cascadeOnDelete();

            return true;
        }

        return false;
    }
};
