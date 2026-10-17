---
title: Catalogue
order: 10
---

# Stripe's catalogue

The products kept in Stripe (Products and their Prices), read through the
[omnitrade catalogue contract](https://github.com/glitchr-studio/omnitrade/blob/1.x/docs/catalogue.md).
Stripe counts no stock: `supports(FetchInventory::class)` is false, `fetchInventory()` throws
`RequestNotSupportedException`, and the stock stays the site's.

## Installation

```sh
composer require omnitrade/stripe
```

```yaml
omnitrade:
    gateways:
        card:
            factory: stripe
            options:
                api_key: '%env(STRIPE_API_KEY)%'               # a secret or restricted key reading Products and Prices
                webhook_secret: '%env(STRIPE_WEBHOOK_SECRET)%' # for the product and price events
                active_only: false
```

| Option | Default | |
|---|---|---|
| `active_only` | `false` | `true`: `fetchProducts()` lists the active products only (`active=true`, or `active:"true"` in a search). Otherwise the archived ones come too, as `Product::ARCHIVED`. |

The catalogue's reads are plain `GET`s on `https://api.stripe.com` through the HTTP client the
factory is given (`Authorization: Bearer <api_key>`); the payments still go through omnipay.

## Requests

| Request | What is sent |
|---|---|
| `fetchProducts(?cursor, ?updatedSince, ?query, limit)` | `GET /v1/products?limit=limit (≤ 100)&starting_after=cursor&expand[]=data.default_price`; the next cursor is the page's last product id while `has_more`. With a `query`: `GET /v1/products/search?query=name~"<query>"` (`name:"<query>"` under three characters, Stripe's minimum for a substring), the cursor being `next_page`. Then, for each product, `GET /v1/prices?product=<id>&active=true&limit=100`. |
| `fetchProduct($reference)` | `GET /v1/products/<prod_…>?expand[]=default_price`, then its prices. An address answers `null` (Stripe hosts no product pages), as an unknown id does. |
| `fetchInventory()` | not supported |

`updatedSince`: Stripe's search does not know a product's update date (its product fields are
`active`, `description`, `metadata`, `name`, `shippable`, `url`), so the products of each page
whose `updated` is earlier are left out. A page may then come back short, or empty with a next
cursor: keep paging while `hasMore()`. A new or changed **price** does not change its
product's `updated`: the `price.*` webhooks are what tell of those. Pass the same `updatedSince`
and `query` with the cursor.

Search is eventually consistent (a minute or so), limited to 20 requests a second, and not
offered to accounts in India.

## What is mapped how

| Stripe | omnitrade |
|---|---|
| `id` (`prod_…`) | `Product::$reference` |
| `name` | `title` |
| `description` (plain text) | `description`, made HTML (escaped, line breaks as `<br>`) |
| `metadata` | `attributes`, all of it |
| `metadata.brand` | `brand` |
| `metadata.categories` (or `category`), `metadata.tags` | `categories`, `tags` (comma-separated) |
| `metadata.slug` (or `handle`) | `handle` |
| `images[]` | `media` (images) |
| `url` | `url`, and each offer's |
| `active` | `Product::ACTIVE`, else `ARCHIVED` |
| `updated` | `updatedAt` |
| `package_dimensions.weight` (ounces) | each variant's `weight`, in grams |
| each active price, `default_price` first | a variant: reference the price's id; title its `nickname`, else its metadata `title`/`name`/`variant`, else (several prices) "24.00 EUR / month"; an only price has no title |
| price `unit_amount` (or `unit_amount_decimal`), `currency` | `Offer` price, with `reference` the price id; prices chosen by the buyer or tiered are skipped |
| price `tax_behavior` | `taxIncluded`: `inclusive` true, `exclusive` false, else null |
| price `metadata` | the variant's `attributes` (its `sku` the variant's SKU; an only price takes the product's `metadata.sku`) |
| price `recurring` | the variant's `attributes.recurring` (`month`, `3 month`) |
| no price at all | one variant, the product's id, no offer |

No stock on any variant (`ProductVariant::$stock` is null), no options.

## Webhooks

Add `product.created`, `product.updated`, `product.deleted`, `price.created`, `price.updated`,
`price.deleted` to the webhook endpoint; `notify()` checks `Stripe-Signature` as for Checkout.

| Event | Notification |
|---|---|
| `product.created`, `product.updated` | `$product`: the event's product with its active prices (one call). |
| `price.created`, `price.updated`, `price.deleted` | `$product`: the price's product, read afresh with its prices (two calls); `$reference` the product's id. |
| `product.deleted` | `$product` null, `$reference` the product's id. |

## Examples

```php
$page = $stripe->fetchProducts(updatedSince: $lastSync);
while (true) {
    foreach ($page->products as $product) {
        // $product->brand, $product->variants[0]->offer()->reference (the price to sell at)
    }
    if (!$page->hasMore()) {
        break;
    }
    $page = $stripe->fetchProducts($page->next, updatedSince: $lastSync);
}

$product = $stripe->fetchProduct('prod_Q1x');
$stripe->supports(\Omnitrade\Request\FetchInventory::class);   // false
```

With the harness of omniscient/omnitrade (`core/docker`):

```sh
docker compose run --rm omnitrade catalogue stripe --query=margaux
docker compose run --rm omnitrade catalogue stripe --product=prod_Q1x
```
