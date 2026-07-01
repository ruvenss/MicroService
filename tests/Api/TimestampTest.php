<?php

declare(strict_types=1);

use App\Libraries\Timestamp;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The shared UTC → ISO-8601 formatter used by the resource cast and the meta
 * endpoints, so every timestamp on the wire is unambiguous for n8n.
 *
 * @internal
 */
final class TimestampTest extends CIUnitTestCase
{
    public function testFormatsStoredDatetimeAsIso8601Utc(): void
    {
        $this->assertSame('2026-07-01T10:19:30Z', Timestamp::iso('2026-07-01 10:19:30'));
    }

    public function testNullAndEmptyPassThrough(): void
    {
        $this->assertNull(Timestamp::iso(null));
        $this->assertSame('', Timestamp::iso(''));
    }

    public function testUnparseableValuePassesThrough(): void
    {
        $this->assertSame('not-a-date', Timestamp::iso('not-a-date'));
    }

    public function testAlreadyIsoInputStaysUtc(): void
    {
        // An input that carries an offset is normalised to Z (same instant).
        $this->assertSame('2026-07-01T10:19:30Z', Timestamp::iso('2026-07-01T10:19:30+00:00'));
    }

    public function testNowMatchesTheSameZSuffixedShape(): void
    {
        // Server-generated envelope timestamps (health `time`, webhook `timestamp`)
        // must be byte-for-byte the same format as stored ones — Z, no offset — so a
        // consumer parses every timestamp on the wire with one rule.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', Timestamp::now());
    }
}
