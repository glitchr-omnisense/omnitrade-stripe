<?php

namespace Omnitrade\Stripe\Tests;

use Omnitrade\GatewayInterface;
use Omnitrade\Model\Product;
use Omnitrade\Request\FetchInventory;
use Omnitrade\Request\FetchProduct;
use Omnitrade\Request\FetchProducts;
use Omnitrade\Stripe\Data;
use Omnitrade\Stripe\StripeGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StripeCatalogueTest extends TestCase
{
    private const SECRET = 'whsec_test';

    /** @var list<array{string, array<string, mixed>}> path, query */
    private array $calls = [];

    /** @param array<string, mixed> $options */
    private function gateway(array $options = []): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringStartsWith('https://api.stripe.com/v1/', $url);
            self::assertContains('Authorization: Bearer sk_test_x', Data::texts($options['headers'] ?? []));
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = [$path, Data::map($query)];
            $json = static fn (array $data) => new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR));

            return match (true) {
                '/v1/products' === $path => $json(isset($query['starting_after'])
                    ? ['object' => 'list', 'data' => [self::product('prod_C', 1759400000)], 'has_more' => false]
                    : ['object' => 'list', 'data' => [self::product('prod_A', 1759500000), self::product('prod_B', 1759000000)], 'has_more' => true]),
                '/v1/products/search' === $path => $json(['object' => 'search_result', 'data' => [self::product('prod_A', 1759500000)], 'has_more' => true, 'next_page' => 'WzE3NTk1MDAwMDBd']),
                '/v1/products/prod_A' === $path => $json(self::product('prod_A', 1759500000)),
                '/v1/prices' === $path => $json(['object' => 'list', 'data' => self::prices(Data::cast($query['product'] ?? null)), 'has_more' => false]),
                default => new MockResponse(json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such product: '.$path]], \JSON_THROW_ON_ERROR), ['http_code' => 404]),
            };
        });

        return (new StripeGatewayFactory($http))->create($options + ['api_key' => 'sk_test_x', 'webhook_secret' => self::SECRET]);
    }

    public function testStripeReadsProductsButCountsNoStock(): void
    {
        $gateway = $this->gateway();
        self::assertTrue($gateway->supports(FetchProducts::class));
        self::assertTrue($gateway->supports(FetchProduct::class));
        self::assertFalse($gateway->supports(FetchInventory::class), 'Stripe has no stock');
    }

    public function testTheCatalogueIsReadPageByPage(): void
    {
        $gateway = $this->gateway();

        $first = $gateway->fetchProducts(limit: 2);
        self::assertSame(['limit' => '2', 'expand' => ['data.default_price']], $this->calls[0][1]);
        self::assertSame(['/v1/prices', ['product' => 'prod_A', 'active' => 'true', 'limit' => '100']], $this->calls[1], 'each product\'s active prices');
        self::assertSame(['prod_A', 'prod_B'], array_map(static fn (Product $p) => $p->reference, $first->products));
        self::assertSame('prod_B', $first->next, 'the last id, there being more');

        $second = $gateway->fetchProducts($first->next, limit: 2);
        self::assertSame('prod_B', $this->calls[3][1]['starting_after']);
        self::assertFalse($second->hasMore());

        $this->gateway(['active_only' => true])->fetchProducts();
        self::assertSame('true', $this->calls[5][1]['active']);
    }

    public function testUpdatedSinceKeepsTheLaterOnesOfEachPage(): void
    {
        $page = $this->gateway()->fetchProducts(updatedSince: new \DateTimeImmutable('@1759300000'));
        self::assertSame('/v1/products', $this->calls[0][0], 'Stripe\'s search knows no update date');
        self::assertSame(['prod_A'], array_map(static fn (Product $p) => $p->reference, $page->products));
        self::assertSame('prod_B', $page->next, 'the page went that far');
    }

    public function testAQueryIsStripesSearch(): void
    {
        $gateway = $this->gateway();
        $page = $gateway->fetchProducts(query: 'Mar"gaux');
        self::assertSame('/v1/products/search', $this->calls[0][0]);
        self::assertSame('name~"Mar\"gaux"', $this->calls[0][1]['query']);
        self::assertSame(['data.default_price'], $this->calls[0][1]['expand']);
        self::assertSame('WzE3NTk1MDAwMDBd', $page->next, 'next_page');

        $gateway->fetchProducts('WzE3NTk1MDAwMDBd', query: 'MG');
        self::assertSame('name:"MG"', $this->calls[2][1]['query'], 'below three characters, the whole name');
        self::assertSame('WzE3NTk1MDAwMDBd', $this->calls[2][1]['page']);
    }

    public function testAProductIsMappedWithAVariantPerPrice(): void
    {
        $product = $this->gateway()->fetchProduct('prod_A');
        self::assertNotNull($product);

        self::assertSame('stripe', $product->provider);
        self::assertSame('Margaux', $product->title);
        self::assertSame("Un vin de garde.<br>\nChâteau &amp; chai.", $product->description, 'plain text made HTML');
        self::assertSame('Château Exemple', $product->brand, 'metadata brand');
        self::assertSame(['Bordeaux', 'Rouge'], $product->categories);
        self::assertSame('margaux', $product->handle);
        self::assertSame('Margaux AOC', $product->attributes['appellation']);
        self::assertSame('https://cave.example/margaux', $product->url);
        self::assertSame(Product::ACTIVE, $product->status);
        self::assertSame('https://files.stripe.com/margaux.jpg', $product->media[0]->url);
        self::assertEquals(new \DateTimeImmutable('@1759500000'), $product->updatedAt);

        self::assertCount(2, $product->variants);
        [$bottle, $magnum] = $product->variants;
        self::assertSame('price_75', $bottle->reference, 'the default price first');
        self::assertSame('75 cl', $bottle->title, 'the nickname');
        self::assertSame('MGX-75', $bottle->sku);
        self::assertSame(2400, $bottle->price()?->amount);
        self::assertSame('EUR', $bottle->price()->currency);
        self::assertSame('price_75', $bottle->offer()?->reference);
        self::assertTrue($bottle->offer()->taxIncluded);
        self::assertNull($bottle->stock, 'no stock at Stripe');
        self::assertSame(1361, $bottle->weight, '48 ounces in grams');
        self::assertSame('Magnum', $magnum->title, 'a title in its metadata');
        self::assertSame(5200, $magnum->price()?->amount);
        self::assertNull($magnum->offer()?->taxIncluded);
        self::assertSame(2400, $product->price()?->amount);
    }

    public function testAProductWithoutPricesAndARecurringOne(): void
    {
        $products = $this->gateway()->fetchProducts()->products;
        $bare = $products[1];
        self::assertSame(Product::ARCHIVED, $bare->status, 'inactive');
        self::assertCount(1, $bare->variants);
        self::assertSame('prod_B', $bare->variants[0]->reference);
        self::assertSame([], $bare->variants[0]->offers);

        $club = $this->gateway()->fetchProducts('prod_B')->products[0];
        self::assertSame('3 month', $club->variants[0]->attributes['recurring']);
        self::assertNull($club->variants[0]->title, 'an only price needs no title');
    }

    public function testAnAddressOrAnUnknownIdIsNoProduct(): void
    {
        $gateway = $this->gateway();
        self::assertNull($gateway->fetchProduct('https://cave.example/margaux'));
        self::assertSame([], $this->calls, 'Stripe hosts no product pages: nothing asked');
        self::assertNull($gateway->fetchProduct('prod_404'));
    }

    public function testTheProductAndPriceEventsCarryTheProduct(): void
    {
        $gateway = $this->gateway();

        $updated = $gateway->notify(...$this->signed(['id' => 'evt_1', 'type' => 'product.updated', 'data' => ['object' => self::product('prod_A', 1759500000, false)]]));
        self::assertTrue($updated->isCatalogue());
        self::assertSame('prod_A', $updated->reference);
        self::assertNotNull($updated->product);
        self::assertSame('Château Exemple', $updated->product->brand);
        self::assertCount(2, $updated->product->variants, 'its prices read along');
        self::assertSame('price_75', $updated->product->variants[0]->reference, 'the default price first, even unexpanded');

        $price = $gateway->notify(...$this->signed(['id' => 'evt_2', 'type' => 'price.updated', 'data' => ['object' => self::prices('prod_A')[1]]]));
        self::assertTrue($price->isCatalogue());
        self::assertSame('prod_A', $price->reference, 'the price\'s product');
        self::assertSame('Margaux', $price->product?->title);

        $deleted = $gateway->notify(...$this->signed(['id' => 'evt_3', 'type' => 'product.deleted', 'data' => ['object' => self::product('prod_A', 1759500000, false)]]));
        self::assertTrue($deleted->isCatalogue());
        self::assertNull($deleted->product);
        self::assertSame('prod_A', $deleted->reference);
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array{string, array<string, string>}
     */
    private function signed(array $event): array
    {
        $body = json_encode($event, \JSON_THROW_ON_ERROR);
        $time = time();

        return [$body, ['Stripe-Signature' => sprintf('t=%d,v1=%s', $time, hash_hmac('sha256', $time.'.'.$body, self::SECRET))]];
    }

    /** @return array<string, mixed> */
    private static function product(string $id, int $updated, bool $expanded = true): array
    {
        return match ($id) {
            'prod_A' => ['id' => 'prod_A', 'object' => 'product', 'active' => true, 'name' => 'Margaux', 'description' => "Un vin de garde.\nChâteau & chai.", 'images' => ['https://files.stripe.com/margaux.jpg'], 'url' => 'https://cave.example/margaux',
                'metadata' => ['brand' => 'Château Exemple', 'categories' => 'Bordeaux, Rouge', 'slug' => 'margaux', 'appellation' => 'Margaux AOC'],
                'package_dimensions' => ['height' => 12, 'length' => 4, 'weight' => 48, 'width' => 4],
                'default_price' => $expanded ? self::prices('prod_A')[1] : 'price_75', 'created' => 1750000000, 'updated' => $updated],
            'prod_B' => ['id' => 'prod_B', 'object' => 'product', 'active' => false, 'name' => 'Sans prix', 'description' => null, 'images' => [], 'metadata' => [], 'default_price' => null, 'updated' => $updated],
            default => ['id' => $id, 'object' => 'product', 'active' => true, 'name' => 'Club', 'images' => [], 'metadata' => [], 'default_price' => null, 'updated' => $updated],
        };
    }

    /** @return list<array<string, mixed>> */
    private static function prices(string $product): array
    {
        return match ($product) {
            // The magnum listed first: the default price must still come first.
            'prod_A' => [
                ['id' => 'price_mag', 'object' => 'price', 'active' => true, 'currency' => 'eur', 'unit_amount' => 5200, 'nickname' => null, 'product' => 'prod_A', 'tax_behavior' => 'unspecified', 'type' => 'one_time', 'recurring' => null, 'metadata' => ['title' => 'Magnum']],
                ['id' => 'price_75', 'object' => 'price', 'active' => true, 'currency' => 'eur', 'unit_amount' => 2400, 'nickname' => '75 cl', 'product' => 'prod_A', 'tax_behavior' => 'inclusive', 'type' => 'one_time', 'recurring' => null, 'metadata' => ['sku' => 'MGX-75']],
            ],
            'prod_C' => [
                ['id' => 'price_club', 'object' => 'price', 'active' => true, 'currency' => 'eur', 'unit_amount' => 9000, 'product' => 'prod_C', 'type' => 'recurring', 'recurring' => ['interval' => 'month', 'interval_count' => 3], 'metadata' => []],
            ],
            default => [],
        };
    }
}
