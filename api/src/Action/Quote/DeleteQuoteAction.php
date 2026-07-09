<?php

declare(strict_types=1);

namespace MyInvoice\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\QuoteRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/quotes/{id} — soft delete cenové nabídky.
 */
final class DeleteQuoteAction
{
    public function __construct(
        private readonly QuoteRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);
        if ($existing === null || !SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }

        $this->repo->softDelete($id);

        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('quote.deleted', $userId, 'quote', $id, [
            'quote_number' => $existing['quote_number'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, ['deleted' => true]);
    }
}
