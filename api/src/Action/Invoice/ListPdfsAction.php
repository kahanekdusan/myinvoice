<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Pdf\PdfArchiveService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/invoices/{id}/pdfs — list archivovaných verzí PDF (sent kopie + invalidované).
 * Autorizace přes SupplierGuard (invoice musí patřit current supplier scope).
 */
final class ListPdfsAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly PdfArchiveService $archive,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        return Json::ok($response, ['items' => $this->archive->listForInvoice($id)]);
    }
}
