-- Salli raportin luominen jaetulle asiakkaalle.
-- Asiakas voi kuulua toiselle laatijalle kuin raportin omistajalle.
-- Suorita tämä olemassa olevaan tietokantaan ennen version 1.0.24 käyttöönottoa.
ALTER TABLE `raportit`
    DROP FOREIGN KEY `fk_raportit_asiakas`,
    ADD CONSTRAINT `fk_raportit_asiakas_id`
        FOREIGN KEY (`asiakas_id`) REFERENCES `asiakkaat` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT;
