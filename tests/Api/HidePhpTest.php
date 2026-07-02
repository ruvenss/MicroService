<?php

declare(strict_types=1);

use App\Filters\HidePhp;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class HidePhpTest extends CIUnitTestCase
{
    /**
     * @dataProvider phpUris
     */
    public function testDetectsPhpReferences(string $uri, bool $expected): void
    {
        $this->assertSame($expected, HidePhp::referencesPhp($uri));
    }

    public static function phpUris(): array
    {
        return [
            'front controller path info' => ['/index.php/api/v1/health', true],
            'bare index.php'             => ['/index.php', true],
            'probe file'                 => ['/phpinfo.php', true],
            'nested php'                 => ['/a/b/config.php', true],
            // Case-insensitive: `/index.PHP` must not slip past the guard (Apache would
            // still hand a `.PHP` request to the FPM handler). Pins the regex `i` flag —
            // dropping it would reopen the bypass while the lowercase cases stayed green.
            'uppercase .PHP'             => ['/index.PHP', true],
            'mixed-case .pHp'            => ['/index.pHp', true],
            'uppercase .PHP path info'   => ['/index.PHP/api/v1/health', true],
            'clean route'                => ['/api/v1/health', false],
            'clean nested'               => ['/api/v1/products/5', false],
            'php only in query'          => ['/api/v1/products?note=evil.php', false],
            'root'                       => ['/', false],
        ];
    }
}
