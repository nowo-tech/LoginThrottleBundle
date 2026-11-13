<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users and login_attempts tables';
    }

    public function up(Schema $schema): void
    {
        // Create users table
        $this->addSql('CREATE TABLE users (
            id INT AUTO_INCREMENT NOT NULL,
            email VARCHAR(180) NOT NULL,
            password VARCHAR(255) NOT NULL,
            roles JSON NOT NULL,
            UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create login_attempts table. username and ip_address are identity columns: they need a binary collation
        // (REQ-SEC-006), and so does the table default, so a column added later does not silently fold.
        // MySQL >= 8.0.17: utf8mb4_0900_bin. MariaDB: utf8mb4_nopad_bin. MySQL 5.7 only: utf8mb4_bin, which is binary
        // but PAD SPACE (`bob ` still equals `bob`), so there the bundle's PHP re-check is what keeps them apart.
        $this->addSql('CREATE TABLE login_attempts (
            id INT AUTO_INCREMENT NOT NULL,
            ip_address VARCHAR(45) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
            username VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            blocked TINYINT(1) DEFAULT 0 NOT NULL,
            INDEX idx_ip_username (ip_address, username),
            INDEX idx_created_at (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_bin` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE login_attempts');
        $this->addSql('DROP TABLE users');
    }
}

