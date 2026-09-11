-- Varmista, että laskentarivin poistaminen ei edellytä summarivin poistamista.
-- Suorita tämä olemassa olevaan tietokantaan ennen version 1.0.26 käyttöönottoa.
ALTER TABLE `raporttitaulukko_laskentarivit`
    DROP FOREIGN KEY `fk_raporttitaulukko_laskentarivit_yhteenveto`,
    ADD CONSTRAINT `fk_laskentarivit_yhteenveto_1026`
        FOREIGN KEY (`yhteenvetorivi_id`, `laatijat_id`)
        REFERENCES `raporttitaulukko_yhteenvetorivit` (`id`, `laatijat_id`)
        ON UPDATE CASCADE ON DELETE CASCADE;
