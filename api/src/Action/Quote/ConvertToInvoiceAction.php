<?php

declare(strict_types=1);

namespace MyInvoice\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\QuoteRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Quote\QuoteToInvoiceConverter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/quotes/{id}/to-invoice — vygenerování vydané faktury z nabídky.
 * Z jedné nabídky lze vystavit jen JEDNU vydanou fakturu. Stav nabídky ? 'invoiced'.
 */
final class ConvertToInvoiceAction
{
    public function __construct(
        private readonly QuoteRepository $repo,
        private readonly QuoteToInvoiceConverter $converter,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $quote = $this->repo->find($id);
        if ($quote === null || !SupplierGuard::owns($request, $quote)) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }

        if (in_array((string) $quote['status'], ['invoiced', 'rejected'], true)) {
            return Json::error($response, 'invalid_state', 'Tuto nabídku nelze vyfakturovat.', 409);
        }
        if ($this->repo->hasFinalInvoice($id)) {
            return Json::error($response, 'already_invoiced', 'Z této nabídky již byla vystavena faktura.', 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $invoiceId = $this->converter->convert($quote, 'invoice', $userId);

        $supplierId = SupplierGuard::currentId($request);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('quote.to_invoice', $userId, 'quote', $id, [
            'invoice_id' => $invoiceId,
        ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, ['invoice_id' => $invoiceId], 201);
    }
}
