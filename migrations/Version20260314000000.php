<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260314000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create kyc_events table (event store)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE kyc_events (
                event_id       VARCHAR(36)  NOT NULL,
                aggregate_id   VARCHAR(36)  NOT NULL,
                aggregate_type VARCHAR(100) NOT NULL,
                event_type     VARCHAR(100) NOT NULL,
                payload        TEXT         NOT NULL,
                occurred_at    VARCHAR(32)  NOT NULL,
                version        INTEGER      NOT NULL,
                PRIMARY KEY (event_id),
                UNIQUE (aggregate_id, version)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE kyc_events');
    }
}
