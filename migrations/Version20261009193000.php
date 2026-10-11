<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mirror source appointment category IDs and booking information';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('appointment_type');
        $table->addColumn('source_id', 'integer', ['notnull' => false]);
        $table->addColumn('for_booking', 'text', ['notnull' => false]);
        $table->modifyColumn('name', ['length' => 255]);
        $table->dropIndex('uniq_appt_type_per_instance');
        $table->addUniqueIndex(['synstitute_instance_id', 'source_id'], 'uniq_appt_category_per_instance');
        $table->addIndex(['synstitute_instance_id', 'name', 'duration_minutes'], 'idx_appt_type_name_duration');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('appointment_type');
        $table->dropIndex('uniq_appt_category_per_instance');
        $table->dropIndex('idx_appt_type_name_duration');
        $table->dropColumn('source_id');
        $table->dropColumn('for_booking');
        $table->modifyColumn('name', ['length' => 120]);
        $table->addUniqueIndex(['synstitute_instance_id', 'name', 'duration_minutes'], 'uniq_appt_type_per_instance');
    }
}
