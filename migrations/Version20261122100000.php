<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The bear is the icon an account starts on, so the column defaults move
 * from 'logo' to 'bear' and every existing account is flipped to it. The
 * backfill is unconditional: a default alone only reaches accounts created
 * after it, and the choice was to flip everyone, not just new ones.
 *
 * down() restores the defaults only. The backfill cannot be undone — a row
 * flipped by this migration is indistinguishable from one whose owner chose
 * the bear on purpose, and restoring 'logo' would take the icon away from
 * both.
 */
final class Version20261122100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make the bear the default app icon and flip every existing account to it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                     WHERE table_schema = 'public' AND table_name = 'user'
                       AND column_name = 'appearance_app_icon'
                ) THEN
                    EXECUTE 'ALTER TABLE "user" ALTER COLUMN appearance_app_icon SET DEFAULT ''bear''';
                    EXECUTE 'ALTER TABLE "user" ALTER COLUMN appearance_web_icon SET DEFAULT ''bear''';
                    EXECUTE 'UPDATE "user" SET appearance_app_icon = ''bear'', appearance_web_icon = ''bear''';
                END IF;
            END $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                     WHERE table_schema = 'public' AND table_name = 'user'
                       AND column_name = 'appearance_app_icon'
                ) THEN
                    EXECUTE 'ALTER TABLE "user" ALTER COLUMN appearance_app_icon SET DEFAULT ''logo''';
                    EXECUTE 'ALTER TABLE "user" ALTER COLUMN appearance_web_icon SET DEFAULT ''logo''';
                END IF;
            END $$
            SQL);
    }
}
