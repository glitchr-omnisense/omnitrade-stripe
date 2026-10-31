<?php

namespace Omnitrade\Stripe\Tests;

use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Webhook;
use Omnitrade\Request\DeleteWebhook;
use Omnitrade\Request\FetchWebhooks;
use Omnitrade\Request\RegisterWebhook;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\StripeGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The shop's webhook endpoint registered from the application, on answers shaped as Stripe's: no real call. */
final class StripeWebhookEndpointsTest extends TestCase
{
    private const URL = 'https://shop.example.org/payments/webhook';

    /** @var array<string, array<string, mixed>> what the account holds, by id */
    private array $endpoints = [];

    /** @var list<array{string, string, array<string, mixed>, array<string, mixed>}> method, path, what was sent, the options */
    private array $calls = [];

    private int $made = 0;

    protected function setUp(): void
    {
        $this->endpoints = [
            'we_old' => self::endpoint('we_old', self::URL, ['checkout.session.completed']),
            'we_other' => self::endpoint('we_other', 'https://other.example.org/hook', ['invoice.paid']),
        ];
    }

    private function gateway(array $options = []): \Omnitrade\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            parse_str((string) ($options['body'] ?? ''), $sent);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$method, $path, 'GET' === $method ? $query : $sent, $options];
            $json = static fn (array $data, int $status = 200) => new MockResponse(json_encode($data), ['http_code' => $status]);
            $id = substr($path, \strlen('/v1/webhook_endpoints/'));

            if ('GET' === $method && '/v1/webhook_endpoints' === $path) {
                // one endpoint a page, so that every page is read
                $all = array_values($this->endpoints);
                $from = isset($query['starting_after']) ? array_search($query['starting_after'], array_column($all, 'id'), true) + 1 : 0;

                return $json(['object' => 'list', 'url' => '/v1/webhook_endpoints', 'has_more' => $from + 1 < \count($all), 'data' => \array_slice($all, $from, 1)]);
            }
            if ('POST' === $method && '/v1/webhook_endpoints' === $path) {
                $made = self::endpoint('we_new'.++$this->made, $sent['url'], $sent['enabled_events'], $sent['api_version'] ?? null);
                $this->endpoints[$made['id']] = $made;

                return $json($made + ['secret' => 'whsec_made_by_the_mock_'.$this->made]);
            }
            if ('DELETE' === $method && isset($this->endpoints[$id])) {
                unset($this->endpoints[$id]);

                return $json(['id' => $id, 'object' => 'webhook_endpoint', 'deleted' => true]);
            }

            return $json(['error' => ['message' => 'No such webhook endpoint: \''.$id.'\'', 'code' => 'resource_missing', 'type' => 'invalid_request_error']], 404);
        });

        return (new StripeGatewayFactory($http))->create($options + ['api_key' => 'sk_test_x']);
    }

    public function testRegisteringReplacesTheEndpointOnTheSameAddressAndAnswersItsSecret(): void
    {
        $gateway = $this->gateway();
        self::assertTrue($gateway->supports(RegisterWebhook::class));

        $gateway->execute($request = new RegisterWebhook(self::URL));
        $webhook = $request->getWebhook();

        self::assertInstanceOf(Webhook::class, $webhook);
        self::assertSame('we_new1', $webhook->id);
        self::assertSame(self::URL, $webhook->url);
        self::assertSame(['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'], $webhook->events);
        self::assertSame('whsec_made_by_the_mock_1', $webhook->secret);
        self::assertArrayNotHasKey('secret', $webhook->raw, 'the secret is in one place only');

        // listed (both pages), the old one on the same address deleted, the other kept, then made
        self::assertSame(['GET /v1/webhook_endpoints', 'GET /v1/webhook_endpoints', 'DELETE /v1/webhook_endpoints/we_old', 'POST /v1/webhook_endpoints'], array_map(static fn (array $c) => $c[0].' '.$c[1], $this->calls));
        self::assertSame('we_old', $this->calls[1][2]['starting_after']);
        self::assertSame(['we_other', 'we_new1'], array_keys($this->endpoints));

        [, , $sent, $options] = $this->calls[3];
        self::assertSame(Api::API_VERSION, $sent['api_version'], 'events come in the version notify() reads');
        self::assertContains('Stripe-Version: '.Api::API_VERSION, $options['headers']);

        // again: the endpoint just made is replaced, never doubled
        $gateway->execute($again = new RegisterWebhook(self::URL));
        self::assertSame('we_new2', $again->getWebhook()->id);
        self::assertSame(['we_other', 'we_new2'], array_keys($this->endpoints));
    }

    public function testTheEventsAskedForAndTheAccountsDefaultVersion(): void
    {
        $gateway = $this->gateway(['api_version' => null]);
        $gateway->execute($request = new RegisterWebhook('https://shop.example.org/other', ['charge.refunded']));

        self::assertSame(['charge.refunded'], $request->getWebhook()->events);
        [, , $sent] = end($this->calls);
        self::assertArrayNotHasKey('api_version', $sent);
        self::assertCount(3, $this->endpoints, 'another address: nothing deleted');
    }

    public function testListingAndDeleting(): void
    {
        $gateway = $this->gateway();
        $gateway->execute($list = new FetchWebhooks());
        self::assertSame(['we_old', 'we_other'], array_map(static fn (Webhook $w) => $w->id, $list->getWebhooks()));
        self::assertNull($list->getWebhooks()[0]->secret);
        self::assertSame(['invoice.paid'], $list->getWebhooks()[1]->events);

        $gateway->execute($delete = new DeleteWebhook('we_old'));
        self::assertTrue($delete->getResult());
        $gateway->execute($gone = new DeleteWebhook('we_old'));
        self::assertTrue($gone->getResult(), 'already gone: done');
        self::assertSame(['we_other'], array_keys($this->endpoints));
    }

    public function testAnotherRefusalIsThrown(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['error' => ['message' => 'Invalid API Key provided: sk_test_***x', 'type' => 'invalid_request_error']]), ['http_code' => 401]));
        $gateway = (new StripeGatewayFactory($http))->create(['api_key' => 'sk_test_x']);

        $this->expectException(ProviderException::class);
        $gateway->execute(new RegisterWebhook(self::URL));
    }

    /** @param list<string> $events */
    private static function endpoint(string $id, string $url, array $events, ?string $version = '2020-08-27'): array
    {
        return ['id' => $id, 'object' => 'webhook_endpoint', 'api_version' => $version, 'application' => null, 'created' => 1761868800, 'description' => 'omnitrade', 'enabled_events' => $events, 'livemode' => false, 'metadata' => [], 'status' => 'enabled', 'url' => $url];
    }
}
