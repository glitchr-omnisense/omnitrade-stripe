<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Model\ProductPage;
use Omnitrade\Request\FetchProducts;
use Omnitrade\Request\Request;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\Data;
use Omnitrade\Stripe\Products;

/**
 * A page of Stripe's Products, each with its active Prices (one call per
 * product): GET /v1/products, the cursor the last product's id
 * (starting_after); with a query, GET /v1/products/search on name~"...",
 * the cursor Stripe's next_page. Stripe's search knows no update date, so
 * updatedSince keeps the products whose "updated" is that late among each
 * page - a page may come back short, or empty with a next one. Pass the
 * same updatedSince and query with the cursor.
 */
final class FetchProductsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct(private readonly bool $activeOnly = false)
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchProducts;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchProducts);
        $limit = max(1, min(100, $request->limit));
        $expand = ['data.default_price'];
        if (null !== $request->query && '' !== trim($request->query)) {
            $search = self::search(trim($request->query)).($this->activeOnly ? ' AND active:"true"' : '');
            $page = $this->api->get('/v1/products/search', array_filter(['query' => $search, 'limit' => $limit, 'page' => $request->cursor, 'expand' => $expand]));
            $next = !empty($page['has_more']) && !empty($page['next_page']) ? Data::cast($page['next_page']) : null;
        } else {
            $page = $this->api->get('/v1/products', array_filter(['limit' => $limit, 'starting_after' => $request->cursor, 'active' => $this->activeOnly ? 'true' : null, 'expand' => $expand]));
            $data = (array) ($page['data'] ?? []);
            $last = end($data);
            $next = !empty($page['has_more']) && \is_array($last) ? Data::cast($last['id'] ?? null) : null;
        }

        $since = $request->updatedSince?->getTimestamp();
        $products = [];
        foreach (Data::maps($page['data'] ?? []) as $product) {
            if (null !== $since && Data::integer($product['updated'] ?? 0) < $since) {
                continue;
            }
            $products[] = Products::fetch($this->api, $product);
        }

        $request->setResult(new ProductPage($products, $next));
    }

    /** Stripe's search: name~"..." (a substring, three characters at least), name:"..." below. */
    public static function search(string $text): string
    {
        $quoted = '"'.addcslashes($text, '"\\').'"';

        return mb_strlen($text) >= 3 ? 'name~'.$quoted : 'name:'.$quoted;
    }
}
