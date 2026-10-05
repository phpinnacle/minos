<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

class MinosMigrationTenant extends Model {}

it('adds tenant foreign keys and defaults to the configured application model', function () {
    Schema::create('minos_migration_tenants', function (Blueprint $table) {
        $table->id();
    });
    config()->set('phpinnacle-minos.tenancy', ['model' => MinosMigrationTenant::class, 'default' => 1]);
    (require __DIR__ . '/../../database/migrations/create_minos_tables.php')->up();

    foreach (['payment_methods', 'payment_plans', 'payment_transactions'] as $table) {
        $column = collect(Schema::getColumns($table))->firstWhere('name', 'tenant_id');
        $foreignKey = collect(Schema::getForeignKeys($table))->firstWhere('columns', ['tenant_id']);

        expect($column['default'])->toBe("'1'")->and($foreignKey['foreign_table'])->toBe('minos_migration_tenants');
    }

    expect(Schema::hasColumn('payment_cards', 'tenant_id'))->toBeFalse();
});

it('creates and rolls back every Minos table on its configured connection', function () {
    config()->set('database.connections.minos', config('database.connections.sqlite'));
    config()->set('phpinnacle-minos.connection', 'minos');
    $path = realpath(__DIR__ . '/../../database/migrations');

    $this->artisan('migrate', [
        '--path' => $path,
        '--realpath' => true,
    ])->assertSuccessful();

    foreach (['payment_methods', 'payment_plans', 'payment_cards', 'payment_transactions'] as $table) {
        expect(Schema::connection('minos')->hasTable($table))->toBeTrue()->and(Schema::hasTable($table))->toBeFalse();
    }

    $this->artisan('migrate:rollback', [
        '--path' => $path,
        '--realpath' => true,
    ])->assertSuccessful();

    foreach (['payment_methods', 'payment_plans', 'payment_cards', 'payment_transactions'] as $table) {
        expect(Schema::connection('minos')->hasTable($table))->toBeFalse();
    }
});
