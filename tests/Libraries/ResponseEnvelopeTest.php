<?php

declare(strict_types=1);

use App\Libraries\ResponseEnvelope;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ResponseEnvelopeTest extends CIUnitTestCase
{
    public function testWrapOmitsEmptyMeta(): void
    {
        $envelope = ResponseEnvelope::wrap(['id' => 1]);

        $this->assertSame(['data' => ['id' => 1]], $envelope);
        $this->assertArrayNotHasKey('meta', $envelope);
    }

    public function testWrapKeepsNonEmptyMeta(): void
    {
        $envelope = ResponseEnvelope::wrap(['id' => 1], ['etag' => 'abc']);

        $this->assertSame(['etag' => 'abc'], $envelope['meta']);
    }

    public function testCollectionComputesPagination(): void
    {
        $envelope = ResponseEnvelope::collection([1, 2], 2, 25, 51);

        $this->assertSame([1, 2], $envelope['data']);
        $this->assertSame(
            ['page' => 2, 'perPage' => 25, 'total' => 51, 'totalPages' => 3],
            $envelope['meta']['pagination'],
        );
    }
}
