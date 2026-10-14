<?php

require_once __DIR__ . '/funktiot.php';
sendApplicationSecurityHeaders();

function privacyMarkdownToHtml(string $text): string
{
    $html = '';
    $inList = false;
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            continue;
        }
        if (preg_match('/^(#{1,2})\s+(.+)$/u', $trimmed, $heading)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $level = strlen($heading[1]);
            $html .= '<h' . $level . '>' . markdownInlineToHtml($heading[2]) . '</h' . $level . '>';
        } elseif (str_starts_with($trimmed, '* ')) {
            if (!$inList) { $html .= '<ul>'; $inList = true; }
            $html .= '<li>' . markdownInlineToHtml(substr($trimmed, 2)) . '</li>';
        } else {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<p>' . markdownInlineToHtml($trimmed) . '</p>';
        }
    }
    return $inList ? $html . '</ul>' : $html;
}

$privacyText = <<<'TEXT'
# Tietosuojaseloste

**Päivitetty 5.9.2026**

Tässä tietosuojaselosteessa kuvataan, miten tekraportti.com-palvelun yhteydessä kerätään, käytetään, säilytetään ja suojataan henkilötietoja.

## 1. Rekisterinpitäjä

**Helsingin Kiinteistömanagerit Oy / Koodiportti**
Y-tunnus: 3203383-5

Koodiportti on Helsingin Kiinteistömanagerit Oy:n aputoiminimi.

Palvelu: **tekraportti.com**

## 2. Tietosuojasta vastaava

Tietosuojaa koskevissa kysymyksissä yhteydenotot:

**Koodiportti / Helsingin Kiinteistömanagerit Oy**

Yhteydenoton voi tehdä tekraportti.com-palvelun kautta tai rekisterinpitäjän ilmoittamaan sähköpostiosoitteeseen.

## 3. Mitä henkilötietoja käsittelemme?

Voimme käsitellä asiakkaista, yhteyshenkilöistä ja palvelun käyttäjistä seuraavia tietoja:

* nimi
* puhelinnumero
* sähköpostiosoite
* postiosoite
* Y-tunnus
* henkilötunnus
* yhteisön nimi
* yhteydenottolomakkeella, sähköpostitse tai muutoin palvelun käyttämisen yhteydessä annetut palvelun toteuttamiseksi tarpeelliset tiedot.

Tietoja saadaan lähtökohtaisesti rekisteröidyltä itseltään yhteydenottolomakkeen, sähköpostin tai muun asioinnin yhteydessä.

## 4. Henkilötietojen käsittelyn tarkoitukset

Henkilötietoja käsitellään seuraaviin tarkoituksiin:

* tekraportti.com-palvelun ja siihen liittyvien palvelujen tarjoaminen ja toimittaminen;
* asiakkaan yhteydenottoihin ja toimeksiantoihin vastaaminen;
* asiakassuhteen ja toimeksiantojen hoitaminen;
* palvelun toiminnan ylläpitäminen ja kehittäminen;
* laskutus ja maksujen hallinnointi; sekä
* rekisterinpitäjälle kuuluvien lakisääteisten velvoitteiden hoitaminen.

Henkilötietoja käsitellään vain siinä laajuudessa kuin se on kulloisenkin käyttötarkoituksen kannalta tarpeellista.

## 5. Käsittelyn oikeusperuste

Henkilötietojen käsittely perustuu tilanteesta riippuen:

* rekisteröidyn kanssa tehtävän sopimuksen täytäntöönpanoon tai sopimusta edeltäviin toimenpiteisiin;
* rekisterinpitäjän lakisääteisten velvoitteiden noudattamiseen; tai
* rekisterinpitäjän oikeutettuun etuun, kuten palvelun, asiakaspalvelun ja liiketoiminnan asianmukaiseen kehittämiseen ja turvaamiseen.

Henkilötunnusta käsitellään vain silloin, kun henkilön yksiselitteinen yksilöiminen on käsittelyn tarkoituksen kannalta tarpeellista ja käsittelylle on lainmukainen peruste. Henkilötunnusta ei kerätä tai käytetä tarpeettomasti.

## 6. Henkilötietojen säilyttäminen

Henkilötietoja säilytetään vain niin kauan kuin se on tarpeen tässä selosteessa kuvattujen käyttötarkoitusten toteuttamiseksi tai kuin lainsäädäntö edellyttää.

Asiakas- ja toimeksiantotietoja voidaan säilyttää asiakassuhteen tai toimeksiannon ajan sekä tämän jälkeen siinä määrin kuin säilyttäminen on tarpeen esimerkiksi kirjanpitoa, laskutusta, oikeudellisten vaatimusten selvittämistä tai muiden lakisääteisten velvoitteiden täyttämistä varten.

Tarpeettomat henkilötiedot poistetaan tai anonymisoidaan asianmukaisesti.

## 7. Tietojen vastaanottajat ja henkilötietojen käsittelijät

Henkilötietoja voidaan luovuttaa tai antaa käsiteltäväksi rekisterinpitäjän käyttämille palveluntarjoajille silloin, kun se on tarpeen palvelun toteuttamiseksi. Tällaisia voivat olla esimerkiksi palvelun tekniseen ylläpitoon, tietojen säilyttämiseen, sähköpostiin, maksamiseen tai laskutukseen osallistuvat palveluntarjoajat.

Palveluntarjoajat saavat käsitellä henkilötietoja vain sovittujen palvelujen tuottamiseksi ja rekisterinpitäjän ohjeiden mukaisesti.

Tietoja voidaan lisäksi luovuttaa viranomaisille tai muille tahoille silloin, kun laki sitä edellyttää.

## 8. Henkilötietojen siirtäminen EU:n tai ETA:n ulkopuolelle

Mikäli henkilötietoja käsittelevä palveluntarjoaja käsittelee tietoja Euroopan unionin tai Euroopan talousalueen ulkopuolella, henkilötietojen siirroissa huolehditaan sovellettavan tietosuojalainsäädännön edellyttämistä suojatoimista.

## 9. Henkilötietojen suojaaminen

Henkilötiedot suojataan asianmukaisilla teknisillä ja organisatorisilla toimenpiteillä luvattomalta pääsyltä sekä tietojen vahingossa tai lainvastaisesti tapahtuvalta häviämiseltä, muuttamiselta, luovuttamiselta tai muulta käsittelyltä.

Henkilötietoihin pääsy rajataan henkilöihin, joiden tehtävien hoitaminen edellyttää tietojen käsittelyä.

## 10. Rekisteröidyn oikeudet

Rekisteröidyllä on sovellettavan tietosuojalainsäädännön mukaisesti oikeus:

* saada tietää, käsitelläänkö häntä koskevia henkilötietoja, ja tarkastaa häntä koskevat tiedot;
* pyytää virheellisten tai puutteellisten tietojen oikaisemista;
* pyytää henkilötietojen poistamista silloin, kun poistamiselle säädetyt edellytykset täyttyvät;
* pyytää henkilötietojen käsittelyn rajoittamista;
* vastustaa oikeutettuun etuun perustuvaa henkilötietojen käsittelyä henkilökohtaiseen erityiseen tilanteeseensa liittyvällä perusteella; sekä
* saada itse toimittamansa tiedot siirrettyä järjestelmästä toiseen silloin, kun tähän sovellettavan lainsäädännön perusteella on oikeus.

Oikeuksien käyttämistä koskevan pyynnön voi osoittaa rekisterinpitäjälle. Rekisterinpitäjä voi tarvittaessa pyytää pyynnön esittäjää todentamaan henkilöllisyytensä.

## 11. Oikeus tehdä valitus valvontaviranomaiselle

Jos rekisteröity katsoo, että hänen henkilötietojaan on käsitelty tietosuojasäännösten vastaisesti, hänellä on oikeus saattaa asia toimivaltaisen valvontaviranomaisen käsiteltäväksi.

Suomessa henkilötietojen käsittelyä valvoo **Tietosuojavaltuutetun toimisto**.

## 12. Automaattinen päätöksenteko

Henkilötietoja ei käytetä sellaiseen yksinomaan automaattiseen päätöksentekoon, jolla olisi rekisteröityä koskevia oikeusvaikutuksia tai vastaavia merkittäviä vaikutuksia, ellei tästä ilmoiteta erikseen.

## 13. Tietosuojaselosteen muuttaminen

Rekisterinpitäjä voi päivittää tätä tietosuojaselostetta esimerkiksi palvelun, henkilötietojen käsittelyn tai lainsäädännön muuttuessa. Ajantasainen tietosuojaseloste julkaistaan tekraportti.com-palvelussa.
TEXT;

?><!doctype html>
<html lang="fi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tietosuojaseloste – TEKRAPORTTI</title>
    <link rel="icon" type="image/png" href="Logo pyöreä.png">
    <style>
        :root { --navy:#071a3d; --blue:#1769e8; --muted:#52627a; }
        body { margin:0; background:#f5f8fc; color:var(--navy); font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        .privacy-header { background:#fff; border-bottom:1px solid #dce6f1; padding:22px 28px; }
        .privacy-header img { width:250px; max-width:70vw; }
        .privacy-main { max-width:920px; margin:38px auto; padding:42px 52px; background:#fff; border:1px solid #dce6f1; border-radius:12px; box-shadow:0 6px 18px #1a34560d; }
        h1 { font-size:2.2rem; margin:0 0 8px; } h2 { margin:34px 0 10px; font-size:1.35rem; } p,li { color:var(--muted); line-height:1.65; } ul { padding-left:25px; }
        .privacy-back { color:var(--blue); text-decoration:none; font-weight:600; }
        @media (max-width:600px) { .privacy-main { margin:18px 12px; padding:25px 22px; } .privacy-header { padding:18px; } }
    </style>
</head>
<body><header class="privacy-header"><a href="index.php"><img src="Logo.png" alt="TEKRAPORTTI"></a></header><main class="privacy-main"><a class="privacy-back" href="index.php">← Takaisin etusivulle</a><?= privacyMarkdownToHtml($privacyText) ?></main></body>
</html>
