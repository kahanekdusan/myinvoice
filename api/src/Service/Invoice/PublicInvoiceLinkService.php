<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\PublicInvoiceLinkRepository;

final class PublicInvoiceLinkService
{
    private const DEFAULT_TTL_DAYS = 180;

    public function __construct(
        private readonly PublicInvoiceLinkRepository $repo,
        private readonly Config $config,
    ) {}

    /**
     * Vytvoøí/vrátí platný public token a kompletní URL.
     */
    public function ensurePublicUrl(int $invoiceId): string
    {
        $ttl = (int) ($this->config->get('invoice.public_link_ttl_days') ?? self::DEFAULT_TTL_DAYS);
        $ttl = $ttl > 0 ? $ttl : self::DEFAULT_TTL_DAYS;
        $token = $this->repo->ensureToken($invoiceId, $ttl);

        $base = (string) ($this->config->get('app.url') ?? '');
        if ($base === '') {
            throw new \RuntimeException('Missing config app.url for public invoice links.');
        }
        $base = rtrim($base, '/');

        // Odkaz v e-mailu míøí rovnou na PDF endpoint — pøíjemce tak vidí fakturu
        // bez mezikroku (žádná landing stránka s tlaèítkem). Otevøení se stále
        // loguje pøes PublicInvoicePdfAction::touchViewed.
        return $base . '/api/public/invoice/' . $token . '/pdf';
    }

    public function markSent(int $invoiceId): void
    {
        $this->repo->markSent($invoiceId);
    }
}
