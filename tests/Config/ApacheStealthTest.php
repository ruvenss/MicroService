<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards the Apache-layer stealth posture that only exists in the container and so
 * cannot be exercised from PHPUnit: the neutral error body Apache serves itself
 * (403/405/413/… via ErrorDocument, bypassing PHP) must not leak server/build
 * fingerprints, and Apache must not emit its default ETag.
 *
 * @internal
 */
final class ApacheStealthTest extends CIUnitTestCase
{
    private string $vhost;
    private string $stealth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vhost   = (string) file_get_contents(dirname(__DIR__, 2) . '/docker/apache/microservice.conf');
        $this->stealth = (string) file_get_contents(dirname(__DIR__, 2) . '/docker/apache/stealth.conf');
    }

    public function testDefaultApacheETagIsDisabled(): void
    {
        // Apache's default `inode-size-mtime` ETag is a recognisable server fingerprint
        // (and historically leaked the inode — CVE-2003-1418).
        $this->assertMatchesRegularExpression('/^\s*FileETag\s+None\s*$/mi', $this->vhost);
    }

    public function testApacheServedErrorBodyStripsFingerprintHeaders(): void
    {
        // The <Files "error.json"> block is what Apache applies when it serves the
        // static problem+json for a server-level error. It must strip the static-file
        // headers that fingerprint Apache / disclose the build time.
        if (! preg_match('/<Files "error\.json">(.*?)<\/Files>/s', $this->vhost, $m)) {
            $this->fail('vhost is missing the <Files "error.json"> hardening block');
        }
        $block = $m[1];

        $this->assertMatchesRegularExpression('/Header\s+always\s+unset\s+Last-Modified/i', $block, 'must unset Last-Modified (build-time leak)');
        $this->assertMatchesRegularExpression('/Header\s+always\s+unset\s+Accept-Ranges/i', $block, 'must unset Accept-Ranges (static-file hint)');
    }

    public function testApacheServedErrorBodyMatchesAppStealthHeaders(): void
    {
        // App responses carry no-store + X-Robots-Tag (via the Stealth filter); the
        // Apache-served error must be indistinguishable, or it becomes a tell.
        if (! preg_match('/<Files "error\.json">(.*?)<\/Files>/s', $this->vhost, $m)) {
            $this->fail('vhost is missing the <Files "error.json"> hardening block');
        }
        $block = $m[1];

        $this->assertMatchesRegularExpression('/Header\s+always\s+set\s+Cache-Control\s+"no-store"/i', $block);
        $this->assertMatchesRegularExpression('/Header\s+always\s+set\s+X-Robots-Tag\s+"noindex/i', $block);
    }

    public function testApacheServedErrorCarriesEveryAppSecurityHeader(): void
    {
        // The Apache-served static error bypasses PHP entirely, so it must re-assert the
        // *full* security-header set the Stealth filter adds to every app response — not a
        // subset. Missing headers (nosniff, X-Frame-Options, CSP, …) would both weaken the
        // error response and make it distinguishable from an app response. Derive the
        // required set straight from Stealth::harden so a new header there can't silently
        // drift out of the Apache error path.
        if (! preg_match('/<Files "error\.json">(.*?)<\/Files>/s', $this->vhost, $m)) {
            $this->fail('vhost is missing the <Files "error.json"> hardening block');
        }
        $block = $m[1];

        $stealth = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Filters/Stealth.php');
        // Every `$response->setHeader('Name', 'Value')` with a literal value. The Server
        // header uses a class constant (no literal), so it is naturally excluded — Apache
        // sets that at the server layer via mod_security's SecServerSignature.
        preg_match_all('/setHeader\(\s*\'([^\']+)\'\s*,\s*([\'"])(.*?)\2\s*\)/', $stealth, $mm, PREG_SET_ORDER);

        $required = [];
        foreach ($mm as $pair) {
            $required[$pair[1]] = $pair[3];
        }
        $this->assertArrayHasKey('X-Content-Type-Options', $required, 'sanity: Stealth sets nosniff');
        $this->assertArrayHasKey('Content-Security-Policy', $required, 'sanity: Stealth sets a CSP');

        foreach ($required as $name => $value) {
            $this->assertStringContainsString(
                "set {$name} \"{$value}\"",
                $block,
                "the Apache error block must re-assert the app's {$name} header verbatim",
            );
        }
    }

    public function testEdgeBodyLimitIsEnforcedByModSecurityNotLimitRequestBody(): void
    {
        // Apache's core LimitRequestBody is NOT enforced on the mod_proxy_fcgi path that
        // serves the API (a huge POST would stream to FPM), so the edge DoS ceiling must
        // be enforced by mod_security, which runs before the FastCGI handler forwards the
        // body. For a JSON (non-upload) body the *NoFiles* limit is the one that applies,
        // so it must be set — and rejection must be hard (Reject), not ProcessPartial.
        $this->assertMatchesRegularExpression('/^\s*SecRequestBodyAccess\s+On\s*$/mi', $this->stealth, 'body inspection must be On to enforce a limit');
        $this->assertMatchesRegularExpression('/^\s*SecRequestBodyLimitAction\s+Reject\s*$/mi', $this->stealth, 'over-limit bodies must be rejected, not truncated');

        if (! preg_match('/SecRequestBodyNoFilesLimit\s+(\d+)/i', $this->stealth, $m)) {
            $this->fail('stealth.conf must set SecRequestBodyNoFilesLimit (the limit that applies to JSON bodies)');
        }
        $edge = (int) $m[1];

        // The edge ceiling must sit *above* the app's precise ContentGuard contract, so
        // ContentGuard stays the exact gate and the edge only catches gross abuse.
        $ref      = new \ReflectionClassConstant(\App\Filters\ContentGuard::class, 'MAX_BODY_BYTES');
        $contract = (int) $ref->getValue();
        $this->assertGreaterThan($contract, $edge, 'the mod_security edge limit must exceed the ContentGuard contract');

        // And LimitRequestBody, kept only as belt-and-suspenders for non-proxied paths,
        // must be aligned to the same ceiling (not left at a stale, larger value).
        if (! preg_match('/^\s*LimitRequestBody\s+(\d+)/mi', $this->vhost, $lm)) {
            $this->fail('vhost must still declare LimitRequestBody for non-proxied paths');
        }
        $this->assertSame($edge, (int) $lm[1], 'LimitRequestBody should match the mod_security edge ceiling');
    }
}
