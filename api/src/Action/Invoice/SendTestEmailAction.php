<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PublicInvoiceLinkFactory;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Test odeslání faktury — na email aktuálního supplier (fallback cfg.smtp.from_email).
 *
 * Funguje i pro draft (varsymbol může být null).
 * Neměnu invoice.status ani invoice.sent_at.
 * Activity log: email.sent_test
 */
final class SendTestEmailAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Mailer $mailer,
        private readonly InvoiceEmailVarsBuilder $varsBuilder,
        private readonly PublicInvoiceLinkFactory $linkFactory,
        private readonly Config $config,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($invoice['invoice_type'] === 'cancellation') {
            return Json::error($response, 'cannot_send_cancellation', 'Interní storno se klientovi neposílá.', 409);
        }

        // Test recipient = supplier.email (fallback cfg.smtp.from_email)
        $stmt = $this->db->pdo()->prepare('SELECT email FROM supplier WHERE id = ?');
        $stmt->execute([(int) $invoice['supplier_id']]);
        $testRecipient = trim((string) $stmt->fetchColumn());
        if ($testRecipient === '' || !filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
            $testRecipient = (string) $this->config->get('smtp.from_email', '');
        }
        if ($testRecipient === '') {
            return Json::error($response, 'no_test_recipient', 'Supplier nemá email a cfg.smtp.from_email není nastaveno.', 500);
        }

        $publicToken = $this->repo->rotatePublicViewToken($id);
        $invoiceViewUrl = $this->linkFactory->build($publicToken);

        $locale = (string) ($invoice['language'] ?? 'cs');
        $vars = $this->varsBuilder->build($invoice, true, $locale, $invoiceViewUrl);

        $smtpResponse = '';
        try {
            $smtpResponse = $this->mailer->sendTemplate(
                'invoice_send',
                $locale,
                [$testRecipient],
                $vars,
                null,
                [],
                [],
                [],
            );
        } catch (\Throwable $e) {
            return Json::error($response, 'send_failed', 'Email se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('email.sent_test', $user['id'] ?? null, 'invoice', $id, [
            'to'            => $testRecipient,
            'delivery_mode' => 'public_link',
            'invoice_view_url' => $invoiceViewUrl,
            'smtp_response' => $smtpResponse,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'sent_to' => [$testRecipient],
            'sent_at' => date('Y-m-d H:i:s'),
            'is_test' => true,
        ]);
    }
}
