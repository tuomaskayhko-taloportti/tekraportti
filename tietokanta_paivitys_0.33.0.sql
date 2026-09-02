-- Suorita tämä olemassa olevaan tietokantaan ennen version 0.33.0 käyttöönottoa.
-- Lisää laatijakohtaisen ylätunnisteen logon tiedostopolun.
ALTER TABLE `laatijat`
    ADD COLUMN `logo_url` VARCHAR(512) NULL AFTER `postitmp`;
