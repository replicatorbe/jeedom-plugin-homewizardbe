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
 * Point d'entrée du démon, protégé par la clé API du plugin :
 *   GET  ?apikey=…&action=config  → la liste de ce qu'il faut relever, en JSON
 *   POST ?apikey=…  {"batch":[…]} → des réponses brutes d'appareils, 'OK'
 *
 * Le démon ne décode rien : chaque réponse est confiée à ingest(), le même
 * chemin que le relevé par cron.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

if (!jeedom::apiAccess(init('apikey'), 'homewizardbe')) {
    http_response_code(401);
    echo 'Not authorized';
    die();
}

if (init('action') == 'config') {
    header('Content-Type: application/json');
    echo json_encode(homewizardbe::getDaemonConfig());
    die();
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || !isset($payload['batch']) || !is_array($payload['batch'])) {
    log::add('homewizardbe', 'error', __('Lot du démon illisible.', __FILE__));
    echo 'KO';
    die();
}

foreach ($payload['batch'] as $entry) {
    if (!is_array($entry) || !isset($entry['eq_id'], $entry['results']) || !is_array($entry['results'])) {
        continue;
    }
    $eqLogic = eqLogic::byId((int) $entry['eq_id']);
    /* Appareil supprimé ou désactivé pendant que le lot voyageait. */
    if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'homewizardbe' || !$eqLogic->getIsEnable()) {
        continue;
    }
    try {
        $eqLogic->ingest($entry['results']);
    } catch (Throwable $e) {
        log::add('homewizardbe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
    }
}
echo 'OK';
