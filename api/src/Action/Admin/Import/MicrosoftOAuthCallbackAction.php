<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\MicrosoftSmtpOAuthService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MicrosoftOAuthCallbackAction
{
    public function __construct(
        private readonly MicrosoftSmtpOAuthService $oauth,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = (array) $request->getQueryParams();
        $state = (string) ($q['state'] ?? '');
        $code = isset($q['code']) ? (string) $q['code'] : null;
        $error = isset($q['error']) ? (string) $q['error'] : null;
        $errorDescription = isset($q['error_description']) ? (string) $q['error_description'] : null;

        try {
            $result = $this->oauth->handleCallback($state, $code, $error, $errorDescription);
            $message = 'Microsoft úèet byl úspìšnì pøipojen.';
            $this->logger->log('import.microsoft_oauth_connected', (int) $result['user_id'], 'supplier', (int) $result['supplier_id'], [
                'mailbox' => $result['mailbox'],
                'token_expires_at' => $result['token_expires_at'],
            ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));
            return $this->popupResult($response, true, $message);
        } catch (\Throwable $e) {
            $message = $e->getMessage() !== ''
                ? $e->getMessage()
                : 'Pøipojení Microsoft úètu se nezdaøilo.';
            $this->logger->log('import.microsoft_oauth_failed', null, 'supplier', null, [
                'error' => mb_substr($message, 0, 500),
            ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));
            return $this->popupResult($response, false, $message);
        }
    }

    private function popupResult(Response $response, bool $ok, string $message): Response
    {
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeType = $ok ? 'success' : 'error';
        $redirect = '/admin/integrations?tab=microsoft&oauth=' . $safeType;
        $jsonMessage = (string) json_encode(
            $message,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        $jsonRedirect = (string) json_encode(
            $redirect,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        $html = '<!doctype html><html lang="cs"><head><meta charset="utf-8"><title>Microsoft OAuth</title></head><body>'
            . '<p style="font-family:system-ui,Segoe UI,sans-serif;max-width:620px;margin:32px auto;color:#1f2937;">'
            . $safeMessage
            . '<br><a href="' . $redirect . '">Pokraèovat do integrací</a></p>'
            . '<script>'
            . 'const payload={type:"myinvoice:microsoft-oauth",ok:' . ($ok ? 'true' : 'false') . ',message:' . $jsonMessage . '};'
            . 'try{if(window.opener&&!window.opener.closed){window.opener.postMessage(payload,window.location.origin);window.close();}}catch(e){}'
            . 'setTimeout(()=>{window.location.href=' . $jsonRedirect . ';},300);'
            . '</script></body></html>';

        $response->getBody()->write($html);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
