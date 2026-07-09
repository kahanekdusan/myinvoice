<?php

declare(strict_types=1);

namespace MyInvoice\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Service\Quote\QuoteNumberGenerator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/quotes/preview-number — náhled dalšího čísla nabídky (bez inkrementu).
 */
final class PreviewQuoteNumberAction
{
    public function __construct(private readonly QuoteNumberGenerator $numbers) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $for = null;
        if (!empty($q['issue_date']) && is_string($q['issue_date'])) {
            $d = \DateTimeImmutable::createFromFormat('Y-m-d', $q['issue_date']);
            if ($d !== false) {
                $for = $d;
            }
        }
        return Json::ok($response, ['quote_number' => $this->numbers->preview($supplierId, $for)]);
    }
}
