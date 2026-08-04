<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create synstitute_instance, appointment_type, available_slot tables';
    }

    public function up(Schema $schema): void
    {
        $instance = $schema->createTable('synstitute_instance');
        $instance->addColumn('id', 'integer', ['autoincrement' => true]);
        $instance->addColumn('identifier', 'string', ['length' => 64]);
        $instance->addColumn('api_key_hash', 'string', ['length' => 255]);
        $instance->addColumn('booking_target_url', 'string', ['length' => 255]);
        $instance->addColumn('is_active', 'boolean', ['default' => true]);
        $instance->addColumn('require_https', 'boolean', ['default' => true]);
        $instance->addColumn('created_at', 'datetime_immutable');
        $instance->addColumn('updated_at', 'datetime_immutable');
        $instance->setPrimaryKey(['id']);
        $instance->addUniqueIndex(['identifier'], 'uniq_instance_identifier');

        $appointmentType = $schema->createTable('appointment_type');
        $appointmentType->addColumn('id', 'integer', ['autoincrement' => true]);
        $appointmentType->addColumn('synstitute_instance_id', 'integer');
        $appointmentType->addColumn('name', 'string', ['length' => 120]);
        $appointmentType->addColumn('duration_minutes', 'integer');
        $appointmentType->addColumn('description', 'text', ['notnull' => false]);
        $appointmentType->setPrimaryKey(['id']);
        $appointmentType->addUniqueIndex(['synstitute_instance_id', 'name', 'duration_minutes'], 'uniq_appt_type_per_instance');
        $appointmentType->addForeignKeyConstraint('synstitute_instance', ['synstitute_instance_id'], ['id'], ['onDelete' => 'CASCADE']);

        $availableSlot = $schema->createTable('available_slot');
        $availableSlot->addColumn('id', 'integer', ['autoincrement' => true]);
        $availableSlot->addColumn('synstitute_instance_id', 'integer');
        $availableSlot->addColumn('appointment_type_id', 'integer');
        $availableSlot->addColumn('slot_uid', 'string', ['length' => 128]);
        $availableSlot->addColumn('slot_date', 'date_immutable');
        $availableSlot->addColumn('start_at', 'time_immutable');
        $availableSlot->addColumn('end_at', 'time_immutable');
        $availableSlot->addColumn('booked_payload', 'json', ['notnull' => false]);
        $availableSlot->addColumn('booked_at', 'datetime_immutable', ['notnull' => false]);
        $availableSlot->addColumn('exported_at', 'datetime_immutable', ['notnull' => false]);
        $availableSlot->addColumn('updated_at', 'datetime_immutable');
        $availableSlot->setPrimaryKey(['id']);
        $availableSlot->addUniqueIndex(['synstitute_instance_id', 'slot_uid'], 'uniq_slot_per_instance');
        $availableSlot->addIndex(['booked_at', 'exported_at'], 'idx_booked_exported');
        $availableSlot->addForeignKeyConstraint('synstitute_instance', ['synstitute_instance_id'], ['id'], ['onDelete' => 'CASCADE']);
        $availableSlot->addForeignKeyConstraint('appointment_type', ['appointment_type_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('available_slot');
        $schema->dropTable('appointment_type');
        $schema->dropTable('synstitute_instance');
    }
}
