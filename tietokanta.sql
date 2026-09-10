-- Raporttityökalu / MariaDB
-- Tietokanta: tekraportti

CREATE DATABASE IF NOT EXISTS `tekraportti`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `tekraportti`;

CREATE TABLE `laatijat` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nimi` VARCHAR(200) NOT NULL,
    `titteli` VARCHAR(200) NULL,
    `aktiivinen` TINYINT(1) NOT NULL DEFAULT 1,
    `sahkoposti` VARCHAR(254) NOT NULL,
    `puhelin` VARCHAR(50) NOT NULL,
    `yritys` VARCHAR(200) NULL,
    `ytunnus` VARCHAR(20) NULL,
    `osoite` VARCHAR(255) NULL,
    `postinro` VARCHAR(20) NULL,
    `postitmp` VARCHAR(100) NULL,
    `logo_url` VARCHAR(512) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_laatijat_sahkoposti` (`sahkoposti`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `asiakkaat` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `nimi` VARCHAR(200) NOT NULL,
    `yritys` VARCHAR(200) NULL,
    `ytunnus` VARCHAR(20) NULL,
    `sahkoposti` VARCHAR(254) NULL,
    `puhelin` VARCHAR(50) NULL,
    `osoite` VARCHAR(255) NULL,
    `postinro` VARCHAR(20) NULL,
    `postitmp` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_asiakkaat_id_laatijat_id` (`id`, `laatijat_id`),
    KEY `idx_asiakkaat_laatijat_id` (`laatijat_id`),
    CONSTRAINT `fk_asiakkaat_laatijat`
        FOREIGN KEY (`laatijat_id`) REFERENCES `laatijat` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `asiakasjaot` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `asiakas_id` INT UNSIGNED NOT NULL,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_asiakasjaot_asiakas_laatija` (`asiakas_id`, `laatijat_id`),
    KEY `idx_asiakasjaot_laatijat_id` (`laatijat_id`),
    CONSTRAINT `fk_asiakasjaot_asiakas`
        FOREIGN KEY (`asiakas_id`) REFERENCES `asiakkaat` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_asiakasjaot_laatija`
        FOREIGN KEY (`laatijat_id`) REFERENCES `laatijat` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `asiakas_lisatiedot` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `asiakas_id` INT UNSIGNED NOT NULL,
    `nimi` VARCHAR(200) NOT NULL,
    `arvo` VARCHAR(1000) NOT NULL,
    `jarjestys` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_asiakas_lisatiedot_asiakas_jarjestys` (`asiakas_id`, `jarjestys`, `id`),
    UNIQUE KEY `uq_asiakas_lisatiedot_id_laatija` (`id`, `laatijat_id`),
    CONSTRAINT `fk_asiakas_lisatiedot_asiakas`
        FOREIGN KEY (`asiakas_id`, `laatijat_id`) REFERENCES `asiakkaat` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `projektit` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `asiakas_id` INT UNSIGNED NULL,
    `nimi` VARCHAR(200) NOT NULL,
    `kuvaus` TEXT NULL,
    `alkupvm` DATE NULL,
    `loppupvm` DATE NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_projektit_laatijat_id` (`laatijat_id`),
    KEY `idx_projektit_asiakas_id` (`asiakas_id`),
    CONSTRAINT `fk_projektit_laatijat`
        FOREIGN KEY (`laatijat_id`) REFERENCES `laatijat` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
    ,CONSTRAINT `fk_projektit_asiakas`
        FOREIGN KEY (`asiakas_id`) REFERENCES `asiakkaat` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `projektijaot` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `projekti_id` INT UNSIGNED NOT NULL,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_projektijaot_projekti_laatija` (`projekti_id`, `laatijat_id`),
    KEY `idx_projektijaot_laatijat_id` (`laatijat_id`),
    CONSTRAINT `fk_projektijaot_projekti`
        FOREIGN KEY (`projekti_id`) REFERENCES `projektit` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_projektijaot_laatija`
        FOREIGN KEY (`laatijat_id`) REFERENCES `laatijat` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raportit` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `asiakas_id` INT UNSIGNED NOT NULL,
    `projekti_id` INT UNSIGNED NULL,
    `otsikko` VARCHAR(255) NOT NULL,
    `raporttipvm` DATE NOT NULL,
    `laatimispaikka` VARCHAR(200) NULL,
    `tiivistelma` TEXT NULL,
    `kansikuva` VARCHAR(512) NULL,
    `tila` ENUM('luonnos', 'valmis', 'arkistoitu') NOT NULL DEFAULT 'luonnos',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_raportit_laatijat_id` (`laatijat_id`),
    KEY `idx_raportit_asiakas_id` (`asiakas_id`),
    KEY `idx_raportit_projekti_id` (`projekti_id`),
    KEY `idx_raportit_raporttipvm` (`raporttipvm`),
    UNIQUE KEY `uq_raportit_id_laatijat_id` (`id`, `laatijat_id`),
    CONSTRAINT `fk_raportit_laatijat`
        FOREIGN KEY (`laatijat_id`) REFERENCES `laatijat` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_raportit_asiakas`
        FOREIGN KEY (`asiakas_id`) REFERENCES `asiakkaat` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_raportit_projekti`
        FOREIGN KEY (`projekti_id`) REFERENCES `projektit` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raporttiliitteet` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `raportti_id` INT UNSIGNED NOT NULL,
    `tiedosto` VARCHAR(512) NOT NULL,
    `alkuperainen_nimi` VARCHAR(255) NOT NULL,
    `tiedostokoko` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `jarjestys` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_raporttiliitteet_raportti` (`raportti_id`, `jarjestys`, `id`),
    CONSTRAINT `fk_raporttiliitteet_raportti`
        FOREIGN KEY (`raportti_id`, `laatijat_id`) REFERENCES `raportit` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raporttijaot` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `raportti_id` INT UNSIGNED NOT NULL,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_raporttijaot_raportti_laatija` (`raportti_id`, `laatijat_id`),
    KEY `idx_raporttijaot_laatijat_id` (`laatijat_id`),
    CONSTRAINT `fk_raporttijaot_raportti`
        FOREIGN KEY (`raportti_id`) REFERENCES `raportit` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_raporttijaot_laatija`
        FOREIGN KEY (`laatijat_id`) REFERENCES `laatijat` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raporttirivit` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `raportti_id` INT UNSIGNED NOT NULL,
    `otsikko` VARCHAR(255) NOT NULL,
    `sisalto` LONGTEXT NULL,
    `sisaltaa_asiakastiedot` TINYINT(1) NOT NULL DEFAULT 0,
    `jarjestys` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_raporttirivit_raportti_jarjestys` (`raportti_id`, `jarjestys`),
    UNIQUE KEY `uq_raporttirivit_id_laatijat_id` (`id`, `laatijat_id`),
    CONSTRAINT `fk_raporttirivit_raportti`
        FOREIGN KEY (`raportti_id`, `laatijat_id`) REFERENCES `raportit` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raporttitaulukot` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `raportti_id` INT UNSIGNED NOT NULL,
    `raporttirivi_id` INT UNSIGNED NOT NULL,
    `otsikko` VARCHAR(255) NULL,
    `reunat` TINYINT(1) NOT NULL DEFAULT 1,
    `leveys_tila` ENUM('keskitetty', 'kokosivu') NOT NULL DEFAULT 'keskitetty',
    `jarjestys` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_raporttitaulukot_raporttirivi_jarjestys` (`raporttirivi_id`, `jarjestys`),
    UNIQUE KEY `uq_raporttitaulukot_id_laatijat_id` (`id`, `laatijat_id`),
    CONSTRAINT `fk_raporttitaulukot_raportti`
        FOREIGN KEY (`raportti_id`, `laatijat_id`) REFERENCES `raportit` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_raporttitaulukot_raporttirivi`
        FOREIGN KEY (`raporttirivi_id`, `laatijat_id`) REFERENCES `raporttirivit` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raporttitaulukko_sarakkeet` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `taulukko_id` INT UNSIGNED NOT NULL,
    `nimi` VARCHAR(255) NOT NULL,
    `taustavari` CHAR(7) NOT NULL DEFAULT '#ffffff',
    `fonttivari` CHAR(7) NOT NULL DEFAULT '#232d3c',
    `leveys` DECIMAL(10,2) NULL,
    `jarjestys` INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_raporttitaulukko_sarakkeet_taulukko_jarjestys` (`taulukko_id`, `jarjestys`),
    UNIQUE KEY `uq_raporttitaulukko_sarakkeet_id_laatijat_id` (`id`, `laatijat_id`),
    CONSTRAINT `fk_raporttitaulukko_sarakkeet_taulukko`
        FOREIGN KEY (`taulukko_id`, `laatijat_id`) REFERENCES `raporttitaulukot` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raporttitaulukko_rivit` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `taulukko_id` INT UNSIGNED NOT NULL,
    `jarjestys` INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_raporttitaulukko_rivit_taulukko_jarjestys` (`taulukko_id`, `jarjestys`),
    UNIQUE KEY `uq_raporttitaulukko_rivit_id_laatijat_id` (`id`, `laatijat_id`),
    CONSTRAINT `fk_raporttitaulukko_rivit_taulukko`
        FOREIGN KEY (`taulukko_id`, `laatijat_id`) REFERENCES `raporttitaulukot` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raporttitaulukko_solut` (
    `laatijat_id` INT UNSIGNED NOT NULL,
    `taulukko_id` INT UNSIGNED NOT NULL,
    `rivi_id` INT UNSIGNED NOT NULL,
    `sarake_id` INT UNSIGNED NOT NULL,
    `sisalto` TEXT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`taulukko_id`, `rivi_id`, `sarake_id`, `laatijat_id`),
    CONSTRAINT `fk_raporttitaulukko_solut_taulukko`
        FOREIGN KEY (`taulukko_id`, `laatijat_id`) REFERENCES `raporttitaulukot` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_raporttitaulukko_solut_rivi`
        FOREIGN KEY (`rivi_id`, `laatijat_id`) REFERENCES `raporttitaulukko_rivit` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_raporttitaulukko_solut_sarake`
        FOREIGN KEY (`sarake_id`, `laatijat_id`) REFERENCES `raporttitaulukko_sarakkeet` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raporttitaulukko_yhteenvetorivit` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `taulukko_id` INT UNSIGNED NOT NULL,
    `tyyppi` ENUM('summa', 'valisummaa') NOT NULL,
    `jalkeen_rivi_id` INT UNSIGNED NULL,
    `jarjestys` INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_raporttitaulukko_yhteenvetorivit_taulukko` (`taulukko_id`, `jarjestys`),
    UNIQUE KEY `uq_raporttitaulukko_yhteenvetorivit_id_laatijat_id` (`id`, `laatijat_id`),
    CONSTRAINT `fk_raporttitaulukko_yhteenvetorivit_taulukko`
        FOREIGN KEY (`taulukko_id`, `laatijat_id`) REFERENCES `raporttitaulukot` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_raporttitaulukko_yhteenvetorivit_rivi`
        FOREIGN KEY (`jalkeen_rivi_id`) REFERENCES `raporttitaulukko_rivit` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raporttitaulukko_yhteenvetorivi_sarakkeet` (
    `laatijat_id` INT UNSIGNED NOT NULL,
    `yhteenvetorivi_id` INT UNSIGNED NOT NULL,
    `sarake_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`yhteenvetorivi_id`, `sarake_id`, `laatijat_id`),
    CONSTRAINT `fk_raporttitaulukko_yhteenvetorivi_sarakkeet_yhteenveto`
        FOREIGN KEY (`yhteenvetorivi_id`, `laatijat_id`) REFERENCES `raporttitaulukko_yhteenvetorivit` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_raporttitaulukko_yhteenvetorivi_sarakkeet_sarake`
        FOREIGN KEY (`sarake_id`, `laatijat_id`) REFERENCES `raporttitaulukko_sarakkeet` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raporttitaulukko_laskentarivit` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `taulukko_id` INT UNSIGNED NOT NULL,
    `yhteenvetorivi_id` INT UNSIGNED NOT NULL,
    `nimittaja` VARCHAR(255) NOT NULL,
    `yksikot` DECIMAL(12,4) NOT NULL,
    `toiminto` ENUM('jaa', 'kerro', 'prosentti') NOT NULL,
    `lahde_sarake_id` INT UNSIGNED NOT NULL,
    `jarjestys` INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_raporttitaulukko_laskentarivit_yhteenveto` (`yhteenvetorivi_id`, `jarjestys`),
    UNIQUE KEY `uq_raporttitaulukko_laskentarivit_id_laatijat_id` (`id`, `laatijat_id`),
    CONSTRAINT `fk_raporttitaulukko_laskentarivit_taulukko`
        FOREIGN KEY (`taulukko_id`, `laatijat_id`) REFERENCES `raporttitaulukot` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_raporttitaulukko_laskentarivit_yhteenveto`
        FOREIGN KEY (`yhteenvetorivi_id`, `laatijat_id`) REFERENCES `raporttitaulukko_yhteenvetorivit` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_raporttitaulukko_laskentarivit_sarake`
        FOREIGN KEY (`lahde_sarake_id`, `laatijat_id`) REFERENCES `raporttitaulukko_sarakkeet` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raporttimedia` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `raportti_id` INT UNSIGNED NOT NULL,
    `raporttirivi_id` INT UNSIGNED NULL,
    `tyyppi` ENUM('kuva', 'video', 'videolinkki') NOT NULL,
    `tiedosto` VARCHAR(512) NULL,
    `alkuperainen_nimi` VARCHAR(255) NULL,
    `mime_type` VARCHAR(100) NULL,
    `tiedostokoko` BIGINT UNSIGNED NULL,
    `url` VARCHAR(2048) NULL,
    `kuvateksti` TEXT NULL,
    `jarjestys` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_raporttimedia_raportti` (`raportti_id`, `jarjestys`),
    KEY `idx_raporttimedia_raporttirivi` (`raporttirivi_id`, `jarjestys`),
    CONSTRAINT `fk_raporttimedia_raportti`
        FOREIGN KEY (`raportti_id`, `laatijat_id`) REFERENCES `raportit` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_raporttimedia_raporttirivi`
        FOREIGN KEY (`raporttirivi_id`, `laatijat_id`) REFERENCES `raporttirivit` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `kirjautumiskoodit` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `code_hash` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `yritykset` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_kirjautumiskoodit_laatija_voimassaolo` (`laatijat_id`, `expires_at`),
    CONSTRAINT `fk_kirjautumiskoodit_laatija`
        FOREIGN KEY (`laatijat_id`) REFERENCES `laatijat` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
