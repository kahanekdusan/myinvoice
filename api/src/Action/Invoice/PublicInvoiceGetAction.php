<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PublicInvoiceTokenValidator;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/public/invoice/{token}
 */
final class PublicInvoiceGetAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        if (!PublicInvoiceTokenValidator::isValidFormat($token)) {
            return $this->invalidTokenResponse($response);
        }

        $invoice = $this->repo->findByPublicViewToken($token);
        if ($invoice === null) {
            return $this->invalidTokenResponse($response);
        }

        $invoiceId = (int) $invoice['id'];
        $this->repo->markPublicLinkOpened($invoiceId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $ua = $request->getHeaderLine('User-Agent');
        $q = $request->getQueryParams();
        $this->logger->log('invoice.public_link_opened', null, 'invoice', $invoiceId, [
            'utm_source' => (string) ($q['utm_source'] ?? ''),
            'utm_medium' => (string) ($q['utm_medium'] ?? ''),
            'utm_campaign' => (string) ($q['utm_campaign'] ?? ''),
        ], $ip, $ua);

        return Json::ok($response, [
            'invoice' => [
                'id' => $invoiceId,
                'varsymbol' => $invoice['varsymbol'],
                'invoice_type' => $invoice['invoice_type'],
                'issue_date' => $invoice['issue_date'],
                'tax_date' => $invoice['tax_date'],
                'due_date' => $invoice['due_date'],
                'currency' => $invoice['currency'],
                'language' => $invoice['language'],
                'status' => $invoice['status'],
                'total_without_vat' => $invoice['total_without_vat'],
                'total_vat' => $invoice['total_vat'],
                'total_with_vat' => $invoice['total_with_vat'],
                'amount_to_pay' => $invoice['amount_to_pay'],
                'payment_method' => $invoice['payment_method'],
                'client_company_name' => $invoice['client_company_name'] ?? null,
                'items' => $invoice['items'] ?? [],
                'vat_breakdown' => $invoice['vat_breakdown'] ?? [],
                'totals' => $invoice['totals'] ?? [],
            ],
            'pdf_url' => '/api/public/invoice/' . $token . '/pdf',
        ]);
    }

    private function invalidTokenResponse(Response $response): Response
    {
        return Json::error($response, 'token_invalid_or_expired', 'Tento odkaz není platný.', 404);
    }
}
