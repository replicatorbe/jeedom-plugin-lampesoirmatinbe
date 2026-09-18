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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/lampesoirmatinbeSun.class.php';
require_once __DIR__ . '/lampesoirmatinbeLamps.class.php';

/*
 * Un équipement = un groupe de lampes et deux moments : le soir et le matin.
 *
 * Ce découpage est le plugin tout entier. On aurait pu faire un équipement par
 * ordre — « allumage du salon », « extinction du salon » — mais il faudrait
 * alors choisir deux fois les mêmes lampes, et les deux moitiés d'une même
 * intention pourraient diverger sans qu'on le voie. Un groupe, deux moments :
 * ce que l'utilisateur allume le soir est exactement ce qu'il éteint le matin,
 * par construction.
 *
 * Aucun démon, aucune dépendance, aucun appel réseau : le cron du coeur passe
 * chaque minute, l'heure du soleil se calcule en PHP, et un ordre est une
 * commande d'action jouée sur la lampe de quelqu'un d'autre.
 */
class lampesoirmatinbe extends eqLogic {

    /* Les deux moments, dans l'ordre où ils se lisent dans la journée. Les noms
     * sont ceux de la configuration, l'interface les appelle « Le soir » et
     * « Le matin ». */
    const SLOTS = array('evening', 'morning');

    /* Retard au-delà duquel un ordre manqué n'est plus joué. Une box redémarrée
     * à 3 h du matin ne doit pas rattraper l'allumage de 20 h. Réglable dans la
     * configuration du plugin. */
    const DEFAULT_GRACE_MINUTES = 15;

    /* Combien de temps on se souvient d'avoir joué un moment. Deux jours
     * suffisent : la marque ne sert qu'à ne pas rejouer le même jour. */
    const DONE_MEMORY = 259200;

    /* Jours et mois en toutes lettres : IntlDateFormatter n'est pas garanti
     * présent sur toutes les installations Jeedom. */
    public static $_days = array(1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche');

    /* ==================================================================== CRON */

    /*
     * Chaque minute, et c'est nécessaire : un utilisateur qui écrit 20:07 attend
     * 20 h 07, pas 20 h 10. Le coût est nul — pour un équipement sans moment
     * actif, le passage se réduit à deux comparaisons d'entiers.
     */
    public static function cron() {
        $now = time();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                $eqLogic->runSchedule($now);
            } catch (Throwable $e) {
                // Un groupe en échec ne doit pas priver les autres de leur soirée.
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /*
     * Une fois par jour : l'avertissement de position, posé ou retiré.
     *
     * Le message du centre de messages ne s'efface pas tout seul. Sans ce
     * passage, celui qui renseigne enfin sa latitude garderait l'avertissement
     * sous les yeux indéfiniment, et finirait par le fermer à la main — ce qui
     * le ferait disparaître même le jour où il redeviendrait vrai.
     *
     * Une fois par jour et non chaque minute : la position d'une maison ne
     * bouge pas, et le contrôle coûte une requête.
     */
    public static function cronDaily() {
        self::checkPosition();
    }

    public static function checkPosition() {
        if (self::hasPosition()) {
            message::removeAll(__CLASS__, 'lampesoirmatinbe::position');
            return;
        }
        message::add(__CLASS__,
            __('Renseignez la position de votre installation pour que les heures de lever et de coucher du soleil soient justes.', __FILE__),
            '', 'lampesoirmatinbe::position');
    }

    /* =============================================================== POSITION */

    /*
     * La position de l'installation, celle des réglages généraux de Jeedom.
     *
     * C'est la même que celle dont le coeur se sert pour #sunrise# et #sunset#
     * dans les scénarios : les heures affichées par le plugin et celles des
     * scénarios de l'utilisateur ne peuvent donc pas diverger.
     */
    public static function position() {
        return array(
            'latitude'  => (float) config::byKey('info::latitude'),
            'longitude' => (float) config::byKey('info::longitude'),
        );
    }

    /* Une position vide donne les heures de soleil du golfe de Guinée, sans
     * qu'aucune erreur ne soit levée : les lampes s'allumeraient tous les jours
     * à la même heure et personne ne comprendrait pourquoi. */
    public static function hasPosition() {
        $position = self::position();
        return ($position['latitude'] != 0 || $position['longitude'] != 0);
    }

    public static function graceSeconds() {
        return max(1, (int) config::byKey('grace_minutes', __CLASS__, self::DEFAULT_GRACE_MINUTES)) * 60;
    }

    /* ===================================================== CYCLE DE VIE eqLogic */

    public function preSave() {
        /*
         * Les deux moments arrivent du formulaire tels que le JS les a ramassés :
         * c'est ici, et pas à l'exécution, qu'on leur impose une forme. Une case
         * à cocher absente vaut « 0 » et non « clé manquante », un décalage vaut
         * un entier et non « 30 » entre guillemets.
         */
        foreach (self::SLOTS as $key) {
            $this->setConfiguration($key, lampesoirmatinbeSun::cleanSlot(
                $this->getConfiguration($key),
                ($key == 'evening') ? 'on' : 'off'
            ));
        }
        $this->setConfiguration('lamps', self::cleanLamps($this->getConfiguration('lamps')));

        /* La tuile porte une ligne de texte — « demain 07:12 » — que 230 px
         * coupent en deux. L'utilisateur reste libre de la redimensionner. */
        if ($this->getDisplay('width') == '') {
            $this->setDisplay('width', '280px');
        }

        /*
         * Aucune exception ici, même sans lampe et sans moment : le coeur crée
         * l'équipement avec son seul nom, et toute validation rendrait le bouton
         * « Ajouter » définitivement inopérant. Un groupe incomplet se signale
         * dans l'interface, pas en refusant d'exister.
         */
    }

    /*
     * Impose sa forme à la liste des lampes.
     *
     * Une lampe est enregistrée par l'identifiant de son équipement et ceux de
     * ses commandes : un renommage de la lampe ou de la pièce ne casse donc
     * rien, et le nom conservé ici n'est qu'un souvenir d'affichage, rafraîchi à
     * chaque ouverture de la page.
     */
    public static function cleanLamps($_lamps) {
        $clean = array();
        if (!is_array($_lamps)) {
            return $clean;
        }
        $seen = array();
        foreach ($_lamps as $lamp) {
            if (!is_array($lamp) || !isset($lamp['eq'])) {
                continue;
            }
            $eq = (int) $lamp['eq'];
            /* Deux fois la même lampe dans un groupe, c'est deux ordres
             * identiques envoyés coup sur coup : certains protocoles n'aiment
             * pas, et l'utilisateur n'y gagne rien. */
            if ($eq <= 0 || isset($seen[$eq])) {
                continue;
            }
            $seen[$eq] = true;
            $clean[] = array(
                'eq'     => $eq,
                'name'   => isset($lamp['name']) ? (string) $lamp['name'] : '',
                'object' => isset($lamp['object']) ? (string) $lamp['object'] : '',
                'on'     => isset($lamp['on']) && $lamp['on'] !== '' ? (int) $lamp['on'] : null,
                'off'    => isset($lamp['off']) && $lamp['off'] !== '' ? (int) $lamp['off'] : null,
                'toggle' => isset($lamp['toggle']) && $lamp['toggle'] !== '' ? (int) $lamp['toggle'] : null,
                'state'  => isset($lamp['state']) && $lamp['state'] !== '' ? (int) $lamp['state'] : null,
            );
        }
        return $clean;
    }

    public function postSave() {
        $this->createCommands();
        try {
            $this->refreshInfo(time());
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    public function preRemove() {
        foreach (self::SLOTS as $key) {
            cache::delete($this->doneKey($key));
        }
        message::removeAll(__CLASS__, $this->failureKey());
    }

    /* ============================================================== COMMANDES */

    /*
     * Huit commandes, dont trois visibles.
     *
     * Le reste sert aux scénarios et à l'historique, et reste masqué : une tuile
     * de tableau de bord qui empile huit widgets ne se lit plus. L'utilisateur
     * réaffiche ce qu'il veut, c'est une décision qui lui appartient — mais elle
     * ne doit pas lui être imposée à l'installation.
     */
    public function createCommands() {
        $order = 0;
        $definitions = array(
            array('logicalId' => 'next', 'name' => __('Prochain changement', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 1, 'icon' => 'fas fa-clock'),
            array('logicalId' => 'on', 'name' => __('Allumer', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_ON',
                  'visible' => 1, 'icon' => 'fas fa-lightbulb'),
            array('logicalId' => 'off', 'name' => __('Éteindre', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_OFF',
                  'visible' => 1, 'icon' => 'far fa-lightbulb'),
            array('logicalId' => 'toggle', 'name' => __('Basculer', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_TOGGLE',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'state', 'name' => __('État', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => 'LIGHT_STATE',
                  'visible' => 0, 'historized' => 1, 'icon' => ''),
            array('logicalId' => 'last', 'name' => __('Dernier changement', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'active', 'name' => __('Programmation active', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => '',
                  'visible' => 0, 'historized' => 1, 'icon' => ''),
            array('logicalId' => 'pause', 'name' => __('Suspendre', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'resume', 'name' => __('Reprendre', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'nextEvening', 'name' => __('Prochain soir', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'nextMorning', 'name' => __('Prochain matin', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'sunset', 'name' => __('Coucher du soleil', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'sunrise', 'name' => __('Lever du soleil', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
        );

        foreach ($definitions as $definition) {
            $cmd = $this->getCmd(null, $definition['logicalId']);
            $isNew = !is_object($cmd);
            if ($isNew) {
                $cmd = new lampesoirmatinbeCmd();
                $cmd->setLogicalId($definition['logicalId']);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName($definition['name']);
                $cmd->setIsVisible($definition['visible']);
                $cmd->setIsHistorized(isset($definition['historized']) ? $definition['historized'] : 0);
                if ($definition['icon'] != '') {
                    $cmd->setDisplay('icon', '<i class="' . $definition['icon'] . '"></i>');
                }
            }
            /*
             * Le type et le type générique sont reposés à chaque enregistrement,
             * le nom et la visibilité non : ces deux-là appartiennent à
             * l'utilisateur dès qu'il y a touché, les premiers déterminent le
             * fonctionnement et une commande mal typée ne s'exécute plus.
             */
            $cmd->setType($definition['type']);
            $cmd->setSubType($definition['subType']);
            $cmd->setGeneric_type($definition['generic']);
            $cmd->setOrder($order++);
            $cmd->save();
        }

        /* Le bouton « Allumer » agit sur l'état affiché : sans ce lien, la tuile
         * garde l'ancien état jusqu'au prochain ordre. */
        $state = $this->getCmd(null, 'state');
        foreach (array('on', 'off') as $logicalId) {
            $cmd = $this->getCmd(null, $logicalId);
            if (is_object($cmd) && is_object($state) && $cmd->getValue() != $state->getId()) {
                $cmd->setValue($state->getId());
                $cmd->save();
            }
        }
    }

    /* ============================================================== SUSPENSION */

    /*
     * Suspendre plutôt que désactiver.
     *
     * Partir quinze jours, ou simplement ne pas vouloir de lumière ce soir,
     * n'a rien à voir avec désactiver l'équipement : celui-ci sort alors du
     * tableau de bord, ses boutons ne répondent plus et ses commandes
     * disparaissent des scénarios. Un groupe suspendu, lui, reste entier — on
     * peut toujours l'allumer à la main — mais ses deux moments se taisent.
     *
     * L'état est enregistré en base et non en cache : un cache vidé rendrait la
     * programmation à la nuit suivante, et les lampes s'allumeraient dans une
     * maison vide sans que personne comprenne pourquoi.
     */
    public function isPaused() {
        return ($this->getConfiguration('paused', 0) == 1);
    }

    public function pauseSchedule($_paused) {
        $paused = $_paused ? 1 : 0;
        if ($this->isPaused() == ($paused == 1)) {
            /* Rien à faire : réenregistrer pour rien ferait repasser toute la
             * configuration dans preSave et écrirait un événement de plus. */
            return $this->isPaused();
        }
        $this->setConfiguration('paused', $paused);
        $this->setConfiguration('paused_since', ($paused == 1) ? date('Y-m-d H:i:s') : '');
        $this->save();

        log::add(__CLASS__, 'info', $this->getHumanName() . ' : '
               . ($paused == 1 ? __('programmation suspendue', __FILE__) : __('programmation reprise', __FILE__)));
        $this->refreshInfo();
        return ($paused == 1);
    }

    /* ============================================================ PROGRAMMATION */

    /* Le réglage normalisé d'un moment. */
    public function slotConfig($_key) {
        return lampesoirmatinbeSun::cleanSlot(
            $this->getConfiguration($_key),
            ($_key == 'evening') ? 'on' : 'off'
        );
    }

    /* La graine du tirage aléatoire : propre à l'équipement et au moment, pour
     * que deux groupes réglés pareil ne s'allument pas à la même seconde — c'est
     * précisément ce que la simulation de présence cherche à éviter. */
    public function seed($_key) {
        return __CLASS__ . '|' . $this->getId() . '|' . $_key;
    }

    public function doneKey($_key) {
        return __CLASS__ . '::done::' . $this->getId() . '::' . $_key;
    }

    public function failureKey() {
        return __CLASS__ . '::failure::' . $this->getId();
    }

    /* Le prochain horodatage d'un moment, ou null. */
    public function nextOccurrence($_key, $_now = null) {
        $now = ($_now === null) ? time() : $_now;
        $position = self::position();
        $next = lampesoirmatinbeSun::nextOccurrences(
            $this->slotConfig($_key), $now,
            $position['latitude'], $position['longitude'],
            $this->seed($_key), 1
        );
        return (count($next) > 0) ? $next[0] : null;
    }

    /*
     * Le travail de la minute : jouer ce qui est dû, puis rafraîchir l'affichage.
     *
     * L'ordre compte. Rafraîchir d'abord annoncerait le prochain rendez-vous
     * avant d'avoir honoré celui qui vient de passer, et la tuile afficherait un
     * instant « ce soir 20:42 » alors qu'il est 20 h 43.
     */
    public function runSchedule($_now = null) {
        $now = ($_now === null) ? time() : $_now;
        /*
         * Un groupe suspendu ne joue rien, mais continue d'annoncer ce qu'il
         * fera à la reprise : c'est ce qui permet de vérifier d'un coup d'oeil
         * qu'on a bien suspendu le bon groupe, et que rien ne partira ce soir.
         */
        if (!$this->isPaused()) {
            foreach (self::SLOTS as $key) {
                $this->runSlot($key, $now);
            }
        }
        $this->refreshInfo($now);
    }

    private function runSlot($_key, $_now) {
        $slot = $this->slotConfig($_key);
        if ($slot['enable'] != 1) {
            return;
        }
        $position = self::position();
        $due = lampesoirmatinbeSun::dueOccurrence(
            $slot, $_now, $position['latitude'], $position['longitude'],
            $this->seed($_key), self::graceSeconds()
        );
        if ($due === null) {
            return;
        }

        /* Déjà joué aujourd'hui : le cron repasse toutes les minutes pendant
         * tout le délai de grâce. */
        $doneKey = $this->doneKey($_key);
        if (cache::byKey($doneKey)->getValue('') === $due['day']) {
            return;
        }
        /*
         * Marqué avant d'agir : si un ordre part et qu'une lampe lève ensuite,
         * le moment ne doit pas repartir en entier à la minute suivante. Les
         * échecs sont rapportés à part, dans le centre de messages.
         */
        cache::set($doneKey, $due['day'], self::DONE_MEMORY);

        log::add(__CLASS__, 'info', $this->getHumanName() . ' — ' . self::slotName($_key) . ' : '
               . (($slot['action'] == 'on') ? __('allumage', __FILE__) : __('extinction', __FILE__))
               . ' ' . __('prévu à', __FILE__) . ' ' . date('H:i', $due['timestamp'])
               . ' (' . self::humanSlot($slot) . ')');

        $this->applyAction($slot['action'], true, 'schedule');
    }

    /*
     * Envoie l'ordre à toutes les lampes du groupe.
     *
     * Une lampe en échec ne retient pas les suivantes : dans un groupe de six,
     * une ampoule débranchée ne doit pas laisser le salon dans le noir. Les
     * échecs sont rassemblés et rapportés une fois, au centre de messages, parce
     * qu'un groupe devenu muet est invisible autrement — personne ne lit le
     * journal d'un plugin qui a toujours marché.
     */
    public function applyAction($_action, $_report = true, $_source = 'manual') {
        $action = ($_action == 'off') ? 'off' : 'on';
        $lamps  = $this->getConfiguration('lamps');
        $sent   = 0;
        $errors = array();

        if (!is_array($lamps) || count($lamps) == 0) {
            $errors[] = __('aucune lampe dans ce groupe', __FILE__);
        }

        foreach (is_array($lamps) ? $lamps : array() as $lamp) {
            try {
                $this->pushLamp($lamp, $action);
                $sent++;
            } catch (Throwable $e) {
                $name = isset($lamp['name']) ? $lamp['name'] : ('#' . (isset($lamp['eq']) ? $lamp['eq'] : '?'));
                $errors[] = $name . ' — ' . $e->getMessage();
                log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $name . ' — ' . $e->getMessage());
            }
        }

        /* L'état du groupe est celui du dernier ordre envoyé, et non celui des
         * lampes : le plugin ne surveille pas ce qu'un interrupteur mural fait
         * de son côté. La documentation le dit, la commande s'appelle « État »
         * et son historique raconte ce que le plugin a demandé. */
        if ($sent > 0) {
            $this->checkAndUpdateCmd('state', ($action == 'on') ? 1 : 0);
            /*
             * « Est-ce que ça a marché hier soir ? » est la première question
             * qu'on se pose, et le journal est le dernier endroit où l'on pense
             * à aller. Une commande la répond seule, sur le tableau de bord.
             */
            $this->checkAndUpdateCmd('last',
                (($action == 'on') ? __('Allumé', __FILE__) : __('Éteint', __FILE__))
                . ' ' . self::humanDate(time())
                . ' ' . self::sourceLabel($_source)
                . (count($errors) > 0 ? ' — ' . count($errors) . ' ' . __('en échec', __FILE__) : ''));
        }

        if ($_report) {
            message::removeAll(__CLASS__, $this->failureKey());
            if (count($errors) > 0) {
                message::add(__CLASS__, $this->getHumanName() . ' : '
                           . implode(' ; ', $errors), '', $this->failureKey());
            }
        }
        return array('sent' => $sent, 'errors' => $errors);
    }

    /*
     * Un ordre, une lampe.
     *
     * La commande a été choisie par le sélecteur et enregistrée par son
     * identifiant. Si elle a disparu depuis — équipement supprimé puis recréé,
     * plugin réinstallé — on redemande au détecteur ce que cet équipement sait
     * faire aujourd'hui plutôt que d'abandonner : l'utilisateur n'a rien changé
     * de son point de vue, sa lampe doit s'allumer.
     */
    private function pushLamp($_lamp, $_action) {
        $cmd = $this->resolveLampCmd($_lamp, $_action);
        if (!is_object($cmd)) {
            throw new Exception(__('commande introuvable, choisissez la lampe à nouveau', __FILE__));
        }
        if ($cmd->getType() != 'action') {
            throw new Exception(__('ce n\'est pas une commande d\'action :', __FILE__) . ' ' . $cmd->getHumanName());
        }
        $eqLogic = $cmd->getEqLogic();
        if (is_object($eqLogic) && $eqLogic->getIsEnable() != 1) {
            throw new Exception(__('équipement désactivé', __FILE__));
        }
        $cmd->execCmd();
    }

    private function resolveLampCmd($_lamp, $_action) {
        $roles = ($_action == 'on') ? array('on', 'toggle') : array('off', 'toggle');

        foreach ($roles as $role) {
            if (!isset($_lamp[$role]) || $_lamp[$role] === null || $_lamp[$role] === '') {
                continue;
            }
            $cmd = cmd::byId((int) $_lamp[$role]);
            if (is_object($cmd)) {
                return $cmd;
            }
        }

        /* Rien de ce qui était enregistré n'existe encore : on reclasse
         * l'équipement à la volée. Le résultat n'est pas réécrit dans la
         * configuration — un cron n'enregistre pas à la place de l'utilisateur —
         * mais l'ordre part, et l'interface montrera la lampe comme à revoir. */
        $eqLogic = isset($_lamp['eq']) ? eqLogic::byId((int) $_lamp['eq']) : null;
        if (!is_object($eqLogic)) {
            return null;
        }
        $cmds = array();
        foreach ($eqLogic->getCmd() as $cmd) {
            $cmds[] = array(
                'id'      => (int) $cmd->getId(),
                'name'    => $cmd->getName(),
                'type'    => $cmd->getType(),
                'subType' => $cmd->getSubType(),
                'generic' => (string) $cmd->getGeneric_type(),
            );
        }
        $lamp = lampesoirmatinbeLamps::classify($eqLogic->getName(), $cmds);
        if ($lamp === null) {
            return null;
        }
        foreach ($roles as $role) {
            if ($lamp[$role] !== null) {
                return cmd::byId($lamp[$role]);
            }
        }
        return null;
    }

    /* D'où vient l'ordre : ce qui distingue un allumage programmé d'un bouton
     * pressé à la main, et qui répond à « pourquoi mes lampes se sont allumées
     * à 3 h du matin ? ». */
    public static function sourceLabel($_source) {
        switch ($_source) {
            case 'schedule': return __('(programmation)', __FILE__);
            case 'test':     return __('(essai)', __FILE__);
        }
        return __('(commande)', __FILE__);
    }

    /* ============================================================== AFFICHAGE */

    /*
     * Repose les commandes d'information : le prochain rendez-vous de chaque
     * moment, celui qui vient en premier, et les heures du soleil.
     *
     * checkAndUpdateCmd n'écrit que si la valeur change : appelé chaque minute,
     * il ne produit ni événement ni ligne d'historique tant que rien ne bouge.
     */
    public function refreshInfo($_now = null) {
        $now = ($_now === null) ? time() : $_now;
        $position = self::position();
        $sun = lampesoirmatinbeSun::sun($now, $position['latitude'], $position['longitude']);

        $this->checkAndUpdateCmd('sunrise', ($sun['sunrise'] === null) ? '--:--' : date('H:i', $sun['sunrise']));
        $this->checkAndUpdateCmd('sunset', ($sun['sunset'] === null) ? '--:--' : date('H:i', $sun['sunset']));

        $this->checkAndUpdateCmd('active', $this->isPaused() ? 0 : 1);

        $best = null;
        foreach (self::SLOTS as $key) {
            $next = $this->nextOccurrence($key, $now);
            $logicalId = ($key == 'evening') ? 'nextEvening' : 'nextMorning';
            $this->checkAndUpdateCmd($logicalId, ($next === null) ? '' : self::humanDate($next, $now));
            if ($next !== null && ($best === null || $next < $best['timestamp'])) {
                $best = array('timestamp' => $next, 'key' => $key);
            }
        }

        /* Un groupe suspendu le dit à la place de son prochain rendez-vous :
         * afficher « Allumage ce soir 19:36 » pour un groupe qui ne s'allumera
         * pas serait un mensonge, et c'est justement la tuile qu'on regarde
         * avant de partir. */
        if ($this->isPaused()) {
            $this->checkAndUpdateCmd('next', __('Suspendu', __FILE__));
            return $best;
        }
        if ($best === null) {
            $this->checkAndUpdateCmd('next', __('Aucun', __FILE__));
            return null;
        }
        $slot = $this->slotConfig($best['key']);
        $this->checkAndUpdateCmd('next',
            (($slot['action'] == 'on') ? __('Allumage', __FILE__) : __('Extinction', __FILE__))
            . ' ' . self::humanDate($best['timestamp'], $now));
        return $best;
    }

    /* « aujourd'hui 20:42 », « demain 07:12 », « jeudi 20:39 ». Le jour de la
     * semaine plutôt que la date : un rendez-vous à huit jours n'existe pas ici,
     * et « jeudi » se lit plus vite que « 25/09 ». */
    public static function humanDate($_timestamp, $_now = null) {
        $now = ($_now === null) ? time() : $_now;
        $days = (int) floor((strtotime(date('Y-m-d', $_timestamp)) - strtotime(date('Y-m-d', $now))) / 86400);
        $hour = date('H:i', $_timestamp);
        if ($days <= 0) {
            return __('aujourd\'hui', __FILE__) . ' ' . $hour;
        }
        if ($days == 1) {
            return __('demain', __FILE__) . ' ' . $hour;
        }
        return __(self::$_days[(int) date('N', $_timestamp)], __FILE__) . ' ' . $hour;
    }

    public static function slotName($_key) {
        return ($_key == 'evening') ? __('Le soir', __FILE__) : __('Le matin', __FILE__);
    }

    /* Le réglage d'un moment en une phrase, pour le journal et pour l'interface :
     * « 30 min avant le coucher du soleil, du lundi au vendredi ». */
    public static function humanSlot($_slot) {
        $slot = lampesoirmatinbeSun::cleanSlot($_slot);
        if ($slot['mode'] == lampesoirmatinbeSun::MODE_FIXED) {
            $when = __('à', __FILE__) . ' ' . $slot['time'];
        } else {
            $event = ($slot['mode'] == lampesoirmatinbeSun::MODE_SUNSET)
                ? __('le coucher du soleil', __FILE__)
                : __('le lever du soleil', __FILE__);
            if ($slot['offset'] == 0) {
                $when = __('à', __FILE__) . ' ' . $event;
            } else {
                $when = abs($slot['offset']) . ' ' . __('min', __FILE__) . ' '
                      . (($slot['offset'] < 0) ? __('avant', __FILE__) : __('après', __FILE__)) . ' ' . $event;
            }
        }
        if ($slot['random'] > 0) {
            $when .= ' ± ' . $slot['random'] . ' ' . __('min', __FILE__);
        }
        if ($slot['not_before'] !== '') {
            $when .= ', ' . __('pas avant', __FILE__) . ' ' . $slot['not_before'];
        }
        if ($slot['not_after'] !== '') {
            $when .= ', ' . __('pas après', __FILE__) . ' ' . $slot['not_after'];
        }
        if (count($slot['days']) == 0) {
            $when .= ', ' . __('aucun jour coché', __FILE__);
        } elseif (count($slot['days']) < 7) {
            $names = array();
            foreach ($slot['days'] as $day) {
                $names[] = __(self::$_days[$day], __FILE__);
            }
            $when .= ', ' . implode(' ', $names);
        }
        return $when;
    }

    /*
     * Ce que l'interface affiche pour un groupe : ses lampes telles qu'elles
     * s'appellent aujourd'hui, et celles qui ont disparu.
     */
    public function lampList() {
        $lamps = $this->getConfiguration('lamps');
        $list = array();
        foreach (is_array($lamps) ? $lamps : array() as $lamp) {
            $described = lampesoirmatinbeLamps::describe($lamp);
            $described['on']     = isset($lamp['on']) ? $lamp['on'] : null;
            $described['off']    = isset($lamp['off']) ? $lamp['off'] : null;
            $described['toggle'] = isset($lamp['toggle']) ? $lamp['toggle'] : null;
            $described['state']  = isset($lamp['state']) ? $lamp['state'] : null;
            $described['value']  = lampesoirmatinbeLamps::readState($described['state']);
            $list[] = $described;
        }
        return $list;
    }

    /* L'aperçu d'un moment : ses trois prochaines occurrences, telles qu'elles
     * seront jouées, garde-fous et tirage aléatoire compris. C'est la seule
     * façon honnête de montrer ce qu'un réglage va faire. */
    public function previewSlot($_key, $_slot = null, $_count = 3) {
        $now = time();
        $position = self::position();
        $slot = ($_slot === null) ? $this->slotConfig($_key) : lampesoirmatinbeSun::cleanSlot($_slot);
        $preview = array();
        foreach (lampesoirmatinbeSun::nextOccurrences($slot, $now, $position['latitude'], $position['longitude'], $this->seed($_key), $_count) as $timestamp) {
            $preview[] = self::humanDate($timestamp, $now);
        }
        $needsSun = ($slot['mode'] != lampesoirmatinbeSun::MODE_FIXED);
        return array(
            'summary'     => self::humanSlot($slot),
            'occurrences' => $preview,
            'action'      => $slot['action'],
            'needsSun'    => $needsSun ? 1 : 0,
            /*
             * Sans position, date_sun_info répond pour le point zéro, au large
             * du golfe de Guinée : un lever à 6 h et un coucher à 18 h toute
             * l'année, sans la moindre erreur. L'aperçu afficherait donc des
             * heures parfaitement plausibles et parfaitement fausses — c'est ici
             * qu'il faut le dire, au moment où l'utilisateur règle le moment.
             */
            'noPosition'  => ($needsSun && !self::hasPosition()) ? 1 : 0,
        );
    }

    /*
     * Ce qu'une carte de la page d'accueil montre : combien de lampes, et ce
     * qui va se passer.
     *
     * Le prochain rendez-vous est recalculé plutôt que lu dans la commande :
     * c'est un calcul pur, de l'ordre de la fraction de milliseconde, et la
     * commande peut dater si le cron du coeur a pris du retard — or c'est
     * précisément quand quelque chose ne tourne pas rond qu'on regarde cette
     * page.
     *
     * Les lampes sont comptées dans la configuration, sans résoudre chaque
     * équipement : une page de dix groupes n'a pas à faire cinquante requêtes
     * pour afficher un nombre.
     */
    public function cardSummary($_now = null) {
        $now = ($_now === null) ? time() : $_now;
        $lamps = $this->getConfiguration('lamps');
        $summary = array(
            'lamps'  => is_array($lamps) ? count($lamps) : 0,
            'paused' => $this->isPaused(),
            'text'   => '',
        );

        if ($summary['paused']) {
            $summary['text'] = __('Programmation suspendue', __FILE__);
            return $summary;
        }

        $best = null;
        foreach (self::SLOTS as $key) {
            $next = $this->nextOccurrence($key, $now);
            if ($next !== null && ($best === null || $next < $best['timestamp'])) {
                $best = array('timestamp' => $next, 'key' => $key);
            }
        }
        if ($best === null) {
            $summary['text'] = __('Aucun moment programmé', __FILE__);
            return $summary;
        }
        $slot = $this->slotConfig($best['key']);
        $summary['text'] = (($slot['action'] == 'on') ? __('Allumage', __FILE__) : __('Extinction', __FILE__))
                         . ' ' . self::humanDate($best['timestamp'], $now);
        return $summary;
    }

    /* ================================================================= SANTÉ */

    /*
     * Page Santé du coeur. Statique et publique : le coeur l'appelle sur la
     * classe, et l'Error d'un appel statique sur une méthode d'instance n'est
     * pas rattrapée par son catch (Exception) — c'est toute la page qui tombe.
     */
    public static function health() {
        $position = self::hasPosition();
        $groups = self::byType(__CLASS__, true);
        $lamps = 0;
        $paused = 0;
        foreach ($groups as $eqLogic) {
            $lamps += count($eqLogic->lampList());
            if ($eqLogic->isPaused()) {
                $paused++;
            }
        }
        return array(
            array(
                'test'    => __('Position de l\'installation', __FILE__),
                'result'  => $position ? __('renseignée', __FILE__) : __('absente', __FILE__),
                'advice'  => $position ? '' : __('Réglages → Système → Configuration → Général : sans latitude ni longitude, les heures de soleil sont fausses.', __FILE__),
                'state'   => $position,
            ),
            array(
                'test'    => __('Groupes actifs', __FILE__),
                'result'  => count($groups),
                'advice'  => '',
                'state'   => true,
            ),
            array(
                /* Un groupe suspendu et oublié est la panne la plus discrète du
                 * plugin : tout fonctionne, et rien ne s'allume. */
                'test'    => __('Groupes suspendus', __FILE__),
                'result'  => $paused,
                'advice'  => ($paused == 0) ? '' : __('Leur programmation ne joue plus tant qu\'elle n\'est pas reprise.', __FILE__),
                'state'   => ($paused == 0),
            ),
            array(
                'test'    => __('Lampes programmées', __FILE__),
                'result'  => $lamps,
                'advice'  => ($lamps > 0) ? '' : __('Aucune lampe choisie : les moments ne feront rien.', __FILE__),
                'state'   => ($lamps > 0),
            ),
        );
    }
}

/*
 * La classe de commande est obligatoire, même réduite à son execute() : sans
 * elle, le coeur refuse de créer un équipement du plugin.
 */
class lampesoirmatinbeCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        switch ($this->getLogicalId()) {
            case 'on':
                $eqLogic->applyAction('on');
                return;
            case 'off':
                $eqLogic->applyAction('off');
                return;
            case 'toggle':
                /*
                 * L'inverse du dernier ordre connu, et non l'inverse de l'état
                 * réel des lampes : le plugin ne surveille pas ce qu'un
                 * interrupteur mural fait de son côté. C'est la même convention
                 * que la commande « État », et elle est dite dans la
                 * documentation.
                 */
                $state = $eqLogic->getCmd(null, 'state');
                $on = (is_object($state) && $state->execCmd() == 1);
                $eqLogic->applyAction($on ? 'off' : 'on');
                return;
            case 'pause':
                $eqLogic->pauseSchedule(true);
                return;
            case 'resume':
                $eqLogic->pauseSchedule(false);
                return;
        }
    }
}
