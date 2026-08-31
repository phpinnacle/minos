<?php

namespace PHPinnacle\Minos\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * @property string $id
 * @property string $method_id
 * @property string $customer_type
 * @property string $customer_id
 * @property string $token
 * @property string|null $product
 * @property string|null $country
 * @property string|null $brand
 * @property string|null $subbrand
 * @property string|null $bin
 * @property string|null $mask
 * @property bool $is_active
 * @property bool $is_default
 * @property int $sort
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class CreditCard extends Model
{
    use HasUuids;

    public $timestamps = true;

    protected $table = 'payment_cards';

    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'is_active' => 'bool',
        'expires_at' => 'immutable_datetime',
    ];

    protected $fillable = [
        'method_id',
        'customer_type',
        'customer_id',
        'token',
        'product',
        'country',
        'brand',
        'subbrand',
        'bin',
        'mask',
        'is_active',
        'expires_at',
    ];

    protected $hidden = [
        'token',
    ];

    /** @return Builder<self> */
    public static function active(): Builder
    {
        return self::query()->where('is_active', true)->orderBy('sort');
    }

    public static function booted(): void
    {
        self::creating(function (self $record) {
            reset_sort($record, [
                'customer_type' => $record->customer_type,
                'customer_id' => $record->customer_id,
            ]);

            if ($record->sort === 1) {
                $record->is_default = true;
            }
        });
    }

    public static function find(string $id): ?self
    {
        return self::query()->find($id);
    }

    /** @return Collection<int, self> */
    public static function list(string $method, Payer $payer): Collection
    {
        return self::active()
            ->where([
                'method_id' => $method,
                'customer_type' => $payer->type,
                'customer_id' => $payer->id,
            ])
            ->get();
    }

    public function isUsableFor(Payer $payer): bool
    {
        return (
            $this->is_active
            && $this->customer_type === $payer->type
            && $this->customer_id === $payer->id
            && !$this->expires_at->isPast()
        );
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'method_id');
    }
}
