<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * Quand un moment tombe.
 *
 * Cette classe ne connaît ni Jeedom, ni base de données, ni commande : elle
 * reçoit un réglage et une position, elle rend un horodatage. C'est délibéré.
 * Toute la subtilité du plugin est là — un décalage sur le coucher du soleil,
 * un garde-fou, un tirage aléatoire, un jour de semaine — et c'est la seule
 * partie qu'on peut éprouver hors ligne, sur une année entière, en une seconde
 * (voir tests/run.php). Le reste du plugin ne fait qu'appeler ces fonctions et
 * pousser des ordres aux lampes.
 */
class lampesoirmatinbeSun {

    const MODE_FIXED   = 'fixed';
    const MODE_SUNSET  = 'sunset';
    const MODE_SUNRISE = 'sunrise';

    /* Bornes d'un décalage. Douze heures suffisent largement à tout usage réel
     * et empêchent un réglage absurde — « coucher du soleil + 2000 minutes » —
     * de déclencher un ordre deux jours plus tard sans que personne comprenne. */
    const OFFSET_MAX = 720;

    /* Bornes du tirage aléatoire de simulation de présence. */
    const RANDOM_MAX = 120;

    /* Un réglage vide, tel qu'un équipement neuf le reçoit. */
    public static function emptySlot($_action = 'on') {
        return array(
            'enable'     => 0,
            'action'     => ($_action == 'off') ? 'off' : 'on',
            'mode'       => self::MODE_FIXED,
            'time'       => ($_action == 'off') ? '07:00' : '19:00',
            'offset'     => 0,
            'random'     => 0,
            'not_before' => '',
            'not_after'  => '',
            'days'       => array(1, 2, 3, 4, 5, 6, 7),
        );
    }

    /*
     * Impose sa forme à un réglage venu du formulaire.
     *
     * Tout ce qui sort d'une page web est une chaîne, y compris « 0 » et « »,
     * et une comparaison faite plus tard sur une case à cocher absente serait
     * fausse sans bruit : le moment ne partirait jamais et rien ne le dirait.
     * On normalise donc une fois pour toutes, à l'enregistrement.
     */
    public static function cleanSlot($_slot, $_action = 'on') {
        $clean = self::emptySlot($_action);
        if (!is_array($_slot)) {
            return $clean;
        }

        $clean['enable'] = (isset($_slot['enable']) && ($_slot['enable'] == 1 || $_slot['enable'] === true || $_slot['enable'] === 'on')) ? 1 : 0;
        if (isset($_slot['action']) && $_slot['action'] == 'off') {
            $clean['action'] = 'off';
        } elseif (isset($_slot['action']) && $_slot['action'] == 'on') {
            $clean['action'] = 'on';
        }
        if (isset($_slot['mode']) && in_array($_slot['mode'], array(self::MODE_FIXED, self::MODE_SUNSET, self::MODE_SUNRISE))) {
            $clean['mode'] = $_slot['mode'];
        }
        $time = self::cleanTime(isset($_slot['time']) ? $_slot['time'] : '');
        if ($time !== '') {
            $clean['time'] = $time;
        }
        if (isset($_slot['offset'])) {
            $clean['offset'] = max(-self::OFFSET_MAX, min(self::OFFSET_MAX, (int) $_slot['offset']));
        }
        if (isset($_slot['random'])) {
            $clean['random'] = max(0, min(self::RANDOM_MAX, (int) $_slot['random']));
        }
        $clean['not_before'] = self::cleanTime(isset($_slot['not_before']) ? $_slot['not_before'] : '');
        $clean['not_after']  = self::cleanTime(isset($_slot['not_after']) ? $_slot['not_after'] : '');

        if (isset($_slot['days']) && is_array($_slot['days'])) {
            $days = array();
            foreach ($_slot['days'] as $day) {
                $day = (int) $day;
                if ($day >= 1 && $day <= 7 && !in_array($day, $days)) {
                    $days[] = $day;
                }
            }
            sort($days);
            /*
             * Aucun jour coché veut dire « jamais », pas « tous les jours » :
             * l'inverse ferait allumer les lampes sept soirs par semaine à
             * quelqu'un qui vient de tout décocher pour suspendre le moment.
             */
            $clean['days'] = $days;
        }
        return $clean;
    }

    /* « 7:5 », « 07h05 », « 0705 » — tout ce qu'un humain tape pour une heure,
     * ramené à HH:MM, ou vide si ce n'en est pas une. */
    public static function cleanTime($_value) {
        $value = trim((string) $_value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^(\d{1,2})[:hH.]?(\d{2})$/', $value, $matches)) {
            return '';
        }
        $hours   = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours > 23 || $minutes > 59) {
            return '';
        }
        return sprintf('%02d:%02d', $hours, $minutes);
    }

    /*
     * Lever et coucher du soleil pour le jour d'un horodatage.
     *
     * date_sun_info() est dans PHP depuis la version 5.1 : aucune dépendance à
     * installer, aucun service à interroger, et le résultat est le même que
     * celui qu'affiche le coeur de Jeedom dans ses scénarios, qui appelle la
     * même fonction avec la même position.
     *
     * Elle rend true ou false, et non un horodatage, quand le soleil ne se lève
     * ou ne se couche pas du jour — cercle polaire. Les deux valeurs sont
     * rendues telles quelles à l'appelant sous forme de null : un moment qui
     * n'existe pas ne doit pas se replier sur minuit.
     */
    public static function sun($_timestamp, $_latitude, $_longitude) {
        /* Midi et non l'heure reçue : à 23 h 30, le « coucher du soleil » que
         * date_sun_info rend pour l'instant présent est celui du jour en cours,
         * mais un appel fait à 00 h 10 porterait déjà sur le lendemain. Partir
         * de midi rend le résultat indépendant de l'heure d'appel. */
        $noon = mktime(12, 0, 0, (int) date('n', $_timestamp), (int) date('j', $_timestamp), (int) date('Y', $_timestamp));
        $info = @date_sun_info($noon, (float) $_latitude, (float) $_longitude);

        $result = array('sunrise' => null, 'sunset' => null);
        if (!is_array($info)) {
            return $result;
        }
        foreach (array('sunrise', 'sunset') as $key) {
            if (isset($info[$key]) && !is_bool($info[$key])) {
                $result[$key] = (int) $info[$key];
            }
        }
        return $result;
    }

    /*
     * L'horodatage du moment pour un jour donné, ou null s'il n'y a rien ce
     * jour-là : jour de semaine décoché, ou soleil qui ne se couche pas.
     *
     * $_seed distingue les tirages aléatoires de deux moments d'un même jour :
     * sans lui, le soir et le matin d'un même équipement se décaleraient de la
     * même durée, ce qui n'est pas aléatoire mais décalé.
     */
    public static function occurrence($_slot, $_dayTimestamp, $_latitude, $_longitude, $_seed = '') {
        $slot = self::cleanSlot($_slot);
        $day  = date('Y-m-d', $_dayTimestamp);

        /*
         * Le jour de semaine est celui du jour de base, pas celui de l'ordre
         * final : « lundi, coucher du soleil + 5 h » reste le programme du lundi
         * même si l'ordre part le mardi à 1 h du matin. C'est ainsi que
         * l'utilisateur l'a pensé en cochant la case.
         */
        if (count($slot['days']) > 0 && !in_array((int) date('N', $_dayTimestamp), $slot['days'])) {
            return null;
        }

        if ($slot['mode'] == self::MODE_FIXED) {
            $timestamp = strtotime($day . ' ' . $slot['time']);
            if ($timestamp === false) {
                return null;
            }
        } else {
            $sun = self::sun($_dayTimestamp, $_latitude, $_longitude);
            if ($sun[$slot['mode']] === null) {
                return null;
            }
            $timestamp = $sun[$slot['mode']] + $slot['offset'] * 60;
        }

        $timestamp += self::randomOffset($slot['random'], $_seed . '|' . $day) * 60;

        /*
         * Les garde-fous ramènent le moment dans la fenêtre, ils ne l'annulent
         * pas. « Jamais avant 17 h » veut dire « pas avant 17 h », donc à 17 h :
         * en décembre, en Belgique, le soleil se couche à 16 h 30 et un
         * garde-fou qui annulerait laisserait la maison dans le noir tout
         * l'hiver — l'exact contraire de ce qu'on cherchait en le posant.
         */
        $timestamp = self::clamp($timestamp, $day, $slot['not_before'], $slot['not_after']);
        return $timestamp;
    }

    /* Ramène un horodatage entre les deux bornes du jour, quand elles existent. */
    public static function clamp($_timestamp, $_day, $_notBefore, $_notAfter) {
        if ($_notBefore !== '') {
            $floor = strtotime($_day . ' ' . $_notBefore);
            if ($floor !== false && $_timestamp < $floor) {
                $_timestamp = $floor;
            }
        }
        if ($_notAfter !== '') {
            $ceiling = strtotime($_day . ' ' . $_notAfter);
            if ($ceiling !== false && $_timestamp > $ceiling) {
                $_timestamp = $ceiling;
            }
        }
        return $_timestamp;
    }

    /*
     * Décalage aléatoire, en minutes, mais tiré une fois pour toutes pour un
     * jour donné.
     *
     * rand() ne conviendrait pas : le cron repasse toutes les minutes et
     * retirerait un nombre différent à chaque passage. L'heure affichée dans
     * l'interface changerait sans arrêt, et le moment partirait au premier
     * tirage qui tombe dans le passé, c'est-à-dire presque toujours le plus tôt
     * possible. Une empreinte de la graine et du jour donne au contraire une
     * valeur stable de minuit à minuit, et différente le lendemain.
     */
    public static function randomOffset($_random, $_seed) {
        $random = (int) $_random;
        if ($random <= 0) {
            return 0;
        }
        return (int) (crc32($_seed) % (2 * $random + 1)) - $random;
    }

    /*
     * Les prochains horodatages d'un moment, à partir de maintenant.
     *
     * L'horizon vaut huit jours par défaut : un moment qui n'a qu'un seul jour
     * de semaine coché doit rester annonçable toute la semaine qui précède.
     */
    public static function nextOccurrences($_slot, $_now, $_latitude, $_longitude, $_seed = '', $_count = 1, $_horizon = 8) {
        $slot = self::cleanSlot($_slot);
        $found = array();
        if ($slot['enable'] != 1) {
            return $found;
        }

        /*
         * On commence la veille : un moment réglé sur « coucher du soleil
         * + 6 h » appartient au jour d'hier et tombe pourtant après minuit,
         * c'est-à-dire dans l'avenir vu d'ici.
         */
        for ($day = -1; $day <= $_horizon; $day++) {
            $timestamp = self::occurrence($slot, strtotime($day . ' day', $_now), $_latitude, $_longitude, $_seed);
            if ($timestamp === null || $timestamp <= $_now) {
                continue;
            }
            $found[] = $timestamp;
            if (count($found) >= $_count) {
                break;
            }
        }
        sort($found);
        return $found;
    }

    /*
     * Le moment à jouer maintenant, s'il y en a un : celui qui est passé depuis
     * moins que le délai de grâce et qui n'a pas encore été joué.
     *
     * Rend array('timestamp' => ..., 'day' => 'Y-m-d') — le jour de base, qui
     * sert de marque d'exécution : deux passages du cron dans la même minute ne
     * doivent pas allumer deux fois, et une box rallumée le lendemain ne doit
     * pas rejouer le soir de la veille.
     *
     * Le délai de grâce existe pour les coupures de courant et les crons en
     * retard : un ordre manqué de dix minutes a encore tout son sens, un ordre
     * manqué de six heures n'en a plus aucun — allumer les lampes du soir à
     * trois heures du matin réveille la maison au lieu de la servir.
     */
    public static function dueOccurrence($_slot, $_now, $_latitude, $_longitude, $_seed = '', $_grace = 900) {
        $slot = self::cleanSlot($_slot);
        if ($slot['enable'] != 1) {
            return null;
        }
        $best = null;

        /* Deux jours en arrière : un décalage négatif important sur le lever du
         * soleil peut ramener le moment du jour dans la nuit précédente. */
        for ($day = -2; $day <= 0; $day++) {
            $base = strtotime($day . ' day', $_now);
            $timestamp = self::occurrence($slot, $base, $_latitude, $_longitude, $_seed);
            if ($timestamp === null || $timestamp > $_now || ($_now - $timestamp) > $_grace) {
                continue;
            }
            /* Le plus récent gagne : si deux jours de base produisent tous deux
             * un moment jouable, c'est le dernier qui reflète l'intention. */
            if ($best === null || $timestamp > $best['timestamp']) {
                $best = array('timestamp' => $timestamp, 'day' => date('Y-m-d', $base));
            }
        }
        return $best;
    }
}
