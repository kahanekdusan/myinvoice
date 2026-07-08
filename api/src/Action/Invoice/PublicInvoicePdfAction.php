<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Repository\PublicInvoiceLinkRepository;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

final class PublicInvoicePdfAction
{
    public function __construct(
        private readonly PublicInvoiceLinkRepository $repo,
        private readonly InvoicePdfRenderer $pdfRenderer,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $token = trim((string) ($args['token'] ?? ''));
        if (!ApprovalTokenValidator::isValidFormat($token)) {
            $response->getBody()->write('Invoice link not found or expired.');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $row = $this->repo->findActiveByToken($token);
        if ($row === null) {
            $response->getBody()->write('Invoice link not found or expired.');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $invoiceId = (int) $row['id'];
        $userAgent = $request->getHeaderLine('User-Agent');
        if ($userAgent === '') {
            $userAgent = null;
        }
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        $this->repo->touchViewed($invoiceId, $ip, $userAgent);

        try {
            $pdfPath = $this->pdfRenderer->render($invoiceId, false, null);
        } catch (\Throwable) {
            $response->getBody()->write('PDF not available.');
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        if (!is_file($pdfPath)) {
            $response->getBody()->write('PDF not available.');
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $stream = fopen($pdfPath, 'rb');
        if ($stream === false) {
            $response->getBody()->write('Cannot open PDF.');
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $filename = basename($pdfPath);
        $body = new Stream($stream);
        return $response
            ->withBody($body)
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', "inline; filename=\"{$filename}\"")
            ->withHeader('Content-Length', (string) filesize($pdfPath))
            ->withHeader('Cache-Control', 'private, max-age=60');
    }
}
