<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LogoutAction
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly Config $config,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $token = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        $user  = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        $sessionCookieNames = self::sessionCookieNames($this->config);

        // Zneplatni vsechny session tokeny nalezene v requestu (vcetne duplicit
        // stejneho cookie jmena na ruznych Path), aby nedoslo k okamzitemu reloginu.
        foreach ($this->collectSessionTokensToDestroy($request, $token, $sessionCookieNames) as $tok) {
            $this->sessions->destroy($tok);
        }

        // Fallback: pokud je uzivatel autentizovany, odhlas vsechny jeho session.
        // V praxi to eliminuje edge-case, kdy browser posle jinou legacy cookie,
        // nez kterou middleware vyhodnoti jako aktivni.
        if (isset($user['id']) && (int) $user['id'] > 0) {
            $this->sessions->destroyAllForUser((int) $user['id']);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log(
            'auth.logout',
            isset($user['id']) ? (int) $user['id'] : null,
            'user',
            isset($user['id']) ? (int) $user['id'] : null,
            null,
            $ip,
            $request->getHeaderLine('User-Agent'),
        );

        $sameSite = self::normalizeSameSite((string) $this->config->get('session.cookie_samesite', 'Lax'));
        $cookieSecure = (bool) $this->config->get('session.cookie_secure', true);
        $trustedCookieName = (string) $this->config->get('auth.email_otp.trusted_cookie_name', '__Host-myinvoice_td');

        $result = Json::ok($response, ['ok' => true]);

        foreach ($sessionCookieNames as $cookieName) {
            $result = $result->withAddedHeader('Set-Cookie', self::expiredCookie($cookieName, '/', $sameSite, $cookieSecure));
            $result = $result->withAddedHeader('Set-Cookie', self::expiredCookie($cookieName, '/api', $sameSite, $cookieSecure));
            // Legacy non-secure varianta po lokalnim HTTP behu.
            if ($cookieSecure) {
                $result = $result->withAddedHeader('Set-Cookie', self::expiredCookie($cookieName, '/', $sameSite, false));
                $result = $result->withAddedHeader('Set-Cookie', self::expiredCookie($cookieName, '/api', $sameSite, false));
            }
        }

        $result = $result->withAddedHeader('Set-Cookie', self::expiredCookie($trustedCookieName, '/', $sameSite, $cookieSecure));
        if ($cookieSecure) {
            $result = $result->withAddedHeader('Set-Cookie', self::expiredCookie($trustedCookieName, '/', $sameSite, false));
        }

        // Browser-level cleanup cookie jaru pro aktualni origin (Chrome/Edge).
        return $result->withHeader('Clear-Site-Data', '"cookies"');
    }

    /**
     * @return string[]
     */
    private static function sessionCookieNames(Config $config): array
    {
        return array_values(array_unique([
            (string) $config->get('session.cookie_name', '__Host-myinvoice_session'),
            '__Host-myinvoice_session',
            'myinvoice_session',
        ]));
    }

    /**
     * @param string[] $sessionCookieNames
     * @return string[]
     */
    private function collectSessionTokensToDestroy(Request $request, string $attrToken, array $sessionCookieNames): array
    {
        $tokens = [];

        if ($attrToken !== '') {
            $normalized = strtolower($attrToken);
            if (preg_match('/^[a-f0-9]{64}$/', $normalized) === 1) {
                $tokens[$normalized] = true;
            }
        }

        $nameSet = array_fill_keys($sessionCookieNames, true);
        $rawCookie = $request->getHeaderLine('Cookie');
        if ($rawCookie !== '') {
            foreach (explode(';', $rawCookie) as $part) {
                $part = trim($part);
                if ($part === '' || !str_contains($part, '=')) {
                    continue;
                }

                [$name, $value] = array_map('trim', explode('=', $part, 2));
                if (!isset($nameSet[$name])) {
                    continue;
                }

                $value = strtolower(urldecode($value));
                if (preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
                    $tokens[$value] = true;
                }
            }
        }

        return array_keys($tokens);
    }

    private static function normalizeSameSite(string $sameSite): string
    {
        $sameSite = ucfirst(strtolower(trim($sameSite)));
        return in_array($sameSite, ['Lax', 'Strict', 'None'], true) ? $sameSite : 'Lax';
    }

    private static function expiredCookie(string $name, string $path, string $sameSite, bool $secure): string
    {
        return sprintf(
            '%s=; HttpOnly; Path=%s; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=%s%s',
            $name,
            $path,
            $sameSite,
            $secure ? '; Secure' : '',
        );
    }
}
