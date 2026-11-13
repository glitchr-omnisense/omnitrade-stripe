<?php

namespace Omnitrade\Stripe;

use Omnipay\Common\Http\Client as OmnipayClient;
use Omnisense\Contracts\Policy\Backoff;
use Omnipay\Omnipay;
use Omnipay\Stripe\PaymentIntentsGateway;
use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Http\Redaction;
use Omnitrade\Http\Throttle;
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
 * client, get(), with the secret key as a bearer token. Every call names its
 * API version (Stripe-Version): the account's default is the one set when
 * the account was made, and a recent one refuses what this code sends (the
 * Checkout's payment_method_types since 2026-10-28.endive).
 *
 * Given the gateway's Backoff (the factory's Config::backoff()), no call is
 * sent while Stripe's wait lasts, and a 429 (a rate limit, or a lock_timeout:
 * Stripe runs neither) is a RateLimitedException; nothing is retried here.
 * Every create that takes money carries the caller's Idempotency-Key.
 */
final class Api
{
    public const SIGNATURE_HEADER = 'Stripe-Signature';
    public const BASE_URI = 'https://api.stripe.com';
    /** The version this code is written and run against. */
    public const API_VERSION = '2020-08-27';

    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $webhookSecret = null,
        private readonly ?HttpClientInterface $http = null,
        /** Stripe-Version on every call; null or "": the account's default */
        private readonly ?string $apiVersion = self::API_VERSION,
        /** The waits Stripe asks for (omnisense/contracts' Backoff), shared by the gateway's calls */
        private readonly ?Backoff $backoff = null,
    ) {
    }

    /** The Stripe-Version every call sends, null for the account's default. */
    public function apiVersion(): ?string
    {
        return $this->apiVersion ?: null;
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
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     *
     * @throws ProviderException with Stripe's error code ("resource_missing")
     */
    public function get(string $path, array $query = []): array
    {
        Throttle::before($this->backoff);
        try {
            $response = ($this->http ?? HttpClient::create())->request('GET', self::BASE_URI.'/'.ltrim($path, '/'), [
                'auth_bearer' => $this->apiKey,
                'headers' => array_filter(['Accept' => 'application/json', 'Stripe-Version' => $this->apiVersion]),
                'query' => $query,
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new ProviderException('stripe', 'Stripe request failed: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        Throttle::after($this->backoff, 'stripe', $status, $headers, self::refusal($data));
        if (!\is_array($data)) {
            throw Redaction::withoutArguments(new ProviderException('stripe', sprintf('Stripe answered HTTP %d with a body that is not JSON.', $status)));
        }
        if ($status >= 400 || isset($data['error'])) {
            throw self::failure($data, $status);
        }

        return Data::map($data);
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

    /**
     * post(), where a card's refusal is an answer rather than a failure: Stripe's HTTP 402
     * (request_failed - a card declined, a payment that needs its buyer, authentication_required)
     * comes back as its body, ['error' => [code, decline_code, message, payment_intent...]], for
     * the caller to read. Anything else - a network failure, a body that is not JSON, a 4xx that
     * is not a card's, a 5xx, a rate limit - fails as post() does.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function attempt(string $path, array $parameters = [], ?string $idempotencyKey = null): array
    {
        return $this->send('POST', $path, $parameters, $idempotencyKey, true);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    public function delete(string $path, array $parameters = []): array
    {
        return $this->send('DELETE', $path, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param bool                 $cardRefusals a 402 is answered, not thrown (attempt())
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $parameters, ?string $idempotencyKey = null, bool $cardRefusals = false): array
    {
        Throttle::before($this->backoff);
        array_walk_recursive($parameters, static function (&$value): void {
            if (\is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
        });
        try {
            $response = ($this->http ?? HttpClient::create())->request($method, self::BASE_URI.'/'.ltrim($path, '/'), [
                'auth_bearer' => $this->apiKey,
                'headers' => array_filter(['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded', 'Idempotency-Key' => $idempotencyKey, 'Stripe-Version' => $this->apiVersion]),
                'body' => http_build_query(self::withoutNulls($parameters)),
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new ProviderException('stripe', 'Stripe request failed: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        Throttle::after($this->backoff, 'stripe', $status, $headers, self::refusal($data));
        if (!\is_array($data)) {
            throw Redaction::withoutArguments(new ProviderException('stripe', sprintf('Stripe answered HTTP %d with a body that is not JSON.', $status)));
        }
        if ($cardRefusals && 402 === $status && \is_array($data['error'] ?? null)) {
            return Data::map($data);
        }
        if ($status >= 400 || isset($data['error'])) {
            throw self::failure($data, $status);
        }

        return Data::map($data);
    }

    /** What a 429's body says: "Stripe answered 429: <its message>", or nothing. */
    private static function refusal(mixed $data): string
    {
        $error = \is_array($data) && \is_array($data['error'] ?? null) ? $data['error'] : [];
        $message = $error['message'] ?? $error['code'] ?? null;

        return \is_string($message) ? 'Stripe answered 429: '.$message : '';
    }

    /**
     * Stripe's error as a ProviderException: its message (or the status), its code.
     *
     * @param array<mixed> $data
     */
    private static function failure(array $data, int $status): ProviderException
    {
        $error = Data::of($data)->data('error');

        return Redaction::withoutArguments(new ProviderException('stripe', $error->textOrNull('message') ?? sprintf('HTTP %d', $status), $error->textOrNull('code')));
    }

    /**
     * @param array<mixed> $parameters
     *
     * @return array<mixed>
     */
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
        Throttle::before($this->backoff);
        try {
            $response = $request->send();
        } catch (\Omnipay\Common\Http\Exception $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new ProviderException('stripe', 'Stripe request failed: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        } catch (\Omnipay\Common\Exception\OmnipayException $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new ProviderException('stripe', 'Stripe request not sent: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        // omnipay gives the body, not the status: Stripe's 429s say rate_limit or lock_timeout.
        $data = $response->getData();
        $error = \is_array($data) && \is_array($data['error'] ?? null) ? $data['error'] : [];
        if (\in_array($error['code'] ?? null, ['rate_limit', 'lock_timeout'], true)) {
            Throttle::after($this->backoff, 'stripe', 429, [], self::refusal($data));
        }

        return $response;
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
        if (!$gateway instanceof \Omnipay\Stripe\AbstractGateway) {
            throw new \LogicException(sprintf('%s is not a Stripe gateway of omnipay/stripe.', $gateway::class));
        }
        $gateway->setApiKey($this->apiKey);
        if (null !== $this->apiVersion && '' !== $this->apiVersion) {
            $gateway->setStripeVersion($this->apiVersion);
        }

        return $gateway;
    }

    /**
     * What a failure must not carry: the credentials.
     *
     * @return list<string>
     */
    private function secrets(string ...$more): array
    {
        return [$this->apiKey, (string) $this->webhookSecret, ...array_values($more)];
    }
}
