<?php

namespace Omnitrade\Stripe;

use Omnisense\Contracts\Http\Data as KernelData;

/**
 * Stripe's decoded answers read without trusting their shape: the kernel's
 * Data (get() by key or path, data(), list()...), and the reads that keep
 * PHP's casts of a scalar as they are ("" kept, nothing trimmed), for the
 * values the provider turned into strings and integers with (string) and
 * (int) before.
 *
 * @internal
 */
final readonly class Data extends KernelData
{
    /** (string) of the value: "" when absent or not a scalar. */
    public function text(string|int $key): string
    {
        return self::cast($this->get($key));
    }

    /** (string) of the value, null when absent. */
    public function textOrNull(string|int $key): ?string
    {
        $value = $this->get($key);

        return null === $value ? null : self::cast($value);
    }

    /** (int) of the value: 0 when absent or not a scalar. */
    public function number(string|int $key): int
    {
        return self::integer($this->get($key));
    }

    /**
     * An object's fields (an empty array when it is not one).
     *
     * @return array<string, mixed>
     */
    public function fields(string|int $key): array
    {
        return self::map($this->get($key));
    }

    /** (string) of a scalar or a Stringable, "" for anything else. */
    public static function cast(mixed $value): string
    {
        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    /** (int) of a scalar, 0 for anything else. */
    public static function integer(mixed $value): int
    {
        return \is_scalar($value) ? (int) $value : 0;
    }

    /** (float) of a scalar, 0.0 for anything else. */
    public static function decimal(mixed $value): float
    {
        return \is_scalar($value) ? (float) $value : 0.0;
    }

    /**
     * A decoded JSON object as it is, its keys as PHP keeps them (an empty
     * array when it is not an array).
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * The objects of a list, each as map() reads it (what is not an array is skipped).
     *
     * @return list<array<string, mixed>>
     */
    public static function maps(mixed $value): array
    {
        return \is_array($value) ? array_values(array_map(self::map(...), array_filter($value, 'is_array'))) : [];
    }

    /**
     * The scalars of a list, each as cast() makes it.
     *
     * @return list<string>
     */
    public static function texts(mixed $value): array
    {
        return array_map(self::cast(...), array_values((array) $value));
    }
}
