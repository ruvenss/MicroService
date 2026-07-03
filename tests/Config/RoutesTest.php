<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * CodeIgniter 4.5+ deprecates passing lowercase HTTP verbs to `match()`
 * (E_USER_DEPRECATED, removed in 5.0). Guard the routes against a regression to
 * lowercase — the deprecation fires per request at route loading and, under strict
 * error handling, its backtrace renderer can even fault.
 *
 * @internal
 */
final class RoutesTest extends CIUnitTestCase
{
    public function testMatchRoutesUseUppercaseHttpVerbs(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        // No `->match(['get'…` / `['head'…` etc. — the verb must be UPPERCASE.
        $this->assertDoesNotMatchRegularExpression(
            "/->match\(\[\s*'[a-z]/",
            $src,
            'Pass UPPERCASE HTTP verbs to match() — CI4 deprecates lowercase.',
        );
    }
}
