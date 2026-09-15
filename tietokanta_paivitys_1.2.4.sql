-- Suorita tämä olemassa olevaan tietokantaan ennen version 1.2.4 käyttöönottoa.
ALTER TABLE `raporttitaulukko_yhteenvetorivit`
    ADD COLUMN `nimittaja` VARCHAR(255) NOT NULL DEFAULT 'Yhteensä' AFTER `taulukko_id`;
