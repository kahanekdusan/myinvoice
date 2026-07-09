<?php

declare(strict_types=1);

namespace MyInvoice\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\QuoteRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Quote\QuoteCalculator;
use MyInvoice\Service\Quote\QuoteDefaults;
use MyInvoice\Service\Quote\QuoteNumberGenerator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/quotes/{id}/clone — kopie nabídky (nový draft, čerstvé číslo + datumy).
 */
final class CloneQuoteAction
{
    public function __construct(
        private readonly QuoteRepository $repo,
        private readonly QuoteDefaults $defaults,
        private readonly QuoteCalculator $calc,
        private readonly QuoteNumberGenerator $numbers,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $source = $this->repo->find($id);
        if ($source === null || !SupplierGuard::owns($request, $source)) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }

        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        $data = [
            'client_id'          => (int) $source['client_id'],
            'project_id'         => $source['project_id'] ?? null,
            'currency_id'        => (int) $source['currency_id'],
            'reverse_charge'     => (bool) $source['reverse_charge'],
            'prices_include_vat' => (bool) $source['prices_include_vat'],
            'language'           => (string) ($source['language'] ?? 'cs'),
            'payment_method'     => (string) ($source['payment_method'] ?? 'bank_transfer'),
            'order_number'       => $source['order_number'] ?? null,
            'description'        => $source['description'] ?? null,
            'note'               => $source['note'] ?? null,
            'note_above_items'   => $source['note_above_items'] ?? null,
            'note_below_items'   => $source['note_below_items'] ?? null,
            'discount_percent'   => (float) ($source['discount_percent'] ?? 0),
            'items'              => $source['items'] ?? [],
        ];
        // Čerstvé datumy (issue = dnes, valid_until dle nastavení supplieru).
        $data = $this->defaults->resolve($data);

        $issueDate = new \DateTimeImmutable((string) $data['issue_date']);
        $quoteNumber = $this->numbers->next($supplierId, $issueDate);

        $newId = $this->repo->createDraft($data, $userId, $quoteNumber);
        $this->repo->replaceItems($newId, (array) $data['items']);
        $this->calc->recompute($newId);
        $this->repo->writeSnapshots($newId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('quote.cloned', $userId, 'quote', $newId, [
            'source_quote_id' => $id,
            'quote_number'    => $quoteNumber,
        ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, $this->repo->find($newId), 201);
    }
}
