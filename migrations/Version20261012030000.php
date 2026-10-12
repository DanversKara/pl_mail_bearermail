<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BearerMail: addresses that failed to sign in to the mail server, and which
 * of them are blocked or always allowed. See App\Entity\System\MailLoginAddress.
 */
final class Version20261012030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mail_login_address (BearerMail: block repeated failed mail logins)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mail_login_address (
            id SERIAL NOT NULL,
            ip VARCHAR(45) NOT NULL,
            failures INT DEFAULT 0 NOT NULL,
            window_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            last_failure_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            last_login VARCHAR(255) DEFAULT NULL,
            blocked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            blocked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            times_blocked INT DEFAULT 0 NOT NULL,
            trusted BOOLEAN DEFAULT false NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_mail_login_address_ip ON mail_login_address (ip)');
        $this->addSql('CREATE INDEX idx_mail_login_address_blocked ON mail_login_address (blocked_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mail_login_address');
    }
}
