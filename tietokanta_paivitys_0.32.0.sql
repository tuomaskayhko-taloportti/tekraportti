-- Säilyttää kuvan merkinnät alkuperäisestä kuvatiedostosta erillisenä JSON-datana.
ALTER TABLE `raporttimedia`
    ADD COLUMN `merkinnat_json` MEDIUMTEXT NULL AFTER `kuvateksti`;
