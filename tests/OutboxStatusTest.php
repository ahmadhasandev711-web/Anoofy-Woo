<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests;

use PHPUnit\Framework\TestCase;

final class OutboxStatusTest extends TestCase
{
    public function testEnumCasesAndConstantExist(): void
    {
        $this->assertTrue(enum_exists(\Anoofy\Woo\Core\Connection\OutboxStatus::class), 'OutboxStatus enum must exist');
        $this->assertSame('pending', \Anoofy\Woo\Core\Connection\OutboxStatus::Pending->value);
        $this->assertSame('in_flight', \Anoofy\Woo\Core\Connection\OutboxStatus::InFlight->value);
        $this->assertSame('sent', \Anoofy\Woo\Core\Connection\OutboxStatus::Sent->value);
        $this->assertSame('failed', \Anoofy\Woo\Core\Connection\OutboxStatus::Failed->value);

        $this->assertTrue(defined('\Anoofy\Woo\Services\ActionResult::CONNECTION_FAILED'));
        $this->assertSame('CONNECTION_FAILED', \Anoofy\Woo\Services\ActionResult::CONNECTION_FAILED);
    }

    public function testNoRawStatusStringsInSourceCode(): void
    {
        $srcDir = dirname(__DIR__) . '/src';
        $forbidden = ["'pending'", "'in_flight'", "'sent'", "'failed'"];
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $relativePath = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));

            // Allowed files: OutboxStatus.php and Schema.php (SQL DDL)
            if ($relativePath === 'Core/Connection/OutboxStatus.php' || $relativePath === 'Database/Schema.php') {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES);
            foreach ($lines as $lineNum => $line) {
                // Ignore comments
                $trimmed = trim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                    continue;
                }

                foreach ($forbidden as $needle) {
                    if (str_contains($line, $needle)) {
                        $violations[] = "{$relativePath}:" . ($lineNum + 1) . " contains {$needle} -> {$trimmed}";
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Found raw status string literals in src/:\n" . implode("\n", $violations)
        );
    }
}
