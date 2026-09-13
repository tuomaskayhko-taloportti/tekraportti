-- Lisää taulukoille pysty- ja vaakasuuntaisen tekstin tasauksen.
-- Suorita tämä olemassa olevaan tietokantaan ennen version 1.2.0 käyttöönottoa.
ALTER TABLE `raporttitaulukot`
    ADD COLUMN `tasaus_pysty` ENUM('ylos', 'keskelle', 'alas') NOT NULL DEFAULT 'alas' AFTER `leveys_tila`,
    ADD COLUMN `tasaus_vaaka` ENUM('vasen', 'keski', 'oikea') NOT NULL DEFAULT 'vasen' AFTER `tasaus_pysty`;
