<?php

namespace Omnitrade\Stripe;

use Omnipay\Common\Http\Client as OmnipayClient;
use Omnipay\Omnipay;
use Omnipay\Stripe\PaymentIntentsGateway;
use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Exception\ProviderException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stripe, through omnipay/stripe: the Checkout gateway (hosted payment page)
 * and the PaymentIntents gateway (what was paid, refunds), both on one
 * secret key. Omnipay talks through the application's HTTP client when one
 * is given (a PSR-18 bridge): the profiler sees the calls, the tests mock them.
 * The catalogue's plain reads (products, prices) go straight through that
 * client, get(), with the secret key as a bearer token.
 */
final class Api
{
    public const SIGNATURE_HEADER = 'Stripe-Signature';
    public const BASE_URI = 'https://api.stripe.com';

    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $webhookSecret = null,
        private readonly ?HttpClientInterface $http = null,
    ) {
    }

    public function checkout(): CheckoutGateway
    {
        /** @var CheckoutGateway */
        return $this->create('\\'.CheckoutGateway::class);
    }

    public function intents(): PaymentIntentsGateway
    {
        /** @var PaymentIntentsGateway */
        return $this->create('Stripe\PaymentIntents');
    }

    /**
     * A GET of Stripe's REST API: "/v1/products", with its query (arrays as
     * Stripe reads them, expand[0]=...).
     *
     * @return array<string, mixed>
     *
     * @throws ProviderException with Stripe's error code ("resource_missing")
     */
    public function get(string $path, array $query = []): array
    {
        try {
            $response = ($this->http ?? HttpClient::create())->request('GET', self::BASE_URI.'/'.ltrim($path, '/'), [
                'auth_bearer' => $this->apiKey,
                'headers' => ['Accept' => 'application/json'],
                'query' => $query,
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException('stripe', 'Stripe request failed: '.$e->getMessage(), null, $e);
        }
        if (!\is_array($data)) {
            throw new ProviderException('stripe', sprintf('Stripe answered HTTP %d with a body that is not JSON.', $status));
        }
        if ($status >= 400 || isset($data['error'])) {
            throw new ProviderException('stripe', (string) ($data['error']['message'] ?? sprintf('HTTP %d', $status)), isset($data['error']['code']) ? (string) $data['error']['code'] : null);
        }

        return $data;
    }

    /**
     * A POST of Stripe's REST API, form-encoded as Stripe reads it (nested
     * arrays as a[b][c]=...; booleans as "true" / "false"): accounts, account
     * links, portal sessions, subscriptions - what omnipay/stripe has no
     * message for.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     *
     * @throws ProviderException with Stripe's error code
     */
    public function post(string $path, array $parameters = [], ?string $idempotencyKey = null): array
    {
        return $this->send('POST', $path, $parameters, $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function delete(string $path, array $parameters = []): array
    {
        return $this->send('DELETE', $path, $parameters);
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $path, array $parameters, ?string $idempotencyKey = null): array
    {
        array_walk_recursive($parameters, static function (&$value): void {
            if (\is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
        });
        try {
            $response = ($this->http ?? HttpClient::create())->request($method, self::BASE_URI.'/'.ltrim($path, '/'), [
                'auth_bearer' => $this->apiKey,
                'headers' => array_filter(['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded', 'Idempotency-Key' => $idempotencyKey]),
                'body' => http_build_query(self::withoutNulls($parameters)),
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException('stripe', 'Stripe request failed: '.$e->getMessage(), null, $e);
        }
        if (!\is_array($data)) {
            throw new ProviderException('stripe', sprintf('Stripe answered HTTP %d with a body that is not JSON.', $status));
        }
        if ($status >= 400 || isset($data['error'])) {
            throw new ProviderException('stripe', (string) ($data['error']['message'] ?? sprintf('HTTP %d', $status)), isset($data['error']['code']) ? (string) $data['error']['code'] : null);
        }

        return $data;
    }

    private static function withoutNulls(array $parameters): array
    {
        foreach ($parameters as $key => $value) {
            if (null === $value) {
                unset($parameters[$key]);
            } elseif (\is_array($value)) {
                $parameters[$key] = self::withoutNulls($value);
            }
        }

        return $parameters;
    }

    /**
     * Sends one of omnipay's requests (Checkout, payment intents): a network
     * failure, or a request omnipay refuses before sending it (a parameter
     * missing), is a ProviderException, like any other of Stripe's.
     *
     * @throws ProviderException
     */
    public function sendOmnipay(\Omnipay\Common\Message\RequestInterface $request): \Omnipay\Common\Message\ResponseInterface
    {
        try {
            return $request->send();
        } catch (\Omnipay\Common\Http\Exception $e) {
            throw new ProviderException('stripe', 'Stripe request failed: '.$e->getMessage(), null, $e);
        } catch (\Omnipay\Common\Exception\OmnipayException $e) {
            throw new ProviderException('stripe', 'Stripe request not sent: '.$e->getMessage(), null, $e);
        }
    }

    public function canVerify(): bool
    {
        return null !== $this->webhookSecret && '' !== $this->webhookSecret;
    }

    /**
     * Whether a webhook payload was signed by Stripe with the endpoint's
     * secret (Stripe-Signature: t=timestamp,v1=hmac), within $tolerance seconds.
     *
     * @throws InvalidNotificationException
     */
    public function verify(string $payload, ?string $header, int $tolerance = 300): void
    {
        if (!$this->canVerify()) {
            throw new InvalidNotificationException('stripe', 'No webhook secret: the notification cannot be checked.');
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', (string) $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ('t' === $key) {
                $timestamp = (int) $value;
            } elseif ('v1' === $key) {
                $signatures[] = $value;
            }
        }
        if (null === $timestamp || abs(time() - $timestamp) > $tolerance) {
            throw new InvalidNotificationException('stripe', 'The notification is unsigned, or too old.');
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, (string) $this->webhookSecret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new InvalidNotificationException('stripe', 'The notification\'s signature does not match.');
    }

    private function create(string $gateway): \Omnipay\Common\GatewayInterface
    {
        $gateway = Omnipay::create($gateway, $this->http ? new OmnipayClient(new Psr18Client($this->http)) : null);
        $gateway->setApiKey($this->apiKey);

        return $gateway;
    }
}
