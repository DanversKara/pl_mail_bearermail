<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Appearance: which picture is the app icon — the chosen logo, or the
 * BearerMail artwork. Followed by the Android launcher icon.
 */
final class Version20261019010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add appearance_app_icon to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"user\" ADD appearance_app_icon VARCHAR(16) DEFAULT 'logo' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP appearance_app_icon');
    }
}
