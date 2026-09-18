<?php
/* Jeu d'essai hors ligne du plugin Lampes Soir & Matin.
 *
 *   php tests/run.php
 *
 * Aucune dépendance : ni Jeedom, ni base de données, ni lampe. Les deux classes
 * éprouvées ici — le calcul des moments et la reconnaissance des lampes —
 * ignorent volontairement Jeedom, et c'est précisément ce qui rend ce fichier
 * possible.
 *
 * Ce qu'on vérifie n'est pas décoratif : un plugin de programmation se trompe
 * silencieusement. Un décalage de signe, un garde-fou qui annule au lieu de
 * ramener, un tirage aléatoire relancé à chaque minute — rien de tout cela ne
 * lève d'erreur, et la seule façon de s'en apercevoir en production est de
 * constater, un soir d'hiver, que les lampes ne se sont pas allumées.
 */

date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/../core/class/lampesoirmatinbeSun.class.php';
require_once __DIR__ . '/../core/class/lampesoirmatinbeLamps.class.php';

/* Bruxelles. Toutes les heures attendues ci-dessous en découlent. */
const LAT = 50.8503;
const LON = 4.3517;

$ok = 0;
$ko = 0;

function verifie($_titre, $_obtenu, $_attendu) {
    global $ok, $ko;
    if ($_obtenu == $_attendu) {
        $ok++;
        printf("  %-58s ok\n", $_titre);
        return;
    }
    $ko++;
    printf("  %-58s ÉCHEC : obtenu %s, attendu %s\n", $_titre,
           var_export($_obtenu, true), var_export($_attendu, true));
}

function verifieVrai($_titre, $_condition) {
    verifie($_titre, $_condition ? true : false, true);
}

function heure($_timestamp) {
    return ($_timestamp === null) ? null : date('Y-m-d H:i', $_timestamp);
}

/* ------------------------------------------------------------------ 1 ---
 * Les heures tapées à la main. Un champ vide n'est pas minuit : c'est
 * l'absence de garde-fou, et les confondre poserait un plafond à 00:00 qui
 * ramènerait tous les soirs au milieu de la nuit. */
echo "\nHeures saisies\n";
verifie('07:05 tel quel', lampesoirmatinbeSun::cleanTime('07:05'), '07:05');
verifie('7:05 complété', lampesoirmatinbeSun::cleanTime('7:05'), '07:05');
verifie('7h05 accepté', lampesoirmatinbeSun::cleanTime('7h05'), '07:05');
verifie('vide reste vide', lampesoirmatinbeSun::cleanTime(''), '');
verifie('25:00 refusé', lampesoirmatinbeSun::cleanTime('25:00'), '');
verifie('07:75 refusé', lampesoirmatinbeSun::cleanTime('07:75'), '');
verifie('texte refusé', lampesoirmatinbeSun::cleanTime('le soir'), '');

/* ------------------------------------------------------------------ 2 ---
 * La normalisation d'un moment. Tout ce qui sort d'un formulaire est une
 * chaîne, y compris une case décochée qui n'arrive tout simplement pas. */
echo "\nNormalisation d'un moment\n";
$brut = array('enable' => '1', 'action' => 'off', 'mode' => 'sunset', 'offset' => '-45',
              'random' => '999', 'time' => '20h30', 'not_before' => '', 'days' => array('1', '1', 9, '5'));
$slot = lampesoirmatinbeSun::cleanSlot($brut);
verifie('case cochée devient 1', $slot['enable'], 1);
verifie('décalage devient entier', $slot['offset'], -45);
verifie('aléa borné', $slot['random'], lampesoirmatinbeSun::RANDOM_MAX);
verifie('heure normalisée', $slot['time'], '20:30');
verifie('jours dédoublonnés et bornés', implode(',', $slot['days']), '1,5');
$vide = lampesoirmatinbeSun::cleanSlot(array('days' => array()));
verifie('aucun jour coché = jamais', count($vide['days']), 0);
$absent = lampesoirmatinbeSun::cleanSlot(array('action' => 'on'));
verifie('case absente devient 0', $absent['enable'], 0);
verifie('mode inconnu retombe sur heure fixe',
        lampesoirmatinbeSun::cleanSlot(array('mode' => 'lune'))['mode'], 'fixed');

/* ------------------------------------------------------------------ 3 ---
 * Le tirage aléatoire. Il doit être stable sur la journée, sinon l'heure
 * annoncée dans l'interface n'est pas celle qui sera jouée, et le moment part
 * au premier tirage qui tombe dans le passé. */
echo "\nTirage aléatoire\n";
$a = lampesoirmatinbeSun::randomOffset(20, 'salon|evening|2026-01-15');
$b = lampesoirmatinbeSun::randomOffset(20, 'salon|evening|2026-01-15');
verifie('deux appels, même valeur', $a, $b);
verifieVrai('dans les bornes', $a >= -20 && $a <= 20);
verifieVrai('le lendemain, autre valeur',
    lampesoirmatinbeSun::randomOffset(20, 'salon|evening|2026-01-16') !== $a
    || lampesoirmatinbeSun::randomOffset(20, 'salon|evening|2026-01-17') !== $a);
verifie('zéro ne tire rien', lampesoirmatinbeSun::randomOffset(0, 'peu importe'), 0);
$ecart = array();
for ($jour = 1; $jour <= 60; $jour++) {
    $ecart[] = lampesoirmatinbeSun::randomOffset(30, 'salon|evening|2026-03-' . $jour);
}
verifieVrai('60 jours, des valeurs différentes', count(array_unique($ecart)) > 10);

/* ------------------------------------------------------------------ 4 ---
 * Le soleil de Bruxelles. Les valeurs attendues sont celles publiées pour la
 * ville, à la minute près ; on tolère cinq minutes, l'algorithme de PHP ne
 * prétend pas à mieux. */
echo "\nSoleil à Bruxelles\n";
$ete = lampesoirmatinbeSun::sun(mktime(0, 0, 0, 6, 21, 2026), LAT, LON);
$hiver = lampesoirmatinbeSun::sun(mktime(0, 0, 0, 12, 21, 2026), LAT, LON);
verifieVrai('21 juin, coucher vers 22:00', abs($ete['sunset'] - strtotime('2026-06-21 22:00')) < 600);
verifieVrai('21 juin, lever vers 05:30', abs($ete['sunrise'] - strtotime('2026-06-21 05:30')) < 600);
verifieVrai('21 décembre, coucher vers 16:40', abs($hiver['sunset'] - strtotime('2026-12-21 16:40')) < 600);
verifieVrai('21 décembre, lever vers 08:44', abs($hiver['sunrise'] - strtotime('2026-12-21 08:44')) < 600);
/* Le calcul ne doit pas dépendre de l'heure à laquelle on le demande : c'est
 * l'erreur qui ferait basculer un plugin sur le lendemain passé minuit. */
$matin = lampesoirmatinbeSun::sun(strtotime('2026-06-21 00:10'), LAT, LON);
$soir  = lampesoirmatinbeSun::sun(strtotime('2026-06-21 23:50'), LAT, LON);
verifie('même jour, même coucher', $matin['sunset'], $soir['sunset']);
/* Au pôle, le soleil ne se couche pas : null, et surtout pas minuit. */
$pole = lampesoirmatinbeSun::sun(mktime(0, 0, 0, 6, 21, 2026), 78.2, 15.6);
verifie('Svalbard en juin, pas de coucher', $pole['sunset'], null);

/* ------------------------------------------------------------------ 5 ---
 * Un moment, un jour. */
echo "\nCalcul d'un moment\n";
$jour = mktime(0, 0, 0, 12, 21, 2026); // un lundi
$fixe = lampesoirmatinbeSun::cleanSlot(array('enable' => 1, 'mode' => 'fixed', 'time' => '20:00'));
verifie('heure fixe', heure(lampesoirmatinbeSun::occurrence($fixe, $jour, LAT, LON)), '2026-12-21 20:00');

$avant = lampesoirmatinbeSun::cleanSlot(array('enable' => 1, 'mode' => 'sunset', 'offset' => -30));
verifieVrai('30 min avant le coucher',
    abs(lampesoirmatinbeSun::occurrence($avant, $jour, LAT, LON) - ($hiver['sunset'] - 1800)) < 60);

$apres = lampesoirmatinbeSun::cleanSlot(array('enable' => 1, 'mode' => 'sunrise', 'offset' => 45));
verifieVrai('45 min après le lever',
    abs(lampesoirmatinbeSun::occurrence($apres, $jour, LAT, LON) - ($hiver['sunrise'] + 2700)) < 60);

$mardi = lampesoirmatinbeSun::cleanSlot(array('enable' => 1, 'mode' => 'fixed', 'time' => '20:00', 'days' => array(2)));
verifie('jour non coché : rien', lampesoirmatinbeSun::occurrence($mardi, $jour, LAT, LON), null);

/* Le garde-fou ramène, il n'annule pas : en décembre le soleil se couche à
 * 16 h 40, « pas avant 17:30 » doit donner 17:30 et non rien du tout. */
$garde = lampesoirmatinbeSun::cleanSlot(array('enable' => 1, 'mode' => 'sunset', 'offset' => -30, 'not_before' => '17:30'));
verifie('plancher en hiver', heure(lampesoirmatinbeSun::occurrence($garde, $jour, LAT, LON)), '2026-12-21 17:30');
$juin = mktime(0, 0, 0, 6, 21, 2026);
verifieVrai('plancher sans effet en juin',
    lampesoirmatinbeSun::occurrence($garde, $juin, LAT, LON) > strtotime('2026-06-21 20:00'));
$plafond = lampesoirmatinbeSun::cleanSlot(array('enable' => 1, 'mode' => 'sunrise', 'offset' => 0, 'not_after' => '07:00'));
verifie('plafond en hiver', heure(lampesoirmatinbeSun::occurrence($plafond, $jour, LAT, LON)), '2026-12-21 07:00');

/* Le changement d'heure : le dernier dimanche de mars, 2 h devient 3 h. Une
 * heure fixe doit rester l'heure du mur, pas glisser d'une heure. */
$printemps = lampesoirmatinbeSun::occurrence($fixe, mktime(0, 0, 0, 3, 29, 2026), LAT, LON);
verifie('heure fixe le jour du changement d\'heure', heure($printemps), '2026-03-29 20:00');

/* ------------------------------------------------------------------ 6 ---
 * Les prochaines occurrences. */
echo "\nProchaines occurrences\n";
$now = strtotime('2026-12-21 18:00');
$suite = lampesoirmatinbeSun::nextOccurrences($fixe, $now, LAT, LON, 'test', 3);
verifie('trois dates rendues', count($suite), 3);
verifie('la première est ce soir', heure($suite[0]), '2026-12-21 20:00');
verifieVrai('elles sont croissantes', $suite[0] < $suite[1] && $suite[1] < $suite[2]);
verifieVrai('toutes dans le futur', $suite[0] > $now);

$weekend = lampesoirmatinbeSun::cleanSlot(array('enable' => 1, 'mode' => 'fixed', 'time' => '09:00', 'days' => array(6, 7)));
$suite = lampesoirmatinbeSun::nextOccurrences($weekend, $now, LAT, LON, 'test', 2);
verifie('samedi d\'abord', date('N', $suite[0]), '6');
verifie('puis dimanche', date('N', $suite[1]), '7');

$eteint = lampesoirmatinbeSun::cleanSlot(array('enable' => 0, 'mode' => 'fixed', 'time' => '09:00'));
verifie('moment désactivé : aucune date', count(lampesoirmatinbeSun::nextOccurrences($eteint, $now, LAT, LON)), 0);

/* Un moment tardif appartient au jour de base, et se retrouve pourtant après
 * minuit : il doit être vu comme à venir quand on est déjà le lendemain. */
$tardif = lampesoirmatinbeSun::cleanSlot(array('enable' => 1, 'mode' => 'sunset', 'offset' => 480));
$suite = lampesoirmatinbeSun::nextOccurrences($tardif, strtotime('2026-12-22 00:10'), LAT, LON, 'test', 1);
verifie('le moment de la veille compte encore', date('Y-m-d', $suite[0]), '2026-12-22');
verifieVrai('et tombe bien après minuit', (int) date('H', $suite[0]) === 0);

/* ------------------------------------------------------------------ 7 ---
 * Ce qui est dû maintenant. C'est la décision qui allume vraiment. */
echo "\nCe qui est dû\n";
$due = lampesoirmatinbeSun::dueOccurrence($fixe, strtotime('2026-12-21 20:00'), LAT, LON, 'test', 900);
verifie('à la minute pile', heure($due['timestamp']), '2026-12-21 20:00');
verifie('le jour de base est marqué', $due['day'], '2026-12-21');
verifieVrai('dix minutes après, encore dû',
    lampesoirmatinbeSun::dueOccurrence($fixe, strtotime('2026-12-21 20:10'), LAT, LON, 'test', 900) !== null);
verifie('trois heures après, abandonné',
    lampesoirmatinbeSun::dueOccurrence($fixe, strtotime('2026-12-21 23:00'), LAT, LON, 'test', 900), null);
verifie('avant l\'heure, rien',
    lampesoirmatinbeSun::dueOccurrence($fixe, strtotime('2026-12-21 19:59'), LAT, LON, 'test', 900), null);
verifie('moment désactivé, rien',
    lampesoirmatinbeSun::dueOccurrence($eteint, strtotime('2026-12-21 09:00'), LAT, LON, 'test', 900), null);
/* Le moment tardif de la veille doit être joué après minuit, avec le jour de la
 * veille comme marque : sinon il repartirait le soir même. */
$due = lampesoirmatinbeSun::dueOccurrence($tardif, strtotime('2026-12-22 00:45'), LAT, LON, 'test', 900);
verifie('moment d\'après minuit, marque de la veille', $due['day'], '2026-12-21');

/* ------------------------------------------------------------------ 8 ---
 * Une année entière, jour par jour : aucune date manquante, aucune aberration.
 * C'est le test qui attrape les erreurs de saison — celles qu'on découvrirait
 * six mois plus tard. */
echo "\nUne année de soirs\n";
$slot = lampesoirmatinbeSun::cleanSlot(array('enable' => 1, 'mode' => 'sunset', 'offset' => -15, 'random' => 10));
$manquants = 0;
$horsBornes = 0;
$plusTot = null;
$plusTard = null;
for ($jour = 0; $jour < 365; $jour++) {
    $base = strtotime('2026-01-01 +' . $jour . ' day');
    $timestamp = lampesoirmatinbeSun::occurrence($slot, $base, LAT, LON, 'annee');
    if ($timestamp === null) {
        $manquants++;
        continue;
    }
    if (date('Y-m-d', $timestamp) !== date('Y-m-d', $base)) {
        $horsBornes++;
    }
    $heure = (int) date('H', $timestamp);
    $plusTot = ($plusTot === null) ? $heure : min($plusTot, $heure);
    $plusTard = ($plusTard === null) ? $heure : max($plusTard, $heure);
}
verifie('aucun jour sans soir', $manquants, 0);
verifie('aucun soir hors de son jour', $horsBornes, 0);
verifie('le plus tôt est en décembre, vers 16 h', $plusTot, 16);
verifie('le plus tard est en juin, vers 21 h', $plusTard, 21);

/* ------------------------------------------------------------------ 9 ---
 * La reconnaissance des lampes. */
echo "\nReconnaissance des lampes\n";
$hue = array(
    array('id' => 1, 'name' => 'On', 'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_ON'),
    array('id' => 2, 'name' => 'Off', 'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_OFF'),
    array('id' => 3, 'name' => 'Etat', 'type' => 'info', 'subType' => 'binary', 'generic' => 'LIGHT_STATE'),
);
$lampe = lampesoirmatinbeLamps::classify('Lampadaire', $hue, 'Salon');
verifie('lampe sûre', $lampe['confidence'], lampesoirmatinbeLamps::LIGHT);
verifie('commande d\'allumage retenue', $lampe['on'], 1);
verifie('commande d\'extinction retenue', $lampe['off'], 2);
verifie('commande d\'état retenue', $lampe['state'], 3);

$prise = array(
    array('id' => 8, 'name' => 'On', 'type' => 'action', 'subType' => 'other', 'generic' => 'ENERGY_ON'),
    array('id' => 9, 'name' => 'Off', 'type' => 'action', 'subType' => 'other', 'generic' => 'ENERGY_OFF'),
);
verifie('prise reconnue comme prise',
        lampesoirmatinbeLamps::classify('Prise congélateur', $prise)['confidence'], lampesoirmatinbeLamps::PLUG);
verifie('prise nommée « lampe » remonte',
        lampesoirmatinbeLamps::classify('Prise lampe de chevet', $prise)['hinted'], 1);
verifie('prise nommée autrement ne remonte pas',
        lampesoirmatinbeLamps::classify('Prise congélateur', $prise)['hinted'], 0);
verifie('nom de la pièce pris en compte',
        lampesoirmatinbeLamps::classify('Module 3', $prise, 'Éclairage jardin')['hinted'], 1);

$vieux = array(
    array('id' => 20, 'name' => 'Allumer', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
    array('id' => 21, 'name' => 'Éteindre', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
);
$devine = lampesoirmatinbeLamps::classify('Vieux module', $vieux);
verifie('reconnu au nom', $devine['confidence'], lampesoirmatinbeLamps::GUESS);
verifie('allumage trouvé au nom', $devine['on'], 20);
verifie('extinction trouvée au nom', $devine['off'], 21);

$capteur = array(
    array('id' => 30, 'name' => 'Température', 'type' => 'info', 'subType' => 'numeric', 'generic' => 'TEMPERATURE'),
    array('id' => 31, 'name' => 'On', 'type' => 'info', 'subType' => 'binary', 'generic' => ''),
);
verifie('un capteur n\'est pas une lampe', lampesoirmatinbeLamps::classify('Sonde salon', $capteur), null);

$volet = array(
    array('id' => 40, 'name' => 'Monter', 'type' => 'action', 'subType' => 'other', 'generic' => 'FLAP_UP'),
    array('id' => 41, 'name' => 'Descendre', 'type' => 'action', 'subType' => 'other', 'generic' => 'FLAP_DOWN'),
);
verifie('un volet n\'est pas une lampe', lampesoirmatinbeLamps::classify('Volet salon', $volet), null);

$bascule = array(
    array('id' => 50, 'name' => 'Basculer', 'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_TOGGLE'),
);
$inter = lampesoirmatinbeLamps::classify('Interrupteur couloir', $bascule);
verifie('une bascule seule suffit', $inter['toggle'], 50);
verifie('et reste une lampe sûre', $inter['confidence'], lampesoirmatinbeLamps::LIGHT);

/* Les noms tels qu'ils arrivent vraiment : « Lampe du salon » d'un côté,
 * « spotcuisineplafond » de l'autre, tel qu'un identifiant MQTT le donne. Et
 * « salle de bain », qui contient « led » à la lettre près sans rien éclairer. */
echo "\nNoms qui parlent d'éclairage\n";
verifieVrai('« Lampe salon »', lampesoirmatinbeLamps::looksLikeLight('Lampe salon'));
verifieVrai('« lampesalon » collé', lampesoirmatinbeLamps::looksLikeLight('lampesalon'));
verifieVrai('« spotcuisineplafond »', lampesoirmatinbeLamps::looksLikeLight('Module 1 — spotcuisineplafond'));
verifieVrai('« projecteurlednord »', lampesoirmatinbeLamps::looksLikeLight('Module 2 — projecteurlednord'));
verifieVrai('« Spot LED cuisine »', lampesoirmatinbeLamps::looksLikeLight('Spot LED cuisine'));
verifie('« sondesalledebain » n\'en est pas',
        lampesoirmatinbeLamps::looksLikeLight('Module 3 — sondesalledebain'), false);
verifie('« Chaudiere » n\'en est pas', lampesoirmatinbeLamps::looksLikeLight('Chaudiere'), false);
verifie('un nom vide n\'en est pas', lampesoirmatinbeLamps::looksLikeLight(''), false);

/* Les noms composés d'un plugin tiers : l'ordre est le dernier mot, et le reste
 * du nom dit qu'il s'agit d'un éclairage. Les deux conditions comptent : sans la
 * première, « Lumière salon » passerait pour un allumage ; sans la seconde, une
 * sirène deviendrait une lampe. */
echo "\nOrdres en fin de nom\n";
$camera = array(
    array('id' => 71, 'name' => 'Aller au preset', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
    array('id' => 72, 'name' => 'Lumière blanche ON', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
    array('id' => 73, 'name' => 'Lumière blanche OFF', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
);
$trouve = lampesoirmatinbeLamps::classify('Caméra extérieure', $camera);
verifie('« Lumière blanche ON » allume', $trouve['on'], 72);
verifie('« Lumière blanche OFF » éteint', $trouve['off'], 73);
verifie('et reste une reconnaissance au nom', $trouve['confidence'], lampesoirmatinbeLamps::GUESS);

$sirene = array(
    array('id' => 81, 'name' => 'Sortie alarme ON', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
    array('id' => 82, 'name' => 'Sortie alarme OFF', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
);
verifie('une sortie d\'alarme n\'est pas une lampe', lampesoirmatinbeLamps::classify('Centrale d\'alarme', $sirene), null);

$homonyme = array(
    array('id' => 91, 'name' => 'Lumiere salon', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
);
verifie('« Lumiere salon » ne finit pas par un ordre',
        lampesoirmatinbeLamps::classify('Module', $homonyme), null);

/* Le type générique l'emporte sur le nom : une commande nommée « On » qui n'est
 * pas l'allumage ne doit pas voler la place. */
$piege = array(
    array('id' => 60, 'name' => 'On', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
    array('id' => 61, 'name' => 'Allumer la veilleuse', 'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_ON'),
    array('id' => 62, 'name' => 'Off', 'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_OFF'),
);
verifie('le type générique prime sur le nom', lampesoirmatinbeLamps::classify('Lampe', $piege)['on'], 61);

/* ----------------------------------------------------------------- 10 ---
 * L'état d'un groupe à partir de celui de ses lampes.
 *
 * Le cas qui compte est le dernier : un groupe dont aucune lampe ne publie son
 * état ne doit pas être déclaré éteint, sinon la tuile afficherait « éteint »
 * en permanence pour tous les modules 433 MHz, et le dernier ordre du plugin —
 * la seule chose que l'on sache — serait perdu. */
echo "\nÉtat d'un groupe\n";
verifie('une allumée suffit', lampesoirmatinbeLamps::aggregateState(array(0, 0, 1)), 1);
verifie('toutes éteintes', lampesoirmatinbeLamps::aggregateState(array(0, 0, 0)), 0);
verifie('une seule lampe allumée', lampesoirmatinbeLamps::aggregateState(array(1)), 1);
verifie('les inconnues ne comptent pas', lampesoirmatinbeLamps::aggregateState(array(null, 0)), 0);
verifie('une connue allumée parmi des inconnues', lampesoirmatinbeLamps::aggregateState(array(null, 1, null)), 1);
verifie('aucune ne se prononce : on ne sait pas', lampesoirmatinbeLamps::aggregateState(array(null, null)), null);
verifie('groupe vide : on ne sait pas', lampesoirmatinbeLamps::aggregateState(array()), null);

/* ---------------------------------------------------------------- BILAN --- */
echo "\n";
if ($ko == 0) {
    echo "Tous les contrôles passent ($ok).\n";
    exit(0);
}
echo "$ok contrôle(s) passé(s), $ko en échec.\n";
exit(1);
