<?php

declare(strict_types=1);

namespace MyInvoice\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\QuoteRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Quote\QuoteCalculator;
use MyInvoice\Service\Quote\QuoteDefaults;
use MyInvoice\Service\Quote\QuoteNumberGenerator;
use MyInvoice\Service\Validation\QuoteValidation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/quotes — vytvoření cenové nabídky (draft).
 */
final class CreateQuoteAction
{
    public function __construct(
        private readonly QuoteRepository $repo,
        private readonly ClientRepository $clients,
        private readonly QuoteDefaults $defaults,
        private readonly QuoteCalculator $calc,
        private readonly QuoteNumberGenerator $numbers,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $body = $this->defaults->resolve($body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $errors = QuoteValidation::quote($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Klient musí patřit aktuálnímu supplier (proti cross-supplier injection).
        if (!SupplierGuard::owns($request, $this->clients->find((int) $body['client_id']))) {
            return Json::error($response, 'client_not_found', 'Klient neexistuje.', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        $issueDate = new \DateTimeImmutable((string) $body['issue_date']);
        $quoteNumber = $this->numbers->next($supplierId, $issueDate);

        $id = $this->repo->createDraft($body, $userId, $quoteNumber);
        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));
        $this->calc->recompute($id);
        $this->repo->writeSnapshots($id);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('quote.created', $userId, 'quote', $id, [
            'client_id'    => (int) $body['client_id'],
            'quote_number' => $quoteNumber,
        ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, $this->repo->find($id), 201);
    }
}
