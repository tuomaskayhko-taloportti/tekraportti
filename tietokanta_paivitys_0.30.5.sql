-- Suorita tämä olemassa olevaan tietokantaan ennen version 0.30.5 käyttöönottoa.
CREATE TABLE IF NOT EXISTS `raporttitaulukko_yhteenvetorivit` (
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

CREATE TABLE IF NOT EXISTS `raporttitaulukko_yhteenvetorivi_sarakkeet` (
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
