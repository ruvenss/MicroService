<?php

declare(strict_types=1);

use App\Libraries\WebhookDispatcher;
use App\Models\AuditLogModel;
use App\Models\WebhookOutboxModel;
use Config\Database;
use Config\Webhooks;
use Tests\Support\FeatureTestCase;

/**
 * A resource's `hidden` fields (a secret, a token, PII) are stripped from responses —
 * but they must ALSO be stripped from the two places that persist full-row snapshots:
 * the audit trail (before/after JSON) and outbound webhook payloads. Otherwise anyone
 * with `audit:read` (or `*:read`), or the n8n webhook receiver, sees the secret the
 * `hidden` list was meant to protect. This exercises a resource that declares one.
 *
 * @internal
 */
final class HiddenFieldRedactionTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $forge = Database::forge();
        $forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'secret'     => ['type' => 'VARCHAR', 'constraint' => 100],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addPrimaryKey('id');
        $forge->createTable('vault', true);

        config('Resources')->resources['vault'] = [
            'table'      => 'vault',
            'primaryKey' => 'id',
            'fillable'   => ['name', 'secret'],
            'hidden'     => ['secret'],
            'rules'      => ['create' => ['name' => 'required', 'secret' => 'required'], 'update' => []],
            'sortable'   => ['name'],
            'filterable' => ['name'],
            'perPage'    => ['default' => 25, 'max' => 100],
            'timestamps' => true,
            'casts'      => ['id' => 'int'],
        ];

        // Turn webhooks on so the enqueued payload can be inspected.
        $config                            = new Webhooks();
        $config->subscriptions             = [['url' => 'https://n8n.example/hook', 'secret' => 's3cr3t', 'events' => ['*']]];
        WebhookDispatcher::$configOverride = $config;

        $this->auth = $this->authHeaders(['vault:*']);
    }

    protected function tearDown(): void
    {
        WebhookDispatcher::$configOverride = null;
        unset(config('Resources')->resources['vault']);
        Database::forge()->dropTable('vault', true);
        parent::tearDown();
    }

    private function create(): int
    {
        return (int) json_decode((string) $this->withHeaders($this->auth)->withBodyFormat('json')
            ->post('api/v1/vault', ['name' => 'n', 'secret' => 'TOP-SECRET-VALUE'])
            ->response()->getBody(), true)['data']['id'];
    }

    public function testResponseItselfHidesTheSecret(): void
    {
        $id   = $this->create();
        $body = (string) $this->withHeaders($this->auth)->get("api/v1/vault/{$id}")->response()->getBody();
        $this->assertStringNotContainsString('TOP-SECRET-VALUE', $body);
        $this->assertArrayNotHasKey('secret', json_decode($body, true)['data']);
    }

    public function testAuditTrailRedactsTheSecretOnCreateUpdateDelete(): void
    {
        $id = $this->create();
        $this->withHeaders($this->auth)->withBodyFormat('json')->patch("api/v1/vault/{$id}", ['secret' => 'NEW-SECRET']);
        $this->withHeaders($this->auth)->delete("api/v1/vault/{$id}");

        // Every audit row for this resource — across create/update/delete — must carry no
        // secret in either snapshot column.
        $rows = (new AuditLogModel())->where('resource', 'vault')->findAll();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $blob = ($row['before_json'] ?? '') . ($row['after_json'] ?? '');
            $this->assertStringNotContainsString('TOP-SECRET-VALUE', $blob, "audit {$row['action']} leaked the created secret");
            $this->assertStringNotContainsString('NEW-SECRET', $blob, "audit {$row['action']} leaked the updated secret");
            $this->assertStringNotContainsString('secret', $blob, "audit {$row['action']} kept the hidden field name");
        }
    }

    public function testWebhookPayloadRedactsTheSecret(): void
    {
        $this->create();

        $payload = (string) (new WebhookOutboxModel())->where('resource', 'vault')->first()['payload_json'];
        $this->assertStringNotContainsString('TOP-SECRET-VALUE', $payload);
        $this->assertStringNotContainsString('secret', $payload);
    }
}
