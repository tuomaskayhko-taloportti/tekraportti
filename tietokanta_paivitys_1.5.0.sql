-- Raporttimallit. Malli käyttää raportin sisältöä tilannekuvana: osiot ja tekstilohkot kopioidaan uuteen raporttiin.
CREATE TABLE IF NOT EXISTS `raporttimallit` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `asiakas_id` INT UNSIGNED NOT NULL,
    `lahde_raportti_id` INT UNSIGNED NULL,
    `nimi` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_raporttimallit_laatijat_id` (`laatijat_id`),
    KEY `idx_raporttimallit_asiakas_id` (`asiakas_id`),
    CONSTRAINT `fk_raporttimallit_laatija` FOREIGN KEY (`laatijat_id`) REFERENCES `laatijat` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_raporttimallit_asiakas` FOREIGN KEY (`asiakas_id`) REFERENCES `asiakkaat` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_raporttimallit_lahde` FOREIGN KEY (`lahde_raportti_id`) REFERENCES `raportit` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
