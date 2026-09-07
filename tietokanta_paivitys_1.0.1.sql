-- Liittää projektit asiakkaisiin ja mahdollistaa jaetun asiakkaan yhteisen työn.
-- Suorita tämä olemassa olevaan tietokantaan ennen version 1.0.1 käyttöönottoa.
ALTER TABLE `projektit`
    ADD COLUMN `asiakas_id` INT UNSIGNED NULL AFTER `laatijat_id`,
    ADD KEY `idx_projektit_asiakas_id` (`asiakas_id`),
    ADD CONSTRAINT `fk_projektit_asiakas`
        FOREIGN KEY (`asiakas_id`) REFERENCES `asiakkaat` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT;

-- Kohdistetaan vanhat projektit automaattisesti, jos niiden raporteilla on
-- yksiselitteisesti sama asiakas. Moniasiakkaiset projektit jäävät NULL-arvoon
-- ja ne voidaan kohdistaa uudelleen käyttöliittymässä.
UPDATE `projektit` p
INNER JOIN (
    SELECT `projekti_id`, MIN(`asiakas_id`) AS `asiakas_id`
    FROM `raportit`
    WHERE `projekti_id` IS NOT NULL
    GROUP BY `projekti_id`
    HAVING COUNT(DISTINCT `asiakas_id`) = 1
) r ON r.`projekti_id` = p.`id`
SET p.`asiakas_id` = r.`asiakas_id`
WHERE p.`asiakas_id` IS NULL;
