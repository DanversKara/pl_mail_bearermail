<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BearerMail: "go straight to the provider" for single sign-on.
 * See App\Entity\Oidc\OidcConfig::$straightToProvider.
 */
final class Version20261014050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add oidc_config.straight_to_provider (BearerMail: skip the login page)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oidc_config ADD straight_to_provider BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oidc_config DROP straight_to_provider');
    }
}
