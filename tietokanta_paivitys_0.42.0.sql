-- Taulukoiden sarakekohtaiset fonttivärit.
ALTER TABLE `raporttitaulukko_sarakkeet`
    ADD COLUMN `fonttivari` CHAR(7) NOT NULL DEFAULT '#232d3c' AFTER `taustavari`;
