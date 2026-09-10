-- Lisää taulukoille summariveihin sidottavat laskentarivit.
-- Suorita tämä olemassa olevaan tietokantaan ennen version 1.0.9 käyttöönottoa.
CREATE TABLE IF NOT EXISTS `raporttitaulukko_laskentarivit` (
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
