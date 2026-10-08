<?php

namespace App\Casts;

use App\Services\Vault;
use App\Support\SealedSecret;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<SealedSecret, SealedSecret|string>
 */
class SealedSecretCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?SealedSecret
    {
        return $value === null ? null : new SealedSecret((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof SealedSecret => $value->ciphertext,
            // casts kunnen geen constructor-injectie gebruiken
            default => app(Vault::class)->encrypt((string) $value),
        };
    }
}
