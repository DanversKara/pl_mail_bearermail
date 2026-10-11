<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BearerMail: reports filed against Drive share links by the people who were
 * sent them. See App\Entity\Drive\DriveShareReport.
 */
final class Version20261011180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add drive_share_report (BearerMail: reported Drive links)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE drive_share_report (
            id SERIAL NOT NULL,
            share_id INT DEFAULT NULL,
            file_id INT DEFAULT NULL,
            file_name VARCHAR(255) NOT NULL,
            owner_id INT DEFAULT NULL,
            reason VARCHAR(32) NOT NULL,
            details TEXT DEFAULT NULL,
            reporter_email VARCHAR(255) DEFAULT NULL,
            reporter_ip VARCHAR(45) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            resolution VARCHAR(32) DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_drive_share_report_open ON drive_share_report (resolved_at)');
        $this->addSql('CREATE INDEX IDX_DRIVE_SHARE_REPORT_SHARE ON drive_share_report (share_id)');
        $this->addSql('CREATE INDEX IDX_DRIVE_SHARE_REPORT_FILE ON drive_share_report (file_id)');
        $this->addSql('ALTER TABLE drive_share_report ADD CONSTRAINT FK_DRIVE_SHARE_REPORT_SHARE FOREIGN KEY (share_id) REFERENCES drive_share (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE drive_share_report ADD CONSTRAINT FK_DRIVE_SHARE_REPORT_FILE FOREIGN KEY (file_id) REFERENCES drive_file (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE drive_share_report');
    }
}
