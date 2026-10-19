<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Appearance: the web portal's own icon (tab and top bar) — the logo, or the
 * BearerMail envelope. The Android launcher follows the logo, not this.
 */
final class Version20261019020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add appearance_web_icon to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"user\" ADD appearance_web_icon VARCHAR(16) DEFAULT 'logo' NOT NULL");
        // Whoever already chose BearerMail chose its family: envelope on the web.
        $this->addSql("UPDATE \"user\" SET appearance_web_icon = 'envelope' WHERE appearance_app_icon = 'bearermail'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP appearance_web_icon');
    }
}
