<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BearerMail: Admin → Updates can install a newer build by itself, through
 * bin/auto-update on the server. See App\Entity\System\UpdateCheck.
 */
final class Version20261012190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add auto-install to update_check (BearerMail: install updates automatically)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE update_check ADD auto_install BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE update_check ADD installer_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE update_check ADD installed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE update_check ADD install_error TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE update_check DROP auto_install');
        $this->addSql('ALTER TABLE update_check DROP installer_seen_at');
        $this->addSql('ALTER TABLE update_check DROP installed_at');
        $this->addSql('ALTER TABLE update_check DROP install_error');
    }
}
