<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Append-only access/usage log.
 */
class ApiRequestLogModel extends Model
{
    protected $table         = 'api_request_log';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'api_key_id',
        'request_id',
        'method',
        'path',
        'resource',
        'action',
        'status',
        'latency_ms',
        'ip',
        'created_at',
    ];
}
