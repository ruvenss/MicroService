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

        return $this;
    }
}
