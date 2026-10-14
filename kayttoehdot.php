<?php

require_once __DIR__ . '/funktiot.php';
sendApplicationSecurityHeaders();

function termsMarkdownToHtml(string $text): string
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

$termsText = <<<'TEXT'
# TEKRAPORTTI.COM – KÄYTTÖ- JA SOPIMUSEHDOT

**Voimassa 14.10.2026 alkaen**

## 1. Yleistä

Näitä käyttö- ja sopimusehtoja ("Ehdot") sovelletaan Helsingin Kiinteistömanagerit Oy:n aputoiminimen Koodiportti tarjoamaan tekraportti.com-verkkopalveluun ja sen kautta tarjottaviin palveluihin.

**Palveluntarjoaja:**
Helsingin Kiinteistömanagerit Oy / Koodiportti
Y-tunnus: 3203383-5
Palvelu: tekraportti.com

Jäljempänä **"Palveluntarjoaja"**.

Palvelua tilaavaa tai käyttävää yritystä, yhteisöä tai muuta oikeushenkilöä kutsutaan jäljempänä **"Asiakkaaksi"**.

Palvelua Asiakkaan puolesta käyttävää luonnollista henkilöä kutsutaan jäljempänä **"Käyttäjäksi"**.

Tekraportti.com-verkkopalvelua ja sen kautta toimitettavia sähköisiä palveluja kutsutaan jäljempänä **"Palveluksi"**.

Näitä Ehtoja sovelletaan Palveluntarjoajan ja Asiakkaan väliseen Palvelua koskevaan sopimukseen, ellei osapuolten välillä ole kirjallisesti sovittu toisin.

Palvelu on tarkoitettu ensisijaisesti yrityksille ja yhteisöille eikä kuluttajille.

## 2. Sopimuksen syntyminen

Sopimus syntyy, kun Asiakas tilaa Palvelun tekraportti.com-verkkopalvelussa tai muutoin hyväksyy Palveluntarjoajan tarjouksen ja nämä Ehdot.

Sopimus voi syntyä myös silloin, kun Palveluntarjoaja vahvistaa Asiakkaan tekemän tilauksen.

Tilauksen tekevän henkilön tulee olla oikeutettu tekemään tilaus Asiakkaan puolesta.

Asiakas vastaa Palveluntarjoajalle antamiensa tietojen oikeellisuudesta ja riittävyydestä Palvelun toteuttamiseksi.

## 3. Palvelun sisältö

Palveluntarjoaja toimittaa Asiakkaalle tilauksen tai muun sopimuksen mukaisen Palvelun.

Palvelun tarkempi sisältö, ominaisuudet, hinta ja mahdolliset toimitusajat määräytyvät tilaushetkellä ilmoitettujen tietojen, tarjouksen tai muun osapuolten välisen sopimuksen perusteella.

Palveluntarjoajalla on oikeus kehittää ja muuttaa Palvelun teknistä toteutusta, käyttöliittymää, ominaisuuksia ja toimintatapoja.

Palveluntarjoaja pyrkii siihen, etteivät Palveluun tehtävät muutokset olennaisesti heikennä jo tilatun Palvelun keskeisiä ominaisuuksia.

## 4. Palvelun käyttäminen

Asiakkaalle myönnetään sopimuksen voimassaolon ajaksi rajoitettu, ei-yksinomainen ja ei-siirrettävä oikeus käyttää Palveluntarjoajan ylläpitämää Palvelua sovittuun tarkoitukseen. Tämä käyttöoikeus koskee pääsyä Palveluntarjoajan ylläpitämään palveluympäristöön eikä rajoita Tekraportti-ohjelmiston avoimen lähdekoodin lisenssin mukaisia oikeuksia.

Asiakas saa käyttää Palvelua vain lain, näiden Ehtojen ja Palvelussa annettujen ohjeiden mukaisesti.

Palveluntarjoajan ylläpitämää palveluympäristöä käyttäessään Asiakas ei saa:

* käyttää toisen asiakkaan käyttäjätunnuksia, aineistoa tai palveluresursseja ilman asianmukaista oikeutta;
* kiertää palveluympäristön käyttöoikeusrajoituksia tai tietoturvamekanismeja muutoin kuin käyttäessään sovellettavan avoimen lähdekoodin lisenssin mukaisia oikeuksia omaan ohjelmistokopioonsa;
* käyttää Palvelua lainvastaiseen tai kolmannen osapuolen oikeuksia loukkaavaan tarkoitukseen; tai
* käyttää Palvelua tavalla, joka voi aiheuttaa vahinkoa Palvelulle, Palveluntarjoajalle, muille käyttäjille tai kolmansille osapuolille.

Tekraportti-ohjelmisto on lisensoitu GNU Affero General Public License version 3.0 -lisenssillä (AGPL-3.0-only). Ohjelmiston kopioimiseen, käyttämiseen, tutkimiseen, muuttamiseen ja edelleen levittämiseen sovelletaan AGPLv3-lisenssiä. Nämä Ehdot eivät rajoita tai korvaa kyseisen lisenssin myöntämiä oikeuksia eivätkä aseta niiden käyttämiselle AGPLv3:n vastaisia lisärajoituksia. Lisenssiteksti ja lähdekoodi ovat saatavilla Palvelun alatunnisteessa olevien linkkien kautta.

## 5. Käyttäjätunnukset

Mikäli Palvelun käyttäminen edellyttää käyttäjätunnusta, Asiakas ja Käyttäjä vastaavat käyttäjätunnusten ja salasanojen asianmukaisesta säilyttämisestä.

Käyttäjätunnukset ovat henkilökohtaisia, ellei Palveluntarjoaja ole ilmoittanut toisin.

Asiakkaan tulee ilmoittaa Palveluntarjoajalle ilman aiheetonta viivytystä, jos Asiakas epäilee käyttäjätunnuksen tai muun Palveluun pääsyn mahdollistavan tiedon joutuneen sivullisen haltuun.

Asiakas vastaa lähtökohtaisesti tunnuksillaan tapahtuvasta Palvelun käytöstä.

## 6. Asiakkaan toimittamat tiedot ja aineistot

Asiakas vastaa Palveluun syöttämistään, lataamistaan, lähettämistään tai muutoin Palveluntarjoajalle toimittamistaan tiedoista ja aineistoista ("Asiakkaan Aineisto").

Asiakas vastaa siitä, että sillä on oikeus käyttää ja toimittaa Asiakkaan Aineisto Palveluntarjoajalle Palvelun toteuttamista varten.

Asiakas vastaa erityisesti siitä, ettei Asiakkaan Aineisto loukkaa kolmannen osapuolen tekijänoikeutta, tietosuojaa, liikesalaisuutta tai muuta oikeutta.

Asiakas vastaa Palvelun toteuttamista varten antamiensa lähtötietojen oikeellisuudesta.

Palveluntarjoajalla on oikeus käsitellä Asiakkaan Aineistoa siinä laajuudessa kuin Palvelun tuottaminen, ylläpito, tietoturva ja tekninen kehittäminen sitä edellyttävät.

## 7. Henkilötietojen käsittely

Palveluntarjoaja käsittelee henkilötietoja sovellettavan tietosuojalainsäädännön ja tekraportti.com-palvelun kulloinkin voimassa olevan tietosuojaselosteen mukaisesti.

Jos Palveluntarjoaja käsittelee Palvelun tuottamisen yhteydessä henkilötietoja Asiakkaan lukuun siten, että Asiakas toimii rekisterinpitäjänä ja Palveluntarjoaja henkilötietojen käsittelijänä, henkilötietojen käsittelystä voidaan sopia erillisellä henkilötietojen käsittelysopimuksella.

Asiakas vastaa omalta osaltaan siitä, että sillä on lainmukainen peruste Palveluun toimittamiensa henkilötietojen käsittelyyn.

## 8. Immateriaalioikeudet ja avoimen lähdekoodin lisenssi

Tekraportti-ohjelmiston tekijänoikeudet ja muut immateriaalioikeudet kuuluvat Palveluntarjoajalle, ohjelmiston muille tekijöille tai heidän lisenssinantajilleen. Ohjelmiston omistusoikeus ei siirry Asiakkaalle, mutta ohjelmisto on saatavilla ja käytettävissä AGPL-3.0-only-lisenssin ehtojen mukaisesti.

AGPLv3 antaa lisenssin ehtoja noudattavalle vastaanottajalle oikeuden muun muassa käyttää, kopioida, tutkia, muuttaa ja levittää ohjelmistoa. Jos muutettua versiota käytetään verkkopalveluna, sen kanssa verkon välityksellä vuorovaikutuksessa oleville käyttäjille on tarjottava mahdollisuus saada kyseisen version vastaava lähdekoodi AGPLv3:n 13 kohdan mukaisesti. Tarkat ja ensisijaiset ohjelmistolisenssin ehdot ovat ohjelmiston mukana toimitettavassa LICENSE.md-tiedostossa.

Palveluntarjoajan tavaramerkit, liikesalaisuudet, asiakastiedot, henkilötiedot ja sellaiset aineistot, joita ei ole julkaistu AGPLv3-lisenssillä, eivät muutu avoimiksi pelkästään siksi, että Tekraportti-ohjelmisto on avoimen lähdekoodin ohjelmisto.

Asiakkaalle kuuluu Asiakkaan Palveluun toimittama aineisto ja siihen liittyvät oikeudet.

## 9. Palvelun saatavuus

Palveluntarjoaja pyrkii pitämään Palvelun käytettävissä normaalisti ja korjaamaan Palvelussa havaitut olennaiset virheet kohtuullisessa ajassa.

Palveluntarjoaja ei takaa Palvelun keskeytyksetöntä tai täysin virheetöntä toimintaa.

Palvelu voidaan tilapäisesti keskeyttää esimerkiksi:

* huolto- ja päivitystöiden;
* tietoturvatoimenpiteiden;
* teknisen vian;
* tietoliikenneongelman;
* kolmannen osapuolen palvelun häiriön; tai
* muun Palveluntarjoajan kohtuullisen vaikutusmahdollisuuden ulkopuolella olevan syyn vuoksi.

Palveluntarjoajalla on oikeus tehdä Palveluun huolto- ja päivitystoimenpiteitä ilman erillistä ennakkoilmoitusta, jos toimenpiteen kiireellisyys tai tietoturva sitä edellyttää.

## 10. Palvelun tulokset ja niiden käyttäminen

Palvelun tuottamat raportit, laskelmat, analyysit, yhteenvedot ja muut tulokset perustuvat Asiakkaan tai kolmansien osapuolten toimittamiin tietoihin sekä Palvelun käyttämään tekniseen käsittelyyn.

Asiakas vastaa siitä, että Palvelulle annetut lähtötiedot ovat oikeita ja riittäviä.

Palveluntarjoaja ei vastaa virheestä, joka johtuu Asiakkaan tai kolmannen osapuolen antamasta virheellisestä, puutteellisesta tai vanhentuneesta tiedosta.

Asiakkaan tulee ennen olennaisten oikeudellisten, taloudellisten tai teknisten päätösten tekemistä arvioida Palvelun tuottamien tietojen soveltuvuus kyseiseen käyttötarkoitukseen.

Palvelun tuottamaa raporttia tai muuta lopputulosta ei pidetä oikeudellisena, verotuksellisena tai muuna asiantuntijaneuvontana, ellei kyseisen palvelun yhteydessä ole nimenomaisesti toisin ilmoitettu.

## 11. Hinnat ja maksaminen

Palvelun hinta määräytyy tilaushetkellä voimassa olevan hinnaston, tarjouksen tai muun osapuolten välisen sopimuksen perusteella.

Hintoihin lisätään kulloinkin voimassa oleva arvonlisävero, ellei hintaa ole nimenomaisesti ilmoitettu arvonlisäverollisena.

Asiakas vastaa Palvelun käytöstä aiheutuvista maksuista.

Laskut maksetaan laskussa ilmoitettuun eräpäivään mennessä. Viivästyneelle maksulle voidaan periä korkolain mukaista viivästyskorkoa sekä kohtuulliset perintäkulut.

Palveluntarjoajalla on oikeus keskeyttää Palvelun toimittaminen tai Asiakkaan käyttöoikeus, jos erääntynyt maksu on olennaisesti viivästynyt eikä Asiakas ole maksanut saatavaa Palveluntarjoajan maksukehotuksesta huolimatta.

## 12. Palveluntarjoajan vastuu

Palveluntarjoaja vastaa välittömistä vahingoista, jotka aiheutuvat Palveluntarjoajan sopimusrikkomuksesta.

Ellei pakottavasta lainsäädännöstä muuta johdu, Palveluntarjoaja ei vastaa välillisistä tai epäsuorista vahingoista, kuten:

* saamatta jääneestä voitosta tai liikevaihdosta;
* liiketoiminnan keskeytymisestä;
* tietojen tai aineiston menettämisestä;
* kolmannen osapuolen vaatimuksista; tai
* muusta vastaavasta välillisestä vahingosta.

Palveluntarjoajan yhteenlaskettu vastuu Sopimukseen perustuvista vahingoista rajoittuu määrään, jonka Asiakas on maksanut kyseisestä Palvelusta vahingon aiheuttanutta tapahtumaa edeltäneiden kuuden (6) kuukauden aikana.

Jos kyseessä on yksittäisenä kertamaksuna ostettu Palvelu, vastuun enimmäismäärä on kyseisestä Palvelusta maksettu arvonlisäveroton hinta.

Vastuunrajoituksia ei sovelleta siltä osin kuin vahinko on aiheutettu tahallisesti tai törkeällä huolimattomuudella tai vastuun rajoittaminen ei muutoin ole pakottavan lainsäädännön perusteella mahdollista.

## 13. Kolmansien osapuolten palvelut

Palvelu voi hyödyntää kolmansien osapuolten ohjelmistoja, rajapintoja, tietolähteitä, hosting-palveluja tai muita teknisiä palveluja.

Palveluntarjoaja ei vastaa sellaisesta Palvelun häiriöstä tai virheestä, joka johtuu Palveluntarjoajan kohtuullisen vaikutusmahdollisuuden ulkopuolella olevasta kolmannen osapuolen palvelusta.

Palveluntarjoajalla on oikeus vaihtaa Palvelun toteuttamisessa käyttämiään alihankkijoita ja teknisiä palveluntarjoajia.

## 14. Palvelun keskeyttäminen

Palveluntarjoajalla on oikeus keskeyttää Asiakkaan tai Käyttäjän pääsy Palveluun kokonaan tai osittain, jos:

* Palvelua käytetään lain tai näiden Ehtojen vastaisesti;
* Palvelun käyttö aiheuttaa tietoturvariskin;
* Palvelun käyttö voi aiheuttaa vahinkoa Palveluntarjoajalle tai kolmannelle osapuolelle;
* Asiakkaan maksu on olennaisesti viivästynyt; tai
* Palvelun keskeyttäminen on tarpeen viranomaisen määräyksen tai lain perusteella.

Mikäli mahdollista, Palveluntarjoaja varaa Asiakkaalle kohtuullisen ajan korjata rikkomuksensa ennen Palvelun keskeyttämistä.

## 15. Sopimuksen voimassaolo ja päättäminen

Sopimus on voimassa tilauksen yhteydessä sovitun ajan.

Kertaluonteista Palvelua koskeva sopimus päättyy, kun Palvelu on toimitettu ja osapuolten sopimukseen perustuvat velvoitteet on täytetty.

Jatkuvaa Palvelua koskeva sopimus on voimassa määräaikaisesti tai toistaiseksi sen mukaan kuin tilauksen yhteydessä on ilmoitettu.

Toistaiseksi voimassa oleva sopimus voidaan irtisanoa yhden (1) kuukauden irtisanomisajalla, ellei tilauksen yhteydessä ole sovittu muuta.

Osapuolella on oikeus purkaa sopimus päättymään välittömästi, jos toinen osapuoli olennaisesti rikkoo sopimusta eikä korjaa rikkomustaan kohtuullisessa ajassa saatuaan sitä koskevan kirjallisen huomautuksen.

## 16. Sopimuksen päättymisen vaikutukset

Sopimuksen päättyessä Asiakkaan oikeus käyttää Palveluntarjoajan ylläpitämää palveluympäristöä päättyy, ellei toisin ole sovittu. Sopimuksen päättyminen ei päätä tai rajoita Asiakkaan AGPLv3-lisenssin mukaisesti saaman ohjelmistokopion lisenssioikeuksia.

Palveluntarjoajalla on oikeus poistaa Palveluun tallennetut Asiakkaan tiedot kohtuullisen ajan kuluttua sopimuksen päättymisestä, ellei niiden säilyttäminen ole tarpeen lain, sopimuksen tai oikeudellisen vaatimuksen vuoksi.

Asiakas vastaa tarvitsemiensa tietojen ja aineistojen talteen ottamisesta ennen käyttöoikeuden päättymistä, jos Palvelu mahdollistaa niiden tallentamisen tai viennin.

## 17. Luottamuksellisuus

Osapuolet sitoutuvat pitämään luottamuksellisina toiselta osapuolelta saamansa sellaiset tiedot, jotka on merkitty luottamuksellisiksi tai jotka on niiden luonteen perusteella ymmärrettävä luottamuksellisiksi.

Luottamuksellisia tietoja saa käyttää vain sopimuksen mukaisten velvoitteiden täyttämiseen.

Salassapitovelvollisuus ei koske tietoa, joka on julkisesti saatavilla tai jonka vastaanottava osapuoli pystyy osoittamaan saaneensa laillisesti kolmannelta osapuolelta ilman salassapitovelvollisuutta.

## 18. Ylivoimainen este

Osapuoli ei vastaa sopimusvelvoitteen täyttämättä jättämisestä tai viivästymisestä siltä osin kuin se johtuu osapuolen kohtuullisen vaikutusmahdollisuuden ulkopuolella olevasta ennalta arvaamattomasta esteestä.

Tällaisena esteenä voidaan pitää esimerkiksi laajaa tietoliikenne- tai sähkökatkoa, viranomaisen toimenpidettä, työtaistelua, luonnonkatastrofia, sotaa, vakavaa kyberhyökkäystä tai muuta vastaavaa tapahtumaa.

## 19. Ehtojen muuttaminen

Palveluntarjoajalla on oikeus muuttaa näitä Ehtoja Palvelun tai liiketoiminnan kehittymisen, lainsäädännön muutoksen, tietoturvan tai muun perustellun syyn vuoksi.

Olennaisista Asiakkaan asemaan vaikuttavista muutoksista ilmoitetaan kohtuullisessa ajassa ennen muutoksen voimaantuloa.

Jos muutos olennaisesti heikentää toistaiseksi voimassa olevan sopimuksen ehtoja Asiakkaan vahingoksi, Asiakkaalla on oikeus irtisanoa sopimus päättymään muutoksen voimaantuloon.

## 20. Sopimuksen siirtäminen

Asiakas ei saa siirtää sopimusta kolmannelle osapuolelle ilman Palveluntarjoajan etukäteen antamaa kirjallista suostumusta.

Palveluntarjoajalla on oikeus siirtää sopimus samaan konserniin kuuluvalle yhtiölle tai liiketoimintakaupan, yritysjärjestelyn tai muun vastaavan järjestelyn yhteydessä Palvelua jatkavalle taholle.

## 21. Sovellettava laki ja erimielisyydet

Sopimukseen ja näihin Ehtoihin sovelletaan Suomen lakia, lainvalintasäännöksiä lukuun ottamatta.

Osapuolet pyrkivät ensisijaisesti ratkaisemaan sopimuksesta aiheutuvat erimielisyydet neuvotteluteitse.

Jos erimielisyyttä ei saada ratkaistua neuvotteluin, asia ratkaistaan toimivaltaisessa suomalaisessa yleisessä tuomioistuimessa. Ensimmäisenä oikeusasteena toimii Helsingin käräjäoikeus, ellei pakottavasta lainsäädännöstä muuta johdu.

## 22. Ehtojen ensisijaisuus

Jos näiden Ehtojen ja Asiakkaan kanssa erikseen kirjallisesti sovittujen sopimusehtojen välillä on ristiriita, erikseen kirjallisesti sovitut ehdot ovat ensisijaisia.

Palvelun tilauksen yhteydessä ilmoitetut palvelukohtaiset ehdot, palvelukuvaus ja hinnat täydentävät näitä Ehtoja.

Edellä mainittu ensisijaisuus koskee Palveluntarjoajan ylläpitämän palvelun toimittamista. Tekraportti-ohjelmiston kopioimiseen, muuttamiseen ja levittämiseen sovelletaan aina AGPLv3-lisenssiä, eikä sopimusehdoilla aseteta lisenssin myöntämille oikeuksille AGPLv3:n vastaisia lisärajoituksia.

## 23. Yhteystiedot

Palvelua ja näitä Ehtoja koskevissa kysymyksissä yhteydenotot:

**Helsingin Kiinteistömanagerit Oy / Koodiportti**
Y-tunnus: 3203383-5
Palvelu: **tekraportti.com**
TEXT;

?><!doctype html>
<html lang="fi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Käyttö- ja sopimusehdot – TEKRAPORTTI</title>
    <link rel="icon" type="image/png" href="Logo pyöreä.png">
    <style>
        :root { --navy:#071a3d; --blue:#1769e8; --muted:#52627a; }
        body { margin:0; background:#f5f8fc; color:var(--navy); font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        .terms-header { background:#fff; border-bottom:1px solid #dce6f1; padding:22px 28px; }
        .terms-header img { width:250px; max-width:70vw; }
        .terms-main { max-width:920px; margin:38px auto; padding:42px 52px; background:#fff; border:1px solid #dce6f1; border-radius:12px; box-shadow:0 6px 18px #1a34560d; }
        h1 { font-size:2.2rem; margin:0 0 8px; } h2 { margin:34px 0 10px; font-size:1.35rem; } p,li { color:var(--muted); line-height:1.65; } ul { padding-left:25px; }
        .terms-back { color:var(--blue); text-decoration:none; font-weight:600; }
        @media (max-width:600px) { .terms-main { margin:18px 12px; padding:25px 22px; } .terms-header { padding:18px; } }
    </style>
</head>
<body><header class="terms-header"><a href="index.php"><img src="Logo.png" alt="TEKRAPORTTI"></a></header><main class="terms-main"><a class="terms-back" href="index.php">← Takaisin etusivulle</a><?= termsMarkdownToHtml($termsText) ?></main></body>
</html>
