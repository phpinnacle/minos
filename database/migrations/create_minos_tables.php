<?php

use Illuminate\Database\Eloquent\Model;
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

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table
                ->foreignIdFor(PaymentMethod::class, 'method_id')
                ->constrained()
                ->restrictOnDelete();
            $table
                ->foreignUuid('parent_id')
                ->nullable()
                ->constrained('payment_transactions')
                ->noActionOnDelete();
            $table->string('source_type');
            $table->string('source_id');
            $table->string('payer_type');
            $table->string('payer_id');
            $table->string('number');
            $table->text('description');
            $table->text('reason')->nullable();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('external_id')->nullable();
            $table->json('metadata')->default('{}');
            $table->unsignedBigInteger('version')->default(0);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $this->addTenancy($table);

            $table->index(['method_id', 'external_id']);
            $table->index(['payer_type', 'payer_id']);
            $table->index(['source_type', 'source_id']);
            $table->index(['parent_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_cards');
        Schema::dropIfExists('payment_plans');
        Schema::dropIfExists('payment_methods');
    }

    public function getConnection(): ?string
    {
        return config('phpinnacle-minos.connection');
    }

    private function addTenancy(Blueprint $table): void
    {
        /** @var array{model: class-string<Model>, default: int|string}|null $tenancy */
        $tenancy = config('phpinnacle-minos.tenancy');

        if ($tenancy !== null) {
            $table
                ->foreignIdFor($tenancy['model'], 'tenant_id')
                ->after('id')
                ->index()
                ->default($tenancy['default'])
                ->constrained()
                ->cascadeOnDelete();
        }
    }
};
