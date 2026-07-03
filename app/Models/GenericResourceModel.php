<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\ResourceDefinition;
use CodeIgniter\Model;

/**
 * One model class for every resource. Configured at runtime from a
 * ResourceDefinition — no per-entity model code.
 */
class GenericResourceModel extends Model
{
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $allowCallbacks = false;

    /**
     * Configure this instance for a resource. Must be called before any query
     * (the query builder is built lazily, so the table/keys take effect).
     */
    public function forResource(ResourceDefinition $definition): static
    {
        $this->table         = $definition->table;
        $this->primaryKey    = $definition->primaryKey;
        $this->allowedFields = $definition->fillable;
        $this->useTimestamps = $definition->timestamps;

        // A primary key the client writes (it's fillable — a natural string key like a
        // code/slug/email) is NOT auto-increment. Without this, insert(returnID:true)
        // returns getInsertID() = 0, and the controller's find(0) then resolves the WRONG
        // row (MySQL coerces a non-numeric string PK to 0 in `WHERE pk = 0`) — so create
        // echoes an arbitrary record and audits/links it under id 0. With it off,
        // insert() returns the real key value from the data, so create resolves the row
        // it just wrote. Auto-increment int keys (not fillable, e.g. `id`) are unchanged.
        $this->useAutoIncrement = ! in_array($definition->primaryKey, $definition->fillable, true);

        return $this;
    }
}
