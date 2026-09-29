<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Support\Str;

/**
 * Stripe-style ids: `{ID_PREFIX}_{16 base-62 chars}`, e.g. `bd_7Kq2mZp9Xt4LwRc1`.
 * The using model declares `public const ID_PREFIX = 'bd';`.
 */
trait HasPrefixedId
{
    use HasUniqueStringIds;

    public function newUniqueId(): string
    {
        return static::ID_PREFIX.'_'.Str::random(16);
    }

    protected function isValidUniqueId($value): bool
    {
        return is_string($value) && preg_match('/^'.static::ID_PREFIX.'_[A-Za-z0-9]{16}$/', $value) === 1;
    }
}
