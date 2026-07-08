<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Repository\PublicInvoiceLinkRepository;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PublicInvoiceHeartbeatAction
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

        $this->repo->touchHeartbeat((int) $row['id']);

        return Json::ok($response, [
            'ok' => true,
            'invoice_id' => (int) $row['id'],
        ]);
    }
}
