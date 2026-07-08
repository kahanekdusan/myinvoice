<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Repository\PublicInvoiceLinkRepository;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PublicInvoiceGetAction
{
    public function __construct(
        private readonly PublicInvoiceLinkRepository $repo,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $token = trim((string) ($args['token'] ?? ''));
        if (!ApprovalTokenValidator::isValidFormat($token)) {
            return Json::error($response, 'invalid_token', 'Neplatný odkaz.', 404);
        }

        $row = $this->repo->findActiveByToken($token);
        if ($row === null) {
            return Json::error($response, 'token_invalid_or_expired', 'Odkaz na fakturu není platný nebo vypršel.', 404);
        }

        $this->repo->touchViewed((int) $row['id']);

        return Json::ok($response, [
            'invoice' => [
                'id' => (int) $row['id'],
                'supplier_id' => (int) $row['supplier_id'],
                'invoice_type' => (string) $row['invoice_type'],
                'status' => (string) $row['status'],
                'varsymbol' => (string) $row['varsymbol'],
                'issue_date' => (string) $row['issue_date'],
                'due_date' => (string) $row['due_date'],
                'language' => (string) ($row['language'] ?? 'cs'),
                'amount_to_pay' => isset($row['amount_to_pay']) ? (float) $row['amount_to_pay'] : 0.0,
                'paid_total' => isset($row['paid_total']) ? (float) $row['paid_total'] : 0.0,
                'total_with_vat' => isset($row['total_with_vat']) ? (float) $row['total_with_vat'] : 0.0,
                'exchange_rate' => $row['exchange_rate'] !== null ? (float) $row['exchange_rate'] : null,
                'currency' => (string) $row['currency'],
                'client_company_name' => (string) $row['client_company_name'],
                'supplier_company_name' => (string) $row['supplier_company_name'],
                'supplier_display_name' => (string) ($row['supplier_display_name'] ?? ''),
                'email_branding_enabled' => (bool) $row['email_branding_enabled'],
                'email_accent_color' => (string) ($row['email_accent_color'] ?? '#3B2D83'),
                'token_expires_at' => $row['public_invoice_token_expires_at'],
                'view_count' => (int) $row['public_invoice_view_count'],
            ],
        ]);
    }
}
