<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Append-only data-mutation audit trail.
 */
class AuditLogModel extends Model
{
    protected $table         = 'audit_log';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'request_id',
        'api_key_id',
        'action',
        'resource',
        'record_id',
        'before_json',
        'after_json',
        'changed_json',
        'ip',
        'created_at',
    ];
}
