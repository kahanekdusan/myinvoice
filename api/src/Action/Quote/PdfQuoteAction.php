<?php

declare(strict_types=1);

namespace MyInvoice\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\QuoteRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\QuotePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * GET /api/quotes/{id}/pdf
 */
final class PdfQuoteAction
{
    public function __construct(
        private readonly QuotePdfRenderer $renderer,
        private readonly QuoteRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');

        $id = (int) ($args['id'] ?? 0);
        $quote = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $quote)) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }

        $q = $request->getQueryParams();
        $regenerate = !empty($q['regenerate']);
        $download = !empty($q['download']);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        ob_start();
        try {
            $path = $this->renderer->render($id, $regenerate);
        } catch (\Throwable $e) {
            ob_end_clean();
            return Json::error($response, 'pdf_failed', $e->getMessage(), 500);
        }
        ob_end_clean();

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('quote.pdf_generated', $user['id'] ?? null, 'quote', $id, [
            'regenerate' => $regenerate,
            'path' => basename($path),
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = basename($path);
        $disposition = $download ? "attachment; filename=\"{$filename}\"" : "inline; filename=\"{$filename}\"";

        $stream = new Stream(fopen($path, 'rb'));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($stream);
    }
}
