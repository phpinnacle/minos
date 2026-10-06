<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPinnacle\Minos\Models\Intent;
use PHPinnacle\Minos\Models\IntentLine;
use PHPinnacle\Minos\Models\Payer;
use PHPinnacle\Minos\Models\PaymentPlan;
use PHPinnacle\Minos\Models\Source;
use PHPinnacle\Minos\Models\Transaction;
use PHPinnacle\Minos\Payments\Cash;
use PHPinnacle\Money\Money;
use Tests\TestCase;

uses(TestCase::class);

class MinosMigrationTenant extends Model
{
    public $timestamps = false;
}

it('assigns new payment records to the configured default tenant', function () {
    Schema::create('minos_migration_tenants', function (Blueprint $table) {
        $table->id();
    });
    $tenant = new MinosMigrationTenant;
    $tenant->save();
    config()->set('phpinnacle-minos.tenancy', ['model' => MinosMigrationTenant::class, 'default' => $tenant->id]);
    (require __DIR__ . '/../../database/migrations/create_minos_tables.php')->up();

    $method = new Cash()->define();
    $method->save();
    $plan = PaymentPlan::query()->create(['name' => 'Single payment', 'parts' => [['value' => 100, 'delay' => 0]]]);
    $payment = Transaction::payment(new Intent(
        id: (string) Str::uuid(),
        number: 'PAY-TENANT',
        description: 'Tenant payment',
        method: $method,
        source: new Source('00000000-0000-0000-0000-000000000001', 'order'),
        payer: new Payer('00000000-0000-0000-0000-000000000002', 'customer'),
        instrument: null,
        lines: [new IntentLine('Item', 1, new Money(1000, 'USD'))],
    ));

    expect($method->fresh()->getAttribute('tenant_id'))
        ->toBe($tenant->id)
        ->and($plan->fresh()->getAttribute('tenant_id'))
        ->toBe($tenant->id)
        ->and($payment->fresh()->getAttribute('tenant_id'))
        ->toBe($tenant->id);
});
