<?php

declare(strict_types=1);

namespace MyInvoice\Service;

/**
 * Najde CLI `php` binárku pro spouštění detached workerů (import, cron).
 *
 * Pod IIS / FastCGI je `PHP_BINARY` typicky `php-cgi.exe`, který CLI skripty
 * (`if (PHP_SAPI !== 'cli') exit;`) spustí špatně, a `php` často NENÍ na PATH
 * procesu w3wp.exe. Holé `php` ve spawn příkazu proto tiše selže a worker se
 * nikdy nespustí (job zůstane navždy „queued"). Tento helper vrátí skutečnou
 * cestu k CLI php.exe (sibling vedle php-cgi, běžné instalační cesty, …).
 */
final class PhpCliLocator
{
    /**
     * @return string|null Cesta / příkaz CLI php, nebo null pokud nic nenalezeno.
     */
    public static function resolve(): ?string
    {
        $candidates = [];
        $b = PHP_BINARY;
        if ($b !== '') {
            $candidates[] = $b;
            $dir = dirname($b);
            $candidates[] = $dir . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates[] = 'C:\\inetpub\\php\\php.exe';
            $candidates[] = 'C:\\Program Files\\PHP\\php.exe';
            $candidates[] = 'php.exe';
        } else {
            $candidates[] = '/usr/bin/php';
            $candidates[] = '/usr/local/bin/php';
            $candidates[] = 'php';
        }

        foreach ($candidates as $c) {
            $name = strtolower(basename($c));
            // Vyhneme se php-cgi.exe / php-win.exe / phpdbg.exe — chceme jen CLI.
            if ($name === 'php-cgi.exe' || $name === 'php-cgi' || $name === 'php-win.exe' || str_starts_with($name, 'phpdbg')) {
                continue;
            }
            if (str_contains($c, DIRECTORY_SEPARATOR) || str_contains($c, '/')) {
                if (is_file($c)) {
                    return $c;
                }
            } else {
                // Holý název → necháme PATH lookup na OS.
                return $c;
            }
        }

        return null;
    }
}
