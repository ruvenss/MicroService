<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sample resource table for the generic CRUD engine (see Config\Resources).
 */
final class CreateProducts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'sku'        => ['type' => 'VARCHAR', 'constraint' => 64],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'price'      => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'active'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('sku');
        $this->forge->addKey('status');
        $this->forge->createTable('products', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('products', true);
    }
}
