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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');
    /* L'autoload du coeur ne connaît que la classe qui porte le nom du plugin :
     * les deux autres se chargent par elle, et une action qui commencerait par
     * le détecteur mourrait sur « Class not found ». */
    require_once __DIR__ . '/../class/lampesoirmatinbe.class.php';

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /*
     * Récupère un groupe du plugin.
     *
     * eqLogic::byId() charge n'importe quel équipement et le rend dans la classe
     * de SON type : sans ce contrôle, un identifiant étranger ferait agir le
     * plugin sur l'équipement d'un autre.
     */
    $getGroup = function ($_id) {
        $eqLogic = lampesoirmatinbe::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'lampesoirmatinbe') {
            throw new Exception(__('Groupe introuvable', __FILE__));
        }
        return $eqLogic;
    };

    /*
     * Retrouve la commande d'un rôle sur une lampe, en reclassant l'équipement.
     *
     * L'identifiant de commande ne vient jamais du navigateur : la page n'envoie
     * que l'équipement, et c'est le détecteur qui dit quelle commande l'allume.
     * Un point d'entrée qui exécuterait l'identifiant reçu serait un « joue
     * n'importe quelle commande de l'installation » déguisé en bouton d'essai.
     */
    $lampCmd = function ($_eqId, $_role, $_cmdId = '') {
        $eqLogic = eqLogic::byId($_eqId);
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        if ($eqLogic->getEqType_name() == 'lampesoirmatinbe') {
            throw new Exception(__('Un groupe du plugin ne peut pas être une lampe.', __FILE__));
        }

        /*
         * Une commande désignée par l'utilisateur dans le sélecteur complet.
         * Elle est contrôlée, et pas seulement exécutée : elle doit appartenir à
         * l'équipement de la ligne et être une action. Sans ces deux contrôles,
         * le point d'entrée serait un « joue n'importe quelle commande de
         * l'installation » déguisé en bouton d'essai.
         */
        if ($_cmdId !== '' && $_cmdId !== null) {
            $cmd = cmd::byId((int) $_cmdId);
            if (!is_object($cmd) || $cmd->getEqLogic_id() != $eqLogic->getId()) {
                throw new Exception(__('Cette commande n\'appartient pas à cet équipement.', __FILE__));
            }
            if ($cmd->getType() != 'action') {
                throw new Exception(__('Ce n\'est pas une commande d\'action.', __FILE__));
            }
            return $cmd;
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
            throw new Exception(__('Cet équipement ne sait ni s\'allumer ni s\'éteindre.', __FILE__));
        }
        foreach (array($_role, 'toggle') as $role) {
            if ($lamp[$role] !== null) {
                $cmd = cmd::byId($lamp[$role]);
                if (is_object($cmd)) {
                    return $cmd;
                }
            }
        }
        throw new Exception(__('Aucune commande pour cet ordre sur cette lampe.', __FILE__));
    };

    /* Les lampes de l'installation, groupées par pièce. « all » ajoute les
     * équipements dont le plugin ne sait rien dire, pour les installations où
     * une lampe est déclarée en module ou en interrupteur. */
    if (init('action') == 'lamps') {
        ajax::success(array(
            'groups'      => lampesoirmatinbeLamps::discover(init('all') == 1),
            'hasPosition' => lampesoirmatinbe::hasPosition() ? 1 : 0,
        ));
    }

    /* Ce qu'un groupe contient et quand il agira : tout ce que la page affiche
     * à l'ouverture d'un équipement. */
    if (init('action') == 'group') {
        $eqLogic = $getGroup(init('id'));
        ajax::success(array(
            'lamps'       => $eqLogic->lampList(),
            'evening'     => $eqLogic->previewSlot('evening'),
            'morning'     => $eqLogic->previewSlot('morning'),
            'hasPosition' => lampesoirmatinbe::hasPosition() ? 1 : 0,
        ));
    }

    /*
     * L'aperçu d'un réglage en cours de saisie, avant enregistrement.
     *
     * Le calcul est fait ici et non dans le navigateur : c'est le même code qui
     * répond à la question « quand ? » dans l'interface et qui décide de l'ordre
     * au moment venu. Deux implémentations divergeraient, et c'est l'interface
     * qu'on croirait.
     */
    if (init('action') == 'preview') {
        $slot = json_decode(init('slot'), true);
        $key  = (init('key') == 'morning') ? 'morning' : 'evening';
        $id   = init('id');
        /* Un groupe pas encore enregistré n'a pas d'identifiant : l'aperçu se
         * fait alors sur un groupe vide, ce qui ne change que la graine du
         * tirage aléatoire. */
        $eqLogic = is_numeric($id) ? $getGroup($id) : new lampesoirmatinbe();
        ajax::success($eqLogic->previewSlot($key, $slot));
    }

    /* Allume ou éteint tout le groupe, tel que le fera la programmation. */
    if (init('action') == 'testGroup') {
        unautorizedInDemo();
        $eqLogic = $getGroup(init('id'));
        $result = $eqLogic->applyAction(init('order'), false);
        if (count($result['errors']) > 0) {
            throw new Exception(implode(' ; ', $result['errors']));
        }
        ajax::success(array(
            'sent'    => $result['sent'],
            'summary' => $result['sent'] . ' ' . __('lampe(s) commandée(s).', __FILE__),
        ));
    }

    /* Allume ou éteint une seule lampe, pour la reconnaître dans la pièce. */
    if (init('action') == 'switchLamp') {
        unautorizedInDemo();
        $cmd = $lampCmd(init('eq'), (init('order') == 'off') ? 'off' : 'on', init('cmd'));
        $cmd->execCmd();
        ajax::success(array('cmd' => $cmd->getHumanName()));
    }

    /* Les états connus, pour les pastilles du sélecteur. */
    if (init('action') == 'states') {
        $states = array();
        $ids = json_decode(init('cmds'), true);
        foreach (is_array($ids) ? $ids : array() as $eq => $cmdId) {
            $states[$eq] = lampesoirmatinbeLamps::readState($cmdId);
        }
        ajax::success($states);
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

} catch (Throwable $e) {
    // Throwable et non Exception : en PHP 8 une Error (méthode inexistante,
    // erreur de type) n'hérite pas d'Exception et donnerait un HTTP 500 muet.
    ajax::error(displayException($e), $e->getCode());
}
