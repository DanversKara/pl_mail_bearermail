<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BearerMail: "hide the password form" for single sign-on.
 * See App\Entity\Oidc\OidcConfig::$hidePasswordForm.
 */
final class Version20261014060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add oidc_config.hide_password_form (BearerMail: provider button only)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oidc_config ADD hide_password_form BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oidc_config DROP hide_password_form');
    }
}
