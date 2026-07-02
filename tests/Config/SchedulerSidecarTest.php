<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards the scheduler sidecar wiring: without it, enqueued webhooks never leave the
 * outbox (the service→n8n push channel is dead) and the transient tables never prune.
 * These only run in the container stack, so scan the shipped files here.
 *
 * @internal
 */
final class SchedulerSidecarTest extends CIUnitTestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testSchedulerScriptDeliversWebhooksAndPrunes(): void
    {
        $script = (string) file_get_contents($this->root() . '/docker/scheduler.sh');

        $this->assertStringContainsString('php spark webhooks:dispatch', $script);
        $this->assertStringContainsString('php spark maintenance:prune', $script);
        // Dead-letter replay must NOT be automated (would hammer a still-down n8n) — the
        // command is never invoked (a comment may explain why).
        $this->assertStringNotContainsString('php spark webhooks:retry', $script);
    }

    public function testComposeRunsTheSchedulerAsASidecar(): void
    {
        $compose    = (string) file_get_contents($this->root() . '/docker-compose.yml');
        $dockerfile = (string) file_get_contents($this->root() . '/docker/Dockerfile');

        $this->assertStringContainsString('scheduler.sh', $dockerfile);      // baked into the image
        $this->assertMatchesRegularExpression('/^\s{2}scheduler:/m', $compose); // a compose service
        $this->assertStringContainsString('/usr/local/bin/scheduler.sh', $compose); // run as its command
    }
}
