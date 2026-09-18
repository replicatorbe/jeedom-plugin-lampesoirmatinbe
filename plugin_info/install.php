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

require_once __DIR__ . '/../../../core/php/core.inc.php';
/* La désinstallation passe ici alors que le plugin peut déjà être désactivé :
 * l'autoload ne chargerait alors plus sa classe. */
require_once __DIR__ . '/../core/class/lampesoirmatinbe.class.php';

function lampesoirmatinbe_install() {
    /*
     * Le plugin ne sert à rien sans la position de l'installation : les heures
     * de lever et de coucher du soleil seraient celles du point zéro, au large
     * du golfe de Guinée, sans qu'aucune erreur ne soit levée. Le dire à
     * l'installation évite de chercher longtemps pourquoi les lampes s'allument
     * à 18 h 15 toute l'année.
     *
     * Le contrôle vit dans la classe : le cron quotidien le refait, et retire
     * l'avertissement le jour où la position est renseignée.
     */
    lampesoirmatinbe::checkPosition();
}

function lampesoirmatinbe_update() {
    lampesoirmatinbe_install();
}

function lampesoirmatinbe_remove() {
    /*
     * Les marques d'exécution du jour survivraient à la désinstallation : elles
     * empêcheraient un moment de repartir le jour d'une réinstallation, et rien
     * ne le montrerait. Les équipements, eux, sont nettoyés un par un par
     * lampesoirmatinbe::preRemove().
     */
    foreach (eqLogic::byType('lampesoirmatinbe') as $eqLogic) {
        foreach (lampesoirmatinbe::SLOTS as $key) {
            try {
                cache::delete('lampesoirmatinbe::done::' . $eqLogic->getId() . '::' . $key);
            } catch (Throwable $e) {
                log::add('lampesoirmatinbe', 'debug', __('Nettoyage du cache impossible :', __FILE__) . ' ' . $e->getMessage());
            }
        }
    }
    message::removeAll('lampesoirmatinbe');
}
