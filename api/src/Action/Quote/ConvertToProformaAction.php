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
 * POST /api/quotes/{id}/to-proforma — vygenerování zálohové faktury (proforma) z nabídky.
 * Z jedné nabídky lze vystavit více proform. Stav nabídky ? 'ordered'.
 */
final class ConvertToProformaAction
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
            return Json::error($response, 'invalid_state', 'Tuto nabídku nelze převést na zálohu.', 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $invoiceId = $this->converter->convert($quote, 'proforma', $userId);

        $supplierId = SupplierGuard::currentId($request);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('quote.to_proforma', $userId, 'quote', $id, [
            'invoice_id' => $invoiceId,
        ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, ['invoice_id' => $invoiceId], 201);
    }
}
