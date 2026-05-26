<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;

final class PublicInvoiceLinkFactory
{
    public function __construct(private readonly Config $config) {}

    public function build(string $token): string
    {
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        if ($appUrl === '') {
            $appUrl = 'http://localhost:8080';
        }

        $query = http_build_query([
            'utm_source' => 'invoice_email',
            'utm_medium' => 'email',
            'utm_campaign' => 'invoice_link',
        ]);

        return $appUrl . '/invoice/' . $token . ($query !== '' ? ('?' . $query) : '');
    }
}
