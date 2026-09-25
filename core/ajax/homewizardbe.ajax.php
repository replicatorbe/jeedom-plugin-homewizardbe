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

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();

    function homewizardbeEq() {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'homewizardbe') {
            throw new Exception(__('Appareil introuvable :', __FILE__) . ' ' . init('id'));
        }
        return $eqLogic;
    }

    /* Recherche sur le réseau local, sans rien créer. */
    if (init('action') == 'scan') {
        unautorizedInDemo();
        ajax::success(homewizardbe::scan(init('subnet')));
    }

    /* Ce qui répond à une adresse donnée, sans rien créer. */
    if (init('action') == 'probe') {
        unautorizedInDemo();
        $info = homewizardbe::probe(init('ip'));
        $existing = !empty($info['serial']) ? homewizardbe::byLogicalId(strtolower($info['serial']), 'homewizardbe') : null;
        $info['known'] = is_object($existing) ? $existing->getHumanName() : '';
        ajax::success($info);
    }

    /* Crée les appareils retenus, ou met leur adresse à jour. */
    if (init('action') == 'create') {
        unautorizedInDemo();
        $devices = json_decode(init('devices'), true);
        if (!is_array($devices) || count($devices) === 0) {
            throw new Exception(__('Aucun appareil à créer.', __FILE__));
        }
        $count = 0;
        foreach ($devices as $device) {
            if (is_array($device) && isset($device['ip'])) {
                /* On ne croit pas le navigateur sur parole : l'appareil est
                 * réinterrogé avant d'être créé. */
                homewizardbe::createFromProbe(homewizardbe::probe($device['ip']));
                $count++;
            }
        }
        ajax::success($count);
    }

    if (init('action') == 'pair') {
        unautorizedInDemo();
        ajax::success(homewizardbeEq()->pairStep());
    }

    if (init('action') == 'unpair') {
        unautorizedInDemo();
        homewizardbeEq()->unpair();
        ajax::success();
    }

    if (init('action') == 'refresh') {
        unautorizedInDemo();
        $eqLogic = homewizardbeEq();
        $eqLogic->forcePoll();
        ajax::success($eqLogic->toAjax());
    }

    /* Lit le cache, n'interroge jamais l'appareil. */
    if (init('action') == 'data') {
        ajax::success(homewizardbeEq()->toAjax());
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

/* Throwable : en PHP 8 une Error n'hérite pas d'Exception. */
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
