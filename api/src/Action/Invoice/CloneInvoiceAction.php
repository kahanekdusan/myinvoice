<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Invoice\QuoteLifecyclePolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/clone
 * Body (volitelně):
 *   {
 *     "increment_month_in_descriptions": true,
 *     "issue_date": "YYYY-MM-DD",
 *     "target_invoice_type": "invoice|proforma",
 *     "target_numbering_type": "default|quote",
 *     "parent_invoice_id": 123
 *   }
 *
 * Vrací: { draft_id: int }
 */
final class CloneInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly BulkReissueAction $bulk,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $incrementMonth = (bool) ($body['increment_month_in_descriptions'] ?? false);
        $issueDate = !empty($body['issue_date']) ? (string) $body['issue_date'] : date('Y-m-d');
        $targetInvoiceType = !empty($body['target_invoice_type']) ? (string) $body['target_invoice_type'] : null;
        $targetNumberingType = !empty($body['target_numbering_type']) ? (string) $body['target_numbering_type'] : null;
        $parentInvoiceId = array_key_exists('parent_invoice_id', $body) && $body['parent_invoice_id'] !== null
            ? (int) $body['parent_invoice_id']
            : null;

        $quoteViolation = QuoteLifecyclePolicy::conversionViolation(
            $invoice,
            $targetInvoiceType,
            $targetNumberingType,
        );
        if ($quoteViolation !== null) {
            return Json::error($response, $quoteViolation['code'], $quoteViolation['message'], 409);
        }
        $isQuoteConversion = QuoteLifecyclePolicy::isConversion(
            $invoice,
            $targetInvoiceType,
            $targetNumberingType,
        );
        if ($isQuoteConversion) {
            $parentInvoiceId = $id;
        }
        if ($isQuoteConversion && $targetInvoiceType === 'invoice') {
            // Převod nabídky není klon nabídky: vždy zakládá běžnou fakturu v její
            // číselné řadě a s aktuálními účetními daty. Starší klient může stále
            // poslat issue_date nabídky, proto cílové hodnoty kanonizujeme i zde.
            $issueDate = date('Y-m-d');
            $incrementMonth = false;
            $targetInvoiceType = 'invoice';
            $targetNumberingType = 'default';
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        try {
            $newId = $this->bulk->cloneOne(
                $id,
                $issueDate,
                $incrementMonth,
                $userId,
                $targetInvoiceType,
                $targetNumberingType,
                $parentInvoiceId,
            );
        } catch (\Throwable $e) {
            return Json::error($response, 'clone_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.cloned', $userId, 'invoice', $id, [
            'new_draft_id' => $newId, 'increment_month' => $incrementMonth,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['draft_id' => $newId], 201);
    }
}
