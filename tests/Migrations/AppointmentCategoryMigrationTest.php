<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260803000100;
use DoctrineMigrations\Version20261009193000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AppointmentCategoryMigrationTest extends TestCase
{
    public function testUpgradePreservesLegacyTypesAndBookedPayloads(): void
    {
        require_once __DIR__ . '/../../migrations/Version20260803000100.php';
        require_once __DIR__ . '/../../migrations/Version20261009193000.php';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $platform = $connection->getDatabasePlatform();
        $schema = new Schema();
        (new Version20260803000100($connection, new NullLogger()))->up($schema);
        foreach ($schema->toSql($platform) as $sql) {
            $connection->executeStatement($sql);
        }
        $connection->insert('synstitute_instance', [
            'id' => 1,
            'identifier' => 'migration-test',
            'api_key_hash' => 'test-hash',
            'booking_target_url' => 'https://booking.example.test',
            'is_active' => 1,
            'require_https' => 1,
            'created_at' => '2026-10-09 12:00:00',
            'updated_at' => '2026-10-09 12:00:00',
        ]);
        $connection->insert('appointment_type', [
            'id' => 1,
            'synstitute_instance_id' => 1,
            'name' => 'Legacy consultation',
            'duration_minutes' => 30,
        ]);
        $payload = '{"uniqid":"booked-slot","customer":{"firstName":"Test"}}';
        $connection->insert('available_slot', [
            'id' => 1,
            'synstitute_instance_id' => 1,
            'appointment_type_id' => 1,
            'slot_uid' => 'booked-slot',
            'slot_date' => '2026-10-10',
            'start_at' => '09:00:00',
            'end_at' => '09:30:00',
            'booked_payload' => $payload,
            'booked_at' => '2026-10-09 12:00:00',
            'updated_at' => '2026-10-09 12:00:00',
        ]);

        $manager = $connection->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        (new Version20261009193000($connection, new NullLogger()))->up($after);
        $diff = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($platform->getAlterSchemaSQL($diff) as $sql) {
            $connection->executeStatement($sql);
        }

        $type = $connection->fetchAssociative('SELECT * FROM appointment_type WHERE id = 1');
        self::assertSame('Legacy consultation', $type['name']);
        self::assertNull($type['source_id']);
        self::assertNull($type['for_booking']);
        self::assertSame($payload, $connection->fetchOne('SELECT booked_payload FROM available_slot WHERE id = 1'));
        foreach ([41, 42] as $id) {
            $connection->insert('appointment_type', [
                'synstitute_instance_id' => 1,
                'source_id' => $id,
                'name' => 'Same label',
                'duration_minutes' => 30,
                'for_booking' => 'Booking instructions',
            ]);
        }
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM appointment_type'));
        $connection->close();
    }
}
