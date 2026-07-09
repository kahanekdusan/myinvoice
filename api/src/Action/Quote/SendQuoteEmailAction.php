<?php

declare(strict_types=1);

namespace MyInvoice\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\QuoteRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\QuoteEmailVarsBuilder;
use MyInvoice\Service\Mail\RecipientResolver;
use MyInvoice\Service\Pdf\QuotePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/quotes/{id}/send
 *
 * Odešle cenovou nabídku klientovi e-mailem s PDF přílohou.
 */
final class SendQuoteEmailAction
{
    public function __construct(
        private readonly QuoteRepository $repo,
        private readonly Connection $db,
        private readonly QuotePdfRenderer $renderer,
        private readonly Mailer $mailer,
        private readonly QuoteEmailVarsBuilder $varsBuilder,
        private readonly RecipientResolver $recipients,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $quote = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $quote)) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }
        if (in_array((string) ($quote['status'] ?? ''), ['invoiced', 'rejected'], true)) {
            return Json::error($response, 'invalid_state', 'Nabídku v tomto stavu nelze odeslat e-mailem.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $overrideTo = isset($body['to']) && is_array($body['to'])
            ? array_values(array_filter(array_map('trim', $body['to'])))
            : null;
        $cc = isset($body['cc']) && is_array($body['cc'])
            ? array_values(array_filter(array_map('trim', $body['cc'])))
            : [];
        $bcc = isset($body['bcc']) && is_array($body['bcc'])
            ? array_values(array_filter(array_map('trim', $body['bcc'])))
            : [];
        $subjectOverride = isset($body['subject_override']) ? (string) $body['subject_override'] : null;

        $noteRaw = isset($body['note']) ? trim((string) $body['note']) : '';
        if ($noteRaw !== '' && mb_strlen($noteRaw) > 5000) {
            $noteRaw = mb_substr($noteRaw, 0, 5000);
        }
        $noteLines = [];
        if ($noteRaw !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $noteRaw) as $line) {
                $line = trim((string) $line);
                if ($line !== '') {
                    $noteLines[] = $line;
                }
            }
        }

        $resolvedRecipients = [];
        if ($overrideTo !== null) {
            $to = $overrideTo;
            $supplierCopy = $this->recipients->supplierCopy((int) ($quote['supplier_id'] ?? 0), RecipientResolver::TYPE_DOCUMENTS);
            if ($supplierCopy !== null) {
                if ($supplierCopy['recipient'] === 'cc') {
                    $cc[] = $supplierCopy['email'];
                } else {
                    $bcc[] = $supplierCopy['email'];
                }
            }
            $cc = array_values(array_unique($cc));
            $bcc = array_values(array_unique($bcc));
        } else {
            $resolved = $this->recipients->resolve(RecipientResolver::TYPE_DOCUMENTS, [
                'client_id'         => (int) ($quote['client_id'] ?? 0),
                'client_main_email' => (string) ($quote['client_main_email'] ?? ''),
                'project_id'        => $quote['project_id'] ?? null,
                'supplier_id'       => (int) ($quote['supplier_id'] ?? 0),
            ]);
            $to = $resolved['to'];
            $cc = array_values(array_unique(array_merge($resolved['cc'], $cc)));
            $bcc = array_values(array_unique(array_merge($resolved['bcc'], $bcc)));
            $resolvedRecipients = $resolved['resolved'];
        }

        // Dedup napříč rolemi s prioritou TO > CC > BCC.
        $to = array_values(array_unique($to));
        $cc = array_values(array_unique(array_diff($cc, $to)));
        $bcc = array_values(array_unique(array_diff($bcc, $to, $cc)));

        if ($to === []) {
            return Json::error($response, 'no_recipients', 'Žádný platný příjemce (chybí e-mail klienta).', 400);
        }

        foreach ([...$to, ...$cc, ...$bcc] as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Json::error($response, 'invalid_email', "Neplatný e-mail: {$email}", 400);
            }
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;

        try {
            $pdfPath = $this->renderer->render($id);
        } catch (\Throwable $e) {
            return Json::error($response, 'pdf_failed', 'Nepodařilo se vygenerovat PDF nabídky: ' . $e->getMessage(), 500);
        }

        $locale = (string) ($quote['language'] ?? 'cs');
        $vars = $this->varsBuilder->build($quote, false, $locale);
        $vars['note_lines'] = $noteLines;
        $vars['note_text'] = $noteRaw;

        $attachments = [[
            'path' => $pdfPath,
            'name' => basename($pdfPath),
            'contentType' => 'application/pdf',
        ]];

        try {
            $smtpResponse = $this->mailer->sendTemplate(
                'quote_send',
                $locale,
                $to,
                $vars,
                $subjectOverride,
                $cc,
                $bcc,
                $attachments,
                $userId,
            );
        } catch (\Throwable $e) {
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('quote.send_failed', $user['id'] ?? null, 'quote', $id, [
                'to' => $to,
                'cc' => $cc,
                'bcc' => $bcc,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ], $ip, $request->getHeaderLine('User-Agent'));

            return Json::error($response, 'send_failed', 'E-mail se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }

        $this->db->pdo()->prepare(
            'UPDATE quotes
                SET status = CASE WHEN status = "draft" THEN "sent" ELSE status END
              WHERE id = ?'
        )->execute([$id]);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('quote.sent', $user['id'] ?? null, 'quote', $id, [
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'resolved_recipients' => $resolvedRecipients,
            'pdf_path' => basename($pdfPath),
            'smtp_response' => $smtpResponse,
            'note_chars' => mb_strlen($noteRaw),
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'sent_to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'resolved_recipients' => $resolvedRecipients,
            'sent_at' => date('Y-m-d H:i:s'),
            'is_test' => false,
        ]);
    }
}
