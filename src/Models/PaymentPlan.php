<?php

namespace PHPinnacle\Minos\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PHPinnacle\Money\Money;

/**
 * @property string $id
 * @property string $key
 * @property string $name
 * @property array $parts
 * @property int $sort
 * @property bool $is_active
 * @property bool $is_default
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class PaymentPlan extends Model
{
    use HasUuids;

    public const string CUSTOM_ID = '00000000-0000-0000-0000-000000000000';

    public $timestamps = true;

    protected $table = 'payment_plans';

    protected $attributes = [
        'parts' => '{}',
        'is_active' => true,
        'is_default' => false,
    ];

    protected $casts = [
        'parts' => 'array',
        'is_active' => 'bool',
        'is_default' => 'bool',
    ];

    protected $fillable = [
        'key',
        'name',
        'parts',
        'is_active',
    ];

    public static function active(): Builder
    {
        return self::query()->where('is_active', true)->orderBy('sort');
    }

    public static function default(): string
    {
        return self::active()->where('is_default', true)->value('id') ?? self::CUSTOM_ID;
    }

    public static function get(string $id): self
    {
        return self::query()->where('is_active', true)->findOrFail($id);
    }

    public static function list(): Collection
    {
        return self::active()->pluck('name', 'id');
    }

    protected static function booted(): void
    {
        self::creating(function (self $record) {
            reset_sort($record);
        });

        self::saving(function (self $record) {
            $record->key ??= Str::slug($record->name);

            reset_default($record);
        });
    }

    public function scheme(Money $price, ?DateTimeInterface $saleAt = null): PaymentScheme
    {
        $ratios = array_map(intval(...), array_column($this->parts, 'value'));
        $amounts = $price->allocate($ratios);

        $parts = [];
        $saleAt = CarbonImmutable::instance($saleAt ?? now());

        foreach ($amounts as $i => $amount) {
            $parts[] = new PaymentPart($amount, $saleAt->addDays((int) $this->parts[$i]['delay']));
        }

        return PaymentScheme::create($parts);
    }

    public function toggleActive(): void
    {
        if ($this->is_default) {
            return;
        }

        $this->is_active = !$this->is_active;
        $this->save();
    }

    public function toggleDefault(): void
    {
        if (!$this->is_active) {
            return;
        }

        $this->is_default = true;
        $this->save();
    }
}
