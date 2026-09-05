-- Taulukoiden ulkoasuasetukset.
ALTER TABLE `raporttitaulukot`
    ADD COLUMN `reunat` TINYINT(1) NOT NULL DEFAULT 1 AFTER `otsikko`,
    ADD COLUMN `leveys_tila` ENUM('keskitetty', 'kokosivu') NOT NULL DEFAULT 'keskitetty' AFTER `reunat`;

ALTER TABLE `raporttitaulukko_sarakkeet`
    ADD COLUMN `taustavari` CHAR(7) NOT NULL DEFAULT '#ffffff' AFTER `nimi`;
