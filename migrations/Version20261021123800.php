<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contacts: the address book, and the address books mirrored into it — CardDAV
 * through a connection, Google and Outlook through a mail account.
 * See App\Entity\Contacts\Contact and App\Entity\AddressBook\AddressBook.
 *
 * New, empty tables; nothing existing is touched. The harvested `contact`
 * table keeps feeding autocomplete as before, and the address book is read
 * beside it (ContactSuggester), not copied into it.
 */
final class Version20261021123800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contacts: an address book, and CardDAV address books mirrored into it';
    }

    /**
     * BearerMail: the fork had its own address book (Version20261016202052)
     * before this one arrived from plMail, in the same six tables with fewer
     * columns. Where those exist they are moved aside, this migration builds
     * plMail's tables as it always does, and the contacts are copied across
     * with their ids — so links to them (notes, the apps) still point at the
     * same people — then the old tables go. Every contact becomes a local one
     * (no address book), which is what they were.
     */
    private const array FORK_TABLES = [
        'contacts_contact', 'contacts_contact_email', 'contacts_contact_phone',
        'contacts_contact_address', 'contacts_contact_relation', 'contacts_contact_merge_record',
    ];

    private bool $forkTables = false;

    public function preUp(Schema $schema): void
    {
        $this->forkTables = null !== $this->connection->fetchOne("SELECT to_regclass('public.contacts_contact')");
    }

    private function moveForkTablesAside(): void
    {
        foreach (self::FORK_TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %1$s RENAME TO %1$s_bm_old', $table));
        }

        // Index, primary-key and identity-sequence names live in the schema,
        // not the table, so they would collide with plMail's new ones.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE r record;
            BEGIN
                FOR r IN
                    SELECT i.relname AS name FROM pg_index x
                    JOIN pg_class i ON i.oid = x.indexrelid
                    JOIN pg_class t ON t.oid = x.indrelid
                    WHERE t.relname LIKE 'contacts\_contact%\_bm\_old' AND t.relkind = 'r'
                LOOP
                    EXECUTE format('ALTER INDEX %I RENAME TO %I', r.name, left(r.name, 50) || '_bm_old');
                END LOOP;
                FOR r IN
                    SELECT s.relname AS name FROM pg_class s
                    JOIN pg_depend d ON d.objid = s.oid AND d.deptype IN ('a', 'i')
                    JOIN pg_class t ON t.oid = d.refobjid
                    WHERE s.relkind = 'S' AND t.relname LIKE 'contacts\_contact%\_bm\_old'
                LOOP
                    EXECUTE format('ALTER SEQUENCE %I RENAME TO %I', r.name, left(r.name, 50) || '_bm_old');
                END LOOP;
            END $$
            SQL);
    }

    private function copyForkContacts(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO contacts_contact (id, uid, display_name, given_name, family_name, nickname, company, job_title,
                notes, tags, photo_path, source, trashed_at, created_at, updated_at, usr_id, address_book_id)
            SELECT id, gen_random_uuid()::text, display_name, given_name, family_name, nickname, company, job_title,
                notes, tags, photo_path, source, trashed_at, created_at, updated_at, usr_id, NULL
            FROM contacts_contact_bm_old
            SQL);
        $this->addSql('INSERT INTO contacts_contact_email (id, email, label, is_primary, created_at, updated_at, contact_id) SELECT id, email, label, is_primary, created_at, updated_at, contact_id FROM contacts_contact_email_bm_old');
        $this->addSql('INSERT INTO contacts_contact_phone (id, phone, label, is_primary, created_at, updated_at, contact_id) SELECT id, phone, label, is_primary, created_at, updated_at, contact_id FROM contacts_contact_phone_bm_old');
        $this->addSql('INSERT INTO contacts_contact_address (id, label, street, city, region, postcode, country, created_at, updated_at, contact_id) SELECT id, label, street, city, region, postcode, country, created_at, updated_at, contact_id FROM contacts_contact_address_bm_old');
        $this->addSql('INSERT INTO contacts_contact_relation (id, rel_type, created_at, updated_at, owner_contact_id, related_contact_id) SELECT id, rel_type, created_at, updated_at, owner_contact_id, related_contact_id FROM contacts_contact_relation_bm_old');
        $this->addSql('INSERT INTO contacts_contact_merge_record (id, merged_ids, snapshot, merged_at, undo_until, created_at, updated_at, usr_id, kept_contact_id) SELECT id, merged_ids, snapshot, merged_at, undo_until, created_at, updated_at, usr_id, kept_contact_id FROM contacts_contact_merge_record_bm_old');

        // The identity columns carry on after the copied ids.
        foreach (self::FORK_TABLES as $table) {
            $this->addSql(sprintf(
                "SELECT setval(pg_get_serial_sequence('%1\$s', 'id'), COALESCE((SELECT MAX(id) FROM %1\$s), 0) + 1, false)",
                $table,
            ));
        }

        foreach (array_reverse(self::FORK_TABLES) as $table) {
            $this->addSql(sprintf('DROP TABLE %s_bm_old CASCADE', $table));
        }
    }

    public function up(Schema $schema): void
    {
        if ($this->forkTables) {
            $this->moveForkTablesAside();
        }

        $this->addSql('CREATE TABLE address_book (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, remote_id VARCHAR(1024) NOT NULL, name VARCHAR(120) NOT NULL, is_read_only BOOLEAN DEFAULT false NOT NULL, sync_token TEXT DEFAULT NULL, last_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_sync_error TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, usr_id INT NOT NULL, integration_id INT DEFAULT NULL, account_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_address_book_usr ON address_book (usr_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_address_book_integration_remote ON address_book (integration_id, remote_id)');
        $this->addSql('CREATE INDEX IDX_B6A973DA9E82DDEA ON address_book (integration_id)');
        $this->addSql('CREATE INDEX idx_address_book_account ON address_book (account_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_address_book_account_remote ON address_book (account_id, remote_id)');
        $this->addSql('CREATE TABLE contacts_contact (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, uid VARCHAR(255) NOT NULL, href VARCHAR(1024) DEFAULT NULL, etag VARCHAR(255) DEFAULT NULL, vcard TEXT DEFAULT NULL, remote_resource TEXT DEFAULT NULL, sync_state VARCHAR(20) DEFAULT \'clean\' NOT NULL, display_name VARCHAR(255) NOT NULL, given_name VARCHAR(255) DEFAULT NULL, family_name VARCHAR(255) DEFAULT NULL, name_prefix VARCHAR(255) DEFAULT NULL, middle_name VARCHAR(255) DEFAULT NULL, name_suffix VARCHAR(255) DEFAULT NULL, phonetic_given_name VARCHAR(255) DEFAULT NULL, phonetic_middle_name VARCHAR(255) DEFAULT NULL, phonetic_family_name VARCHAR(255) DEFAULT NULL, nickname VARCHAR(255) DEFAULT NULL, company VARCHAR(255) DEFAULT NULL, phonetic_company VARCHAR(255) DEFAULT NULL, department VARCHAR(255) DEFAULT NULL, job_title VARCHAR(255) DEFAULT NULL, office_location VARCHAR(255) DEFAULT NULL, birthday VARCHAR(10) DEFAULT NULL, dates JSON DEFAULT \'[]\' NOT NULL, relations JSON DEFAULT \'[]\' NOT NULL, urls JSON DEFAULT \'[]\' NOT NULL, ims JSON DEFAULT \'[]\' NOT NULL, custom_fields JSON DEFAULT \'[]\' NOT NULL, notes TEXT DEFAULT NULL, tags JSON NOT NULL, photo_path VARCHAR(255) DEFAULT NULL, photo_dirty BOOLEAN DEFAULT false NOT NULL, photo_version VARCHAR(2048) DEFAULT NULL, source VARCHAR(32) NOT NULL, trashed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, usr_id INT NOT NULL, address_book_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_contacts_contact_owner_updated ON contacts_contact (usr_id, updated_at)');
        $this->addSql('CREATE INDEX idx_contacts_contact_book_state ON contacts_contact (address_book_id, sync_state)');
        $this->addSql('CREATE UNIQUE INDEX uniq_contacts_contact_book_uid ON contacts_contact (address_book_id, uid)');
        $this->addSql('CREATE INDEX IDX_C6CAEA65C69D3FB ON contacts_contact (usr_id)');
        $this->addSql('CREATE INDEX IDX_C6CAEA654D474419 ON contacts_contact (address_book_id)');
        $this->addSql('CREATE TABLE contacts_contact_address (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, label VARCHAR(32) NOT NULL, street VARCHAR(255) DEFAULT NULL, city VARCHAR(255) DEFAULT NULL, region VARCHAR(255) DEFAULT NULL, postcode VARCHAR(255) DEFAULT NULL, country VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, contact_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_90FBF9D3E7A1254A ON contacts_contact_address (contact_id)');
        $this->addSql('CREATE TABLE contacts_contact_email (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, email VARCHAR(320) NOT NULL, label VARCHAR(32) NOT NULL, is_primary BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, contact_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_contacts_email_contact_email ON contacts_contact_email (contact_id, email)');
        $this->addSql('CREATE INDEX IDX_DB2535EFE7A1254A ON contacts_contact_email (contact_id)');
        $this->addSql('CREATE TABLE contacts_contact_merge_record (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, merged_ids JSON NOT NULL, snapshot JSON NOT NULL, merged_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, undo_until TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, usr_id INT NOT NULL, kept_contact_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_contacts_merge_owner_undo ON contacts_contact_merge_record (usr_id, undo_until)');
        $this->addSql('CREATE INDEX IDX_9CF7CBF7C69D3FB ON contacts_contact_merge_record (usr_id)');
        $this->addSql('CREATE INDEX IDX_9CF7CBF731B2A36F ON contacts_contact_merge_record (kept_contact_id)');
        $this->addSql('CREATE TABLE contacts_contact_phone (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, phone VARCHAR(64) NOT NULL, label VARCHAR(32) NOT NULL, is_primary BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, contact_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_contacts_phone_contact_phone ON contacts_contact_phone (contact_id, phone)');
        $this->addSql('CREATE INDEX IDX_78F8DE46E7A1254A ON contacts_contact_phone (contact_id)');
        $this->addSql('CREATE TABLE contacts_contact_relation (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, rel_type VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_contact_id INT NOT NULL, related_contact_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_contacts_relation_triple ON contacts_contact_relation (owner_contact_id, related_contact_id, rel_type)');
        $this->addSql('CREATE INDEX IDX_E771C207B5CBBC0F ON contacts_contact_relation (owner_contact_id)');
        $this->addSql('CREATE INDEX IDX_E771C2076D6C2DFA ON contacts_contact_relation (related_contact_id)');
        $this->addSql('ALTER TABLE address_book ADD CONSTRAINT FK_B6A973DAC69D3FB FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE address_book ADD CONSTRAINT FK_B6A973DA9E82DDEA FOREIGN KEY (integration_id) REFERENCES integration (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE address_book ADD CONSTRAINT FK_B6A973DA9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contacts_contact ADD CONSTRAINT FK_C6CAEA65C69D3FB FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contacts_contact ADD CONSTRAINT FK_C6CAEA654D474419 FOREIGN KEY (address_book_id) REFERENCES address_book (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contacts_contact_address ADD CONSTRAINT FK_90FBF9D3E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts_contact (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contacts_contact_email ADD CONSTRAINT FK_DB2535EFE7A1254A FOREIGN KEY (contact_id) REFERENCES contacts_contact (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contacts_contact_merge_record ADD CONSTRAINT FK_9CF7CBF7C69D3FB FOREIGN KEY (usr_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contacts_contact_merge_record ADD CONSTRAINT FK_9CF7CBF731B2A36F FOREIGN KEY (kept_contact_id) REFERENCES contacts_contact (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contacts_contact_phone ADD CONSTRAINT FK_78F8DE46E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts_contact (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contacts_contact_relation ADD CONSTRAINT FK_E771C207B5CBBC0F FOREIGN KEY (owner_contact_id) REFERENCES contacts_contact (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contacts_contact_relation ADD CONSTRAINT FK_E771C2076D6C2DFA FOREIGN KEY (related_contact_id) REFERENCES contacts_contact (id) ON DELETE CASCADE NOT DEFERRABLE');

        if ($this->forkTables) {
            $this->copyForkContacts();
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address_book DROP CONSTRAINT FK_B6A973DAC69D3FB');
        $this->addSql('ALTER TABLE address_book DROP CONSTRAINT FK_B6A973DA9E82DDEA');
        $this->addSql('ALTER TABLE address_book DROP CONSTRAINT FK_B6A973DA9B6B5FBA');
        $this->addSql('ALTER TABLE contacts_contact DROP CONSTRAINT FK_C6CAEA65C69D3FB');
        $this->addSql('ALTER TABLE contacts_contact DROP CONSTRAINT FK_C6CAEA654D474419');
        $this->addSql('ALTER TABLE contacts_contact_address DROP CONSTRAINT FK_90FBF9D3E7A1254A');
        $this->addSql('ALTER TABLE contacts_contact_email DROP CONSTRAINT FK_DB2535EFE7A1254A');
        $this->addSql('ALTER TABLE contacts_contact_merge_record DROP CONSTRAINT FK_9CF7CBF7C69D3FB');
        $this->addSql('ALTER TABLE contacts_contact_merge_record DROP CONSTRAINT FK_9CF7CBF731B2A36F');
        $this->addSql('ALTER TABLE contacts_contact_phone DROP CONSTRAINT FK_78F8DE46E7A1254A');
        $this->addSql('ALTER TABLE contacts_contact_relation DROP CONSTRAINT FK_E771C207B5CBBC0F');
        $this->addSql('ALTER TABLE contacts_contact_relation DROP CONSTRAINT FK_E771C2076D6C2DFA');
        $this->addSql('DROP TABLE address_book');
        $this->addSql('DROP TABLE contacts_contact');
        $this->addSql('DROP TABLE contacts_contact_address');
        $this->addSql('DROP TABLE contacts_contact_email');
        $this->addSql('DROP TABLE contacts_contact_merge_record');
        $this->addSql('DROP TABLE contacts_contact_phone');
        $this->addSql('DROP TABLE contacts_contact_relation');
    }
}
