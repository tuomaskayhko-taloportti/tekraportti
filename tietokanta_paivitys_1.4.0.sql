-- Lisää osioihin järjestettäviä tekstilohkoja taulukoiden rinnalle.
-- Suorita tämä olemassa olevaan tietokantaan ennen version 1.4.0 käyttöönottoa.
CREATE TABLE IF NOT EXISTS `raporttitekstit` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `raportti_id` INT UNSIGNED NOT NULL,
    `raporttirivi_id` INT UNSIGNED NOT NULL,
    `sisalto` LONGTEXT NULL,
    `jarjestys` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_raporttitekstit_raporttirivi_jarjestys` (`raporttirivi_id`, `jarjestys`, `id`),
    UNIQUE KEY `uq_raporttitekstit_id_laatijat_id` (`id`, `laatijat_id`),
    CONSTRAINT `fk_raporttitekstit_raportti`
        FOREIGN KEY (`raportti_id`, `laatijat_id`) REFERENCES `raportit` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_raporttitekstit_raporttirivi`
        FOREIGN KEY (`raporttirivi_id`, `laatijat_id`) REFERENCES `raporttirivit` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
