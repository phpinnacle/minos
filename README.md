# Minos for Filament

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phpinnacle/minos.svg?style=flat-square)](https://packagist.org/packages/phpinnacle/minos)

Minos is a payment orchestration package for Laravel and Filament. It models payment methods, installment plans, stored cards, payment intents and adjustments behind provider contracts, while supplying Filament resources for operational configuration.

## Features

- Payment method and payment plan Filament resources.
- Provider registry with built-in cash, bank, card, ERIP, Stripe, BePaid and WebPay implementations.
- `PaymentGateway` and `PaymentProvider` contracts for custom integrations.
- Payment intents, line items, payers, sources and continuation decisions.
- Discounts, shipping, tax, fees and rounding adjustments.
- Single and multipart payment schemes with validation.
- Tokenized and encrypted card instruments.
- Configurable connection, navigation and tenancy.

## Requirements and installation

- PHP 8.4 or later
- Laravel 13 and Filament 5
- Laravel Cashier 16
- `phpinnacle/common`

```bash
composer require phpinnacle/minos
php artisan vendor:publish --tag="phpinnacle-minos-migrations"
php artisan migrate
```

## Registering providers

```php
use PHPinnacle\Minos\MinosPlugin;
use PHPinnacle\Minos\Payments\Cash;
use PHPinnacle\Minos\Payments\Stripe;

$panel->plugin(
    MinosPlugin::make()->providers(
        new Cash(),
        new Stripe(),
    ),
);
```

Providers are loaded into `ProviderRegistry` and become available when defining payment methods. Each provider supplies its label, availability, configuration form, validation and gateway behavior.
Providers that do not require checkout input may return `null` from `PaymentGateway::schema()`.

## Payment plans and calculations

`PaymentPlan::scheme()` creates a `PaymentScheme` for a price and optional sale date. `PaymentScheme` exposes its start, expiry, total and installment count. `Intent` calculates totals from `IntentLine` records and typed `Adjustment` objects:

```php
use PHPinnacle\Minos\Models\Adjustment;

$adjustment = Adjustment::discount('Campaign', $discount);
$newTotal = $adjustment->apply($subtotal);
```

Gateway results use `Continuation` and `Decision` to represent success, failure, pending and cancellation without coupling application flows to one provider.

Payment configuration and credentials are sensitive. Keep production secrets outside source control and validate webhook authenticity with the selected provider implementation.

## Testing

```bash
composer test
```

## Changelog and license

See [CHANGELOG](CHANGELOG.md). Released under the [MIT License](LICENSE.md).
