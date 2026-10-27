<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BearerMail: retire the fork's own single sign-on switches.
 *
 * "Go straight to the provider" and "hide the password form" came in with
 * Version20261014050000 / Version20261014060000. Upstream plMail now has its
 * own "single sign-on only" (password_login_disabled), so the fork follows it
 * and the two columns go. Neither setting is carried over: "hide the form" was
 * softer than "refuse password sign-ins", and turning that on unasked could
 * shut out an administrator who has never signed in through the provider.
 */
final class Version20261026180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop oidc_config.straight_to_provider and hide_password_form (upstream SSO-only replaces them)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oidc_config DROP COLUMN IF EXISTS straight_to_provider');
        $this->addSql('ALTER TABLE oidc_config DROP COLUMN IF EXISTS hide_password_form');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oidc_config ADD straight_to_provider BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE oidc_config ADD hide_password_form BOOLEAN DEFAULT false NOT NULL');
    }
}
