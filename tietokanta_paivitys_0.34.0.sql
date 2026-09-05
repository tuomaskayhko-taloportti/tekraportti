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
