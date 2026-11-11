<?php

namespace Omnitrade\Stripe;

use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Media;
use Omnitrade\Model\Money;
use Omnitrade\Model\Offer;
use Omnitrade\Model\Product;
use Omnitrade\Model\ProductVariant;

/**
 * Stripe's Products and their Prices, read as Products: one variant per
 * active price (the default price first), no stock - Stripe counts none.
 * Metadata are the attributes; by convention its "brand" is the brand,
 * "category"/"categories" and "tags" (comma-separated) the categories and
 * tags, "slug" (or "handle") the handle, a price's "sku" its variant's.
 */
final class Products
{
    /**
     * A Stripe product with its active prices, fetched (GET /v1/prices?product=...).
     *
     * @param array<string, mixed> $product
     */
    public static function fetch(Api $api, array $product): Product
    {
        return self::product($product, self::prices($api, Data::cast($product['id'] ?? null)));
    }

    /**
     * One product by its id, with its prices; null when Stripe has none.
     *
     * @throws ProviderException
     */
    public static function find(Api $api, string $id): ?Product
    {
        try {
            $product = $api->get('/v1/products/'.rawurlencode($id), ['expand' => ['default_price']]);
        } catch (ProviderException $e) {
            if ('resource_missing' === $e->providerCode) {
                return null;
            }
            throw $e;
        }

        return self::fetch($api, $product);
    }

    /** @return list<array<string, mixed>> the product's active prices, every page */
    public static function prices(Api $api, string $product): array
    {
        $prices = [];
        $after = null;
        do {
            $page = $api->get('/v1/prices', array_filter(['product' => $product, 'active' => 'true', 'limit' => 100, 'starting_after' => $after]));
            array_push($prices, ...Data::maps($page['data'] ?? []));
            $last = end($prices);
            $after = !empty($page['has_more']) && \is_array($last) ? ($last['id'] ?? null) : null;
        } while (null !== $after);

        return $prices;
    }

    /**
     * @param array<string, mixed>       $product a Stripe product (default_price expanded or not)
     * @param list<array<string, mixed>> $prices  its active prices
     */
    public static function product(array $product, array $prices): Product
    {
        $read = Data::of($product);
        $id = $read->text('id');
        $metadata = self::metadata($product['metadata'] ?? []);
        $active = (bool) ($product['active'] ?? true);
        $url = self::blankToNull($product['url'] ?? null);

        $default = $product['default_price'] ?? null;
        $defaultId = \is_array($default) ? ($default['id'] ?? null) : $default;
        if (\is_array($default) && !empty($default['active']) && !\in_array($defaultId, array_column($prices, 'id'), true)) {
            $prices[] = Data::map($default);
        }
        $prices = array_values(array_filter($prices, static fn (array $p) => null !== self::amount($p)));
        usort($prices, static fn (array $a, array $b) => ($b['id'] === $defaultId) <=> ($a['id'] === $defaultId));

        $weight = $read->has('package_dimensions.weight') ? (int) round(Data::decimal($read->get('package_dimensions.weight')) * 28.349523125) : null;
        $variants = [];
        foreach ($prices as $price) {
            $variants[] = self::variant($price, $active, $url, $weight, 1 === \count($prices) ? ($metadata['sku'] ?? null) : null, \count($prices) > 1);
        }
        if (!$variants) {
            // No price yet: the product is there, not for sale.
            $variants[] = new ProductVariant($id, sku: $metadata['sku'] ?? null, weight: $weight);
        }

        $media = [];
        foreach ((array) ($product['images'] ?? []) as $image) {
            if (\is_string($image) && '' !== $image) {
                $media[] = new Media($image);
            }
        }
        $description = self::blankToNull($product['description'] ?? null);

        return new Product(
            provider: 'stripe',
            reference: $id,
            title: $read->text('name'),
            // Stripe holds plain text: made HTML, as the contract has it.
            description: null === $description ? null : nl2br(htmlspecialchars($description, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), false),
            handle: self::blankToNull($metadata['slug'] ?? $metadata['handle'] ?? null),
            brand: self::blankToNull($metadata['brand'] ?? null),
            url: $url,
            status: $active ? Product::ACTIVE : Product::ARCHIVED,
            tags: self::split($metadata['tags'] ?? ''),
            categories: self::split($metadata['categories'] ?? $metadata['category'] ?? ''),
            attributes: $metadata,
            variants: $variants,
            media: $media,
            updatedAt: $read->has('updated') ? (new \DateTimeImmutable('@'.$read->number('updated'))) : null,
            raw: $product + ['_prices' => $prices],
        );
    }

    /** @param array<string, mixed> $price */
    private static function variant(array $price, bool $productActive, ?string $url, ?int $weight, ?string $productSku, bool $several): ProductVariant
    {
        $read = Data::of($price);
        $metadata = self::metadata($price['metadata'] ?? []);
        $money = new Money((int) self::amount($price), Data::cast($price['currency'] ?? 'eur'));
        $recurring = \is_array($price['recurring'] ?? null) ? $read->data('recurring') : null;
        $interval = null === $recurring ? null : (1 === Data::integer($recurring->get('interval_count') ?? 1) ? $recurring->text('interval') : $recurring->text('interval_count').' '.$recurring->text('interval'));
        $title = self::blankToNull($price['nickname'] ?? null) ?? self::blankToNull($metadata['title'] ?? $metadata['name'] ?? $metadata['variant'] ?? null);
        if (null === $title && $several) {
            $title = $money->decimal().' '.$money->currency.(null !== $interval ? ' / '.$interval : '');
        }
        $attributes = array_diff_key($metadata, ['title' => 1, 'name' => 1, 'variant' => 1, 'sku' => 1]);
        if (null !== $interval) {
            $attributes['recurring'] = $interval;
        }

        return new ProductVariant(
            reference: $read->text('id'),
            title: $title,
            sku: self::blankToNull($metadata['sku'] ?? $productSku),
            offers: [new Offer(
                $money,
                available: $productActive && (bool) ($price['active'] ?? true),
                url: $url,
                reference: $read->text('id'),
                taxIncluded: match ($price['tax_behavior'] ?? null) {
                    'inclusive' => true,
                    'exclusive' => false,
                    default => null,
                },
            )],
            attributes: $attributes,
            weight: $weight,
            raw: $price,
        );
    }

    /**
     * unit_amount, or its decimal (a fraction of a cent rounded); null for a price chosen by the buyer or tiered.
     *
     * @param array<string, mixed> $price
     */
    private static function amount(array $price): ?int
    {
        if (isset($price['unit_amount'])) {
            return Data::integer($price['unit_amount']);
        }
        if (isset($price['unit_amount_decimal']) && is_numeric($price['unit_amount_decimal'])) {
            return (int) round((float) $price['unit_amount_decimal']);
        }

        return null;
    }

    /**
     * A metadata map's scalars as strings, by key.
     *
     * @return array<string, string>
     */
    private static function metadata(mixed $metadata): array
    {
        return array_map(Data::cast(...), array_filter(Data::map((array) $metadata), 'is_scalar'));
    }

    /** @return list<string> */
    private static function split(string $list): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $list)), static fn (string $v) => '' !== $v)));
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim(Data::cast($value));

        return '' === $value ? null : $value;
    }
}
