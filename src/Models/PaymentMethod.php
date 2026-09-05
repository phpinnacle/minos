<?php

namespace PHPinnacle\Minos\Models;

use Carbon\CarbonImmutable;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Collection;
use PHPinnacle\Minos\Contracts\PaymentProvider;
use PHPinnacle\Minos\Enums\Ability;

/**
 * @property string $id
 * @property string $name
 * @property string $provider
 * @property array<string, mixed> $settings
 * @property int $sort
 * @property bool $is_online
 * @property bool $is_active
 * @property bool $is_default
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class PaymentMethod extends Model implements HasLabel
{
    use HasUuids;

    public $timestamps = true;

    protected $table = 'payment_methods';

    protected $attributes = [
        'is_online' => false,
        'is_active' => true,
        'is_default' => false,
    ];

    protected $casts = [
        'settings' => 'encrypted:array',
        'is_online' => 'bool',
        'is_active' => 'bool',
        'is_default' => 'bool',
    ];

    protected $fillable = [
        'name',
        'provider',
        'settings',
        'is_active',
    ];

    /** @return Builder<self> */
    public static function active(): Builder
    {
        return self::query()->where('is_active', true)->orderBy('sort');
    }

    public static function default(): ?self
    {
        return self::query()->where('is_active', true)->where('is_default', true)->first();
    }

    /** @param array<string, mixed> $settings */
    public static function define(PaymentProvider $provider, array $settings = []): self
    {
        $self = new self;
        $self->name = $provider->getLabel();
        $self->provider = $provider->key();
        $self->settings = $settings;
        $self->is_online = in_array(Ability::Online, $provider->abilities(), true);

        return $self;
    }

    public static function get(string|PaymentProvider $id): self
    {
        [$key, $value] = is_string($id) ? ['id', $id] : ['provider', $id::class];

        return self::query()->where('is_active', true)->where($key, $value)->sole();
    }

    /** @return Collection<string, string> */
    public static function list(?bool $online = null): Collection
    {
        $query = self::active();

        if ($online !== null) {
            $query->where('is_online', $online);
        }

        return $query->pluck('name', 'id');
    }

    public function getLabel(): string
    {
        return $this->name;
    }

    public function isOnline(): bool
    {
        return $this->is_online;
    }

    public function toggleActive(): void
    {
        if ($this->is_default && $this->is_active) {
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

        $this->is_default = !$this->is_default;
        $this->save();
    }

    protected static function booted(): void
    {
        self::creating(function (self $record) {
            reset_sort($record);
        });

        self::saving(function (self $record) {
            reset_default($record);
        });
    }
}
