<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Config;

use MyInvoice\Infrastructure\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigVarsymbolDefaultsTest extends TestCase
{
    private string $tmpRoot;

    private string|false $dataDirBackup;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/myinvoice-varsymbol-cfg-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0700, true);

        $this->dataDirBackup = getenv('MYINVOICE_DATA_DIR');
        putenv('MYINVOICE_DATA_DIR');
    }

    protected function tearDown(): void
    {
        if ($this->dataDirBackup === false) {
            putenv('MYINVOICE_DATA_DIR');
        } else {
            putenv('MYINVOICE_DATA_DIR=' . $this->dataDirBackup);
        }

        @unlink($this->tmpRoot . '/cfg.php');
        @rmdir($this->tmpRoot);
    }

    public function testEmptyConfigGetsQuoteNumberingTemplate(): void
    {
        $this->writeConfig([]);

        $config = Config::load($this->tmpRoot);

        self::assertSame('2{YY}{MM}{CCC}', $config->get('varsymbol.templates.quote'));
    }

    public function testExplicitQuoteNumberingTemplateOverridesDefault(): void
    {
        $this->writeConfig([
            'varsymbol' => [
                'templates' => [
                    'quote' => 'CN-{YYYY}-{CCCC}',
                ],
            ],
        ]);

        $config = Config::load($this->tmpRoot);

        self::assertSame('CN-{YYYY}-{CCCC}', $config->get('varsymbol.templates.quote'));
    }

    /** @param array<string, mixed> $config */
    private function writeConfig(array $config): void
    {
        $exported = var_export($config, true);
        file_put_contents($this->tmpRoot . '/cfg.php', "<?php return {$exported};\n");
    }
}
