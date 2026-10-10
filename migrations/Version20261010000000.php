<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mail server add-ons: security events, SMTP keys, DMARC reports, Drive.
 */
final class Version20261010000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mail server add-ons: security_event, smtp_key, dmarc_report, drive_file, drive_share';
    }

    public function up(Schema $schema): void
    {
        // Security events (login attempts, etc.)
        $this->addSql('CREATE TABLE security_event (id SERIAL NOT NULL, usr_id INT DEFAULT NULL, type VARCHAR(50) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, details TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_security_event_created ON security_event (created_at)');
        $this->addSql('CREATE INDEX idx_security_event_ip ON security_event (ip_address)');
        $this->addSql('ALTER TABLE security_event ADD CONSTRAINT FK_security_event_usr FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE SET NULL');

        // SMTP keys for scripts
        $this->addSql('CREATE TABLE smtp_key (id SERIAL NOT NULL, usr_id INT NOT NULL, account_id INT NOT NULL, name VARCHAR(100) NOT NULL, key_hash VARCHAR(64) NOT NULL, key_prefix VARCHAR(16) NOT NULL, hourly_limit INT NOT NULL, max_recipients INT NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_smtp_key_hash ON smtp_key (key_hash)');
        $this->addSql('CREATE INDEX idx_smtp_key_user ON smtp_key (usr_id)');
        $this->addSql('CREATE INDEX idx_smtp_key_account ON smtp_key (account_id)');
        $this->addSql('ALTER TABLE smtp_key ADD CONSTRAINT FK_smtp_key_usr FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE smtp_key ADD CONSTRAINT FK_smtp_key_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');

        // DMARC reports
        $this->addSql('CREATE TABLE dmarc_report (id SERIAL NOT NULL, domain VARCHAR(255) NOT NULL, reporter VARCHAR(255) NOT NULL, date_from TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, date_to TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, records JSON NOT NULL, message_count INT NOT NULL, fail_count INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_dmarc_report_domain ON dmarc_report (domain)');

        // Drive files
        $this->addSql('CREATE TABLE drive_file (id SERIAL NOT NULL, usr_id INT NOT NULL, filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size_bytes INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_drive_file_user ON drive_file (usr_id)');
        $this->addSql('ALTER TABLE drive_file ADD CONSTRAINT FK_drive_file_usr FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE');

        // Drive share links
        $this->addSql('CREATE TABLE drive_share (id SERIAL NOT NULL, file_id INT NOT NULL, token VARCHAR(64) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, download_count INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_drive_share_token ON drive_share (token)');
        $this->addSql('CREATE INDEX idx_drive_share_file ON drive_share (file_id)');
        $this->addSql('ALTER TABLE drive_share ADD CONSTRAINT FK_drive_share_file FOREIGN KEY (file_id) REFERENCES drive_file (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE drive_share');
        $this->addSql('DROP TABLE drive_file');
        $this->addSql('DROP TABLE dmarc_report');
        $this->addSql('DROP TABLE smtp_key');
        $this->addSql('DROP TABLE security_event');
    }
}
