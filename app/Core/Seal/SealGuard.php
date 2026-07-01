<?php

declare(strict_types=1);

namespace App\Core\Seal;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Sealed-core invariant (ARCHITECTURE §15, decision #10): the core (`app/`) must
 * stay generic and never couple to a concrete plugin. Features live in plugins/
 * and are discovered dynamically at runtime by manifest — so no core file should
 * ever reference a concrete plugin namespace (a `Plugins\` prefix followed by a
 * real vendor name).
 *
 * The plugin mechanism is allowed: the generic template `Plugins\{$vendor}\…`,
 * the `plugins/` glob discovery, and the `Plugins\` prefix in prose all pass —
 * only a concrete `Plugins\<Vendor>` reference (a hard dependency on one plugin) fails.
 * (This file deliberately never spells out a concrete example, so it does not trip
 * its own check.)
 */
final class SealGuard
{
    /**
     * Concrete plugin reference: `Plugins\` + one/more backslashes + an uppercase
     * vendor letter, e.g. a `use` import or a hard-coded namespace string. Not
     * matched: `Plugins\{$vendor}` (a `{` follows) nor `Plugins\<Vendor>`/`Plugins\…`.
     */
    private const CONCRETE_PLUGIN_REF = '/Plugins\\\\+[A-Z]/';

    /**
     * Scan a directory tree of PHP files for core→plugin coupling.
     *
     * @return list<array{file: string, line: int, text: string}> violations, empty when the seal holds
     */
    public static function violations(string $dir): array
    {
        $violations = [];

        if (! is_dir($dir)) {
            return $violations;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) ?: [] as $index => $text) {
                if (preg_match(self::CONCRETE_PLUGIN_REF, $text) === 1) {
                    $violations[] = [
                        'file' => $file->getPathname(),
                        'line' => $index + 1,
                        'text' => trim($text),
                    ];
                }
            }
        }

        return $violations;
    }
}
