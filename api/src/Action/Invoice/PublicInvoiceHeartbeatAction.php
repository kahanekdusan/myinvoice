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
 * POST /api/public/invoice/{token}/heartbeat
 */
final class PublicInvoiceHeartbeatAction
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

        $invoice = $this->repo->findByPublicToken($token)
            ?? $this->repo->findByPublicViewToken($token);
        if ($invoice === null) {
            return $this->invalidTokenResponse($response);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $seconds = isset($body['seconds_on_page']) ? (int) $body['seconds_on_page'] : 0;
        if ($seconds < 10) {
            return Json::ok($response, ['accepted' => false, 'reason' => 'minimum_10_seconds']);
        }

        $invoiceId = (int) $invoice['id'];
        $this->repo->markPublicLinkViewed($invoiceId, $seconds);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.public_link_viewed', null, 'invoice', $invoiceId, [
            'seconds_on_page' => $seconds,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['accepted' => true]);
    }

    private function invalidTokenResponse(Response $response): Response
    {
        return Json::error($response, 'token_invalid_or_expired', 'Tento odkaz není platný.', 404);
    }
}
