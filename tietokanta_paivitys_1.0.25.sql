-- Salli raportin luominen jaetulle projektille.
-- Projekti voi kuulua toiselle laatijalle kuin raportin omistajalle.
-- Suorita tämä olemassa olevaan tietokantaan ennen version 1.0.25 käyttöönottoa.
ALTER TABLE `raportit`
    DROP FOREIGN KEY `fk_raportit_projekti`,
    ADD CONSTRAINT `fk_raportit_projekti_id`
        FOREIGN KEY (`projekti_id`) REFERENCES `projektit` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT;
