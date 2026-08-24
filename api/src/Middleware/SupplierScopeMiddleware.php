<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Multi-supplier scope: čte hlavičku `X-Supplier-Id` (z Pinia stores na FE) a
 * vystaví ji jako request attribute. Akce čtou přes:
 *
 *   $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
 *
 * Pravidla (resoluci sdílí SupplierAccessResolver — používá ji i RoleMiddleware
 * pro efektivní per-supplier roli):
 *   - PAT bound na supplier_id → forcuj ho, header/query se ignoruje
 *   - Pokud header chybí nebo není v DB, fallback = MIN(supplier.id), resp.
 *     nejnižší PŘIŘAZENÝ supplier u uživatele s membership (user_suppliers)
 *   - Uživatel s neprázdným membership, který si explicitně vyžádá firmu mimo
 *     své membership → 403 `forbidden_supplier` (dřív směl kamkoliv)
 *   - Uživatel bez membership řádků = bez omezení (zpětná kompatibilita)
 *   - Pokud supplier tabulka prázdná (před setup) → 0 (akce by stejně měly být chráněné Authem)
 *   - Validace se memoizuje v rámci requestu (resolver)
 */
final class SupplierScopeMiddleware implements MiddlewareInterface
{
    public const ATTR_CURRENT_ID = 'supplier.current_id';
    public const HEADER_NAME     = 'X-Supplier-Id';

    public function __construct(
        private readonly SupplierAccessResolver $resolver,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/auth/webauthn/')
            || str_starts_with($path, '/api/auth/mfa/')
            || str_starts_with($path, '/api/auth/session/')
        ) {
            return $handler->handle($request);
        }

        $access = $this->resolver->resolve($request);

        if ($access->denied) {
            $response = $this->responseFactory->createResponse(403);
            return Json::error($response, 'forbidden_supplier', 'K této firmě nemáš oprávnění.', 403);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $resolved = $this->resolve($requested, $user);

        return $handler->handle(
            $request->withAttribute(self::ATTR_CURRENT_ID, $access->supplierId),
        );
    }

    /**
     * Vrátí platné supplier_id:
     *  - $requested pokud existuje v DB
     *  - jinak MIN(id)
     *  - jinak 0 (před setup)
     */
    private function resolve(int $requested, array $user = []): int
    {
        $pdo = $this->db->pdo();

        if ($requested > 0) {
            $stmt = $pdo->prepare('SELECT id FROM supplier WHERE id = ? LIMIT 1');
            $stmt->execute([$requested]);
            $id = (int) $stmt->fetchColumn();
            if ($id > 0) return $id;
        }

        $fallback = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($fallback > 0) {
            return $fallback;
        }

        // Self-heal pro fresh setup: pokud není žádný supplier a je přihlášený admin,
        // založ výchozího suppliera, aby aplikace mohla fungovat bez ručního SQL zásahu.
        return $this->bootstrapDefaultSupplier($user);
    }

    private function bootstrapDefaultSupplier(array $user): int
    {
        if (($user['role'] ?? '') !== 'admin') {
            return 0;
        }

        $pdo = $this->db->pdo();

        try {
            $pdo->beginTransaction();

            $existing = (int) $pdo->query('SELECT MIN(id) FROM supplier FOR UPDATE')->fetchColumn();
            if ($existing > 0) {
                $pdo->commit();
                return $existing;
            }

            $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
            if ($countryId === 0) {
                $countryId = (int) $pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn();
            }

            $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
            if ($vatRateId === 0) {
                $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
            }

            if ($countryId === 0 || $vatRateId === 0) {
                $pdo->rollBack();
                return 0;
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            $companyName = trim((string) ($user['name'] ?? 'MyInvoice Supplier'));
            if ($companyName === '') {
                $companyName = 'MyInvoice Supplier';
            }
            $email = trim((string) ($user['email'] ?? 'admin@example.com'));
            if ($email === '') {
                $email = 'admin@example.com';
            }

            $stmt = $pdo->prepare(
                'INSERT INTO supplier
                (company_name, display_name, street, city, zip, country_id, ic, dic, is_vat_payer,
                 email, phone, web, default_currency_id, default_vat_rate_id, default_payment_due_days, default_hourly_rate)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
            );
            $stmt->execute([
                $companyName,
                $companyName,
                'Unknown street',
                'Unknown city',
                '00000',
                $countryId,
                null,
                null,
                0,
                $email,
                null,
                null,
                $vatRateId,
                14,
                '0.00',
            ]);
            $supplierId = (int) $pdo->lastInsertId();

            if ($supplierId <= 0) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                $pdo->rollBack();
                return 0;
            }

            $insertCur = $pdo->prepare(
                'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, 2, 1, 1)'
            );
            $insertCur->execute([$supplierId, 'CZK', 'CZK - default', 'Kc', 'Ceska koruna', 'Czech Koruna']);
            $defaultCurrencyId = (int) $pdo->lastInsertId();
            $insertCur->execute([$supplierId, 'EUR', 'EUR - default', 'EUR', 'Euro', 'Euro']);

            if ($defaultCurrencyId > 0) {
                $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
                    ->execute([$defaultCurrencyId, $supplierId]);
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $pdo->commit();

            return $supplierId;
        } catch (\Throwable $e) {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable) {
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return 0;
        }
    }
}
