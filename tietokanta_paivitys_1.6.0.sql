CREATE TABLE `raporttiaineistot` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `laatijat_id` INT UNSIGNED NOT NULL,
    `raportti_id` INT UNSIGNED NOT NULL,
    `tiedosto` VARCHAR(512) NOT NULL,
    `alkuperainen_nimi` VARCHAR(255) NOT NULL,
    `tiedostokoko` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `otsikot_json` JSON NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_raporttiaineistot_raportti` (`raportti_id`),
    CONSTRAINT `fk_raporttiaineistot_raportti`
        FOREIGN KEY (`raportti_id`, `laatijat_id`) REFERENCES `raportit` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
