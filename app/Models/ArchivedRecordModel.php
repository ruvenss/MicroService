<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * The recycle bin: full snapshots of deleted rows, restorable to their table.
 */
class ArchivedRecordModel extends Model
{
    protected $table         = 'archived_records';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'resource',
        'source_table',
        'record_id',
        'payload_json',
        'deleted_by',
        'request_id',
        'deleted_at',
        'restored_at',
    ];
}
