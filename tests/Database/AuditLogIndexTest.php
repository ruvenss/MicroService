<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The n8n per-resource incremental sync (`_audit?resource=X&sinceId=N`) runs
 * `WHERE resource = ? AND id > ? ORDER BY id`. That needs an index whose columns
 * are (resource, id) in that order, or MySQL filesorts. Guard it here.
 *
 * @internal
 */
final class AuditLogIndexTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = true;

    public function testResourceThenIdCompositeIndexExists(): void
    {
        $hasResourceId = false;
        foreach (db_connect()->getIndexData('audit_log') as $index) {
            $fields = array_values($index->fields);
            if (($fields[0] ?? null) === 'resource' && ($fields[1] ?? null) === 'id') {
                $hasResourceId = true;
                break;
            }
        }

        $this->assertTrue($hasResourceId, 'audit_log needs a (resource, id) index for the per-resource change feed');
    }
}
