<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\MicrosoftSmtpOAuthService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MicrosoftOAuthStartAction
{
    public function __construct(
        private readonly MicrosoftSmtpOAuthService $oauth,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        $tenantId = trim((string) ($body['tenant_id'] ?? 'common'));
        $clientId = trim((string) ($body['client_id'] ?? ''));
        $clientSecret = (string) ($body['client_secret'] ?? '');
        $mailboxRaw = trim((string) ($body['mailbox'] ?? ''));

        if (strlen($tenantId) > 120 || strlen($clientId) > 190 || strlen($clientSecret) > 512 || strlen($mailboxRaw) > 190) {
            return Json::error($response, 'validation_failed', 'Nìkteré pole pøesahuje délkový limit.', 400);
        }
        if ($tenantId !== '' && !preg_match('/^[a-zA-Z0-9._-]+$/', $tenantId)) {
            return Json::error($response, 'validation_failed', 'tenant_id obsahuje nepovolené znaky.', 400);
        }
        $mailbox = null;
        if ($mailboxRaw !== '') {
            if (!filter_var($mailboxRaw, FILTER_VALIDATE_EMAIL)) {
                return Json::error($response, 'validation_failed', 'Neplatný formát mailbox e-mailu.', 400);
            }
            $mailbox = $mailboxRaw;
        }

        try {
            $result = $this->oauth->beginAuthorization(
                $supplierId,
                (int) ($user['id'] ?? 0),
                $tenantId,
                $clientId,
                $clientSecret !== '' ? $clientSecret : null,
                $mailbox,
            );
        } catch (\Throwable $e) {
            return Json::error($response, 'oauth_start_failed', $e->getMessage(), 400);
        }

        $this->logger->log('import.microsoft_oauth_started', $user['id'] ?? null, 'supplier', $supplierId, [
            'tenant_id' => $tenantId !== '' ? $tenantId : null,
            'client_id_source' => $clientId !== '' ? 'request' : 'stored_or_cfg',
            'client_id_prefix' => $clientId !== '' ? substr($clientId, 0, 10) . '…' : null,
            'mailbox' => $mailbox,
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        if (strtoupper($request->getMethod()) === 'GET') {
            return $response
                ->withHeader('Location', $result['authorize_url'])
                ->withStatus(302);
        }

        return Json::ok($response, $result);
    }
}
