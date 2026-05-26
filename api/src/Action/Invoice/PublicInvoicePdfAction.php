<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\PublicInvoiceTokenValidator;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * GET /api/public/invoice/{token}/pdf
 */
final class PublicInvoicePdfAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoicePdfRenderer $renderer,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');

        $token = (string) ($args['token'] ?? '');
        if (!PublicInvoiceTokenValidator::isValidFormat($token)) {
            return $this->invalidTokenResponse($response);
        }

        $invoice = $this->repo->findByPublicViewToken($token);
        if ($invoice === null) {
            return $this->invalidTokenResponse($response);
        }

        try {
            $path = $this->renderer->render((int) $invoice['id']);
        } catch (\Throwable $e) {
            return Json::error($response, 'pdf_failed', 'Nepodarilo se vygenerovat PDF.', 500);
        }

        $filename = basename($path);
        $stream = new Stream(fopen($path, 'rb'));

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', "inline; filename=\"{$filename}\"")
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($stream);
    }

    private function invalidTokenResponse(Response $response): Response
    {
        return Json::error($response, 'token_invalid_or_expired', 'Tento odkaz není platný.', 404);
    }
}
