-- Lisää asiakkaiden jakamisen projektien ja raporttien jakamisen tapaan.
CREATE TABLE IF NOT EXISTS `asiakasjaot` (
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
