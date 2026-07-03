<?php

declare(strict_types=1);

use App\Libraries\ProblemDetails;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ProblemDetailsTest extends CIUnitTestCase
{
    public function testMakeBasicOmitsDetail(): void
    {
        $problem = ProblemDetails::make(404);

        $this->assertSame('about:blank', $problem['type']);
        $this->assertSame('Not Found', $problem['title']);
        $this->assertSame(404, $problem['status']);
        $this->assertArrayNotHasKey('detail', $problem);
    }

    public function testMakeWithDetailAndExtraMembers(): void
    {
        $problem = ProblemDetails::make(422, 'Invalid', ['errors' => ['sku' => ['required']]]);

        $this->assertSame('Unprocessable Entity', $problem['title']);
        $this->assertSame('Invalid', $problem['detail']);
        $this->assertSame(['sku' => ['required']], $problem['errors']);
    }

    public function testUnknownStatusGetsGenericTitle(): void
    {
        $this->assertSame('Error', ProblemDetails::titleFor(418));
        $this->assertSame('Error', ProblemDetails::make(418)['title']);
    }
}
