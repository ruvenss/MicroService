<?php

declare(strict_types=1);

use App\Libraries\ApiExceptionHandler;
use App\Libraries\RequestContext;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ApiExceptionHandlerTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::reset();
    }

    public function testProducesNeutralProblemJsonWithoutLeakingTheEngine(): void
    {
        $handler  = new ApiExceptionHandler(config('Exceptions'));
        $response = $handler->prepare(new RuntimeException('internal secret'), service('response'), 500);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('MicroService', $response->getHeaderLine('Server'));

        $body = (string) $response->getBody();
        $json = json_decode($body, true);
        $this->assertSame('Internal Server Error', $json['title']);
        $this->assertSame(500, $json['status']);
        $this->assertArrayHasKey('requestId', $json);

        // The exception class, file paths, and traces must never appear.
        $this->assertStringNotContainsStringIgnoringCase('RuntimeException', $body);
        $this->assertStringNotContainsString('.php', $body);
    }

    public function testClampsNonHttpStatusToInternalServerError(): void
    {
        $handler  = new ApiExceptionHandler(config('Exceptions'));
        $response = $handler->prepare(new RuntimeException('x'), service('response'), 0);

        $this->assertSame(500, $response->getStatusCode());
    }
}
