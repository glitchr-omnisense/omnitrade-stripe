<?php

namespace Omnitrade\Stripe;

use Omnitrade\Config;
use Omnitrade\GatewayFactory;
use Omnitrade\Stripe\Action\AccountLinkAction;
use Omnitrade\Stripe\Action\CancelSubscriptionAction;
use Omnitrade\Stripe\Action\CreateAccountAction;
use Omnitrade\Stripe\Action\DeleteWebhookAction;
use Omnitrade\Stripe\Action\FetchAccountAction;
use Omnitrade\Stripe\Action\FetchProductAction;
use Omnitrade\Stripe\Action\FetchProductsAction;
use Omnitrade\Stripe\Action\FetchSubscriptionAction;
use Omnitrade\Stripe\Action\FetchTransactionAction;
use Omnitrade\Stripe\Action\FetchWebhooksAction;
use Omnitrade\Stripe\Action\GetPaymentMethodsAction;
use Omnitrade\Stripe\Action\NotifyAction;
use Omnitrade\Stripe\Action\PurchaseAction;
use Omnitrade\Stripe\Action\RefundAction;
use Omnitrade\Stripe\Action\RegisterWebhookAction;
use Omnitrade\Stripe\Action\SubscribeAction;
use Omnitrade\Stripe\Action\SubscriptionPortalAction;

/**
 * Stripe: cards and wallets through Checkout (the hosted page), what was
 * paid, refunds, the webhook's events, and the catalogue kept in Stripe's
 * Products and Prices (no stock: fetchInventory() is not supported).
 *
 * Connect: Express accounts opened for those the platform pays
 * (createAccount(), accountLink(), fetchAccount()), and payments sent to them
 * as destination charges (Payment::$destination, ::$applicationFee).
 * Subscriptions: Checkout in subscription mode (subscribe()), the customer
 * portal, cancellation; account.updated and customer.subscription.* events
 * read by notify().
 *
 *   options:
 *     api_key: '%env(STRIPE_API_KEY)%'              # sk_live_... / sk_test_...
 *     webhook_secret: '%env(STRIPE_WEBHOOK_SECRET)%' # whsec_..., for notify()
 *     adaptive_pricing: false                        # true: Stripe may offer the buyer's own currency
 *     payment_methods: []                            # ['card', 'sepa_debit']: the methods to insist on
 *     active_only: false                             # true: fetchProducts() lists the active products only
 *     api_version: '2020-08-27'                      # Stripe-Version of every call; null: the account's default
 *
 * No authorizations: Checkout takes the money when the buyer pays (a session
 * in "setup"/"authorize" mode is not offered here).
 */
final class StripeGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnitrade.factory_name' => 'stripe',
            'omnitrade.factory_title' => 'Stripe',
            'omnitrade.required_options' => ['api_key'],
            'webhook_secret' => null,
            'adaptive_pricing' => false,
            'payment_methods' => [],
            'active_only' => false,
            'api_version' => Api::API_VERSION,
            'omnitrade.api' => fn (Config $c) => new Api((string) $c['api_key'], $c['webhook_secret'] ?: null, $this->http, $c['api_version'] ?: null),
            'omnitrade.action.purchase' => static fn (Config $c) => new PurchaseAction((bool) $c['adaptive_pricing'], (array) $c['payment_methods']),
            'omnitrade.action.fetch' => new FetchTransactionAction(),
            'omnitrade.action.refund' => new RefundAction(),
            'omnitrade.action.notify' => new NotifyAction(),
            'omnitrade.action.products' => static fn (Config $c) => new FetchProductsAction((bool) $c['active_only']),
            'omnitrade.action.product' => new FetchProductAction(),
            'omnitrade.action.account_create' => new CreateAccountAction(),
            'omnitrade.action.account_link' => new AccountLinkAction(),
            'omnitrade.action.account' => new FetchAccountAction(),
            'omnitrade.action.subscribe' => new SubscribeAction(),
            'omnitrade.action.subscription' => new FetchSubscriptionAction(),
            'omnitrade.action.subscription_cancel' => new CancelSubscriptionAction(),
            'omnitrade.action.subscription_portal' => new SubscriptionPortalAction(),
            'omnitrade.action.webhook_register' => new RegisterWebhookAction(),
            'omnitrade.action.webhooks' => new FetchWebhooksAction(),
            'omnitrade.action.webhook_delete' => new DeleteWebhookAction(),
            'omnitrade.action.methods' => static fn (Config $c) => new GetPaymentMethodsAction((array) $c['payment_methods']),
        ]);
    }
}
