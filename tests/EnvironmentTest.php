<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests;

use Anoofy\Woo\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testSupportedVersionsHaveNoProblems(): void
    {
        self::assertSame([], Environment::problems('8.3.6', '7.1.3', '11.2.1'));
        self::assertSame([], Environment::problems('8.1.0', '7.0', '11.2'));
    }

    public function testOldPhpIsReported(): void
    {
        $p = Environment::problems('8.0.30', '7.1', '11.2.1');
        self::assertCount(1, $p);
        self::assertSame('PHP 8.1.0 or newer is required (found 8.0.30).', $p[0]);
    }

    public function testMissingWooCommerceIsReported(): void
    {
        self::assertSame(['WooCommerce must be installed and active.'], Environment::problems('8.3.0', '7.1', null));
    }

    public function testOldWooCommerceAndWordPressAreReported(): void
    {
        $p = Environment::problems('8.3.0', '6.9', '10.4.0');
        self::assertCount(2, $p);
    }
}
