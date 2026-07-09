<?php

declare(strict_types=1);

namespace MyInvoice\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\QuoteRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/quotes — seznam cenových nabídek (taby + filtry + stránkování + počty tabů).
 */
final class ListQuotesAction
{
    public function __construct(private readonly QuoteRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();

        $filters = [
            'tab'         => $q['tab']         ?? 'all',
            'status'      => $q['status']      ?? null,
            'client_id'   => $q['client_id']   ?? null,
            'issued_from' => $q['issued_from'] ?? null,
            'issued_to'   => $q['issued_to']   ?? null,
            'valid_from'  => $q['valid_from']  ?? null,
            'valid_to'    => $q['valid_to']    ?? null,
            'price_min'   => $q['price_min']   ?? null,
            'price_max'   => $q['price_max']   ?? null,
            'search'      => $q['search']      ?? null,
            'sort'        => $q['sort']        ?? 'issue_date',
            'direction'   => $q['direction']   ?? 'desc',
            'page'        => $q['page']        ?? 1,
            'per_page'    => $q['per_page']    ?? 25,
        ];

        $result = $this->repo->list($supplierId, $filters);
        $result['counts'] = $this->repo->tabCounts($supplierId);

        return Json::ok($response, $result);
    }
}
