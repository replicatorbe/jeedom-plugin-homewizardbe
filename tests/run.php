<?php
/* Rejeu hors ligne du plugin sur des réponses d'appareils HomeWizard.
 *
 *   php tests/run.php
 *
 * Les fixtures de tests/fixtures/ sont des réponses telles que les appareils
 * les rendent (p1_*.json et p1_telegram.txt ont été relevés sur un vrai P1 ;
 * les autres sont écrites d'après la documentation officielle des API v1 et
 * v2). Chaque valeur douteuse rencontrée en production doit y laisser un
 * fichier : c'est ce qui transforme un incident en test de non-régression. */

require_once __DIR__ . '/stub.php';

/* La classe charge le coeur de Jeedom en première ligne ; hors installation, on
 * la recopie sans ce require et on l'inclut. La copie est déposée à côté de
 * l'originale et effacée en sortant, même sur erreur fatale. */
$original = __DIR__ . '/../core/class/homewizardbe.class.php';
$copy = __DIR__ . '/../core/class/.homewizardbe.test.php';
$source = preg_replace('#^\s*require_once .*core\.inc\.php.*$#m', '', file_get_contents($original));
file_put_contents($copy, $source);
register_shutdown_function(function () use ($copy) {
    if (file_exists($copy)) { unlink($copy); }
});
require_once $copy;

$passed = 0;
$failed = 0;

function check($_label, $_actual, $_expected) {
    global $passed, $failed;
    if ($_actual === $_expected) {
        $passed++;
        printf("  ok    %-58s %s\n", $_label, var_export($_actual, true));
    } else {
        $failed++;
        printf("  ECHEC %-58s obtenu %s, attendu %s\n", $_label,
            var_export($_actual, true), var_export($_expected, true));
    }
}

/*
 * Comparaison numérique tolérante au type. JSON ne distingue pas 50,0 de 50 :
 * json_decode rend un entier pour l'un, un flottant pour l'autre. Un === sur
 * la valeur relue échouerait sur une différence qui n'existe pas.
 */
function checkNum($_label, $_actual, $_expected) {
    global $passed, $failed;
    if (is_numeric($_actual) && abs((float) $_actual - (float) $_expected) < 0.0001) {
        $passed++;
        printf("  ok    %-58s %s\n", $_label, var_export($_actual, true));
    } else {
        $failed++;
        printf("  ECHEC %-58s obtenu %s, attendu %s\n", $_label,
            var_export($_actual, true), var_export($_expected, true));
    }
}

function section($_title) {
    echo "\n" . $_title . "\n" . str_repeat('-', mb_strlen($_title)) . "\n";
}

function fixture($_name) {
    return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $_name . '.json'), true);
}

function body($_name) {
    $ext = strpos($_name, '.') === false ? '.json' : '';
    return file_get_contents(__DIR__ . '/fixtures/' . $_name . $ext);
}

/* Donne accès aux méthodes privées : on teste la logique, pas la visibilité. */
function invoke($_object, $_method, $_args = array()) {
    $m = new ReflectionMethod('homewizardbe', $_method);
    $m->setAccessible(true);
    return $m->invokeArgs($_object, $_args);
}

function device($_productType, $_token = '', $_id = 1) {
    $eq = new homewizardbe();
    $eq->id = $_id;
    $eq->setConfiguration('ip', '192.168.0.42');
    $eq->setConfiguration('product_type', $_productType);
    $eq->setConfiguration('token', $_token);
    return $eq;
}

/* Mois AAAA-MM et horodatage DSMR AAMMJJhhmmss relatifs à aujourd'hui : les
 * télégrammes synthétiques restent justes quel que soit le jour du test. */
function monthAgo($_n) {
    return date('Y-m', strtotime('first day of -' . $_n . ' month'));
}
function dsmrStamp($_n, $_day = '10', $_time = '181500') {
    return date('ym', strtotime('first day of -' . $_n . ' month')) . $_day . $_time . 'W';
}

$allKeys = array();   /* toutes les clés produites, pour le contrôle des définitions */
function collect($_values) {
    global $allKeys;
    foreach ($_values as $k => $v) {
        $allKeys[$k] = true;
    }
    return $_values;
}

/* ============================================================== P1 EN v1 */
section('P1 en API v1 (relevé réel)');

$v1 = collect(homewizardbe::normalize(fixture('p1_data')));
$expectedKeys = array('wifi_ssid', 'wifi_strength', 'protocol_version', 'meter_model', 'unique_id', 'tariff',
    'energy_import_kwh', 'energy_import_t1_kwh', 'energy_import_t2_kwh',
    'energy_export_kwh', 'energy_export_t1_kwh', 'energy_export_t2_kwh',
    'power_w', 'power_l1_w', 'voltage_l1_v', 'current_a', 'current_l1_a',
    'average_power_15m_w', 'monthly_power_peak_w', 'monthly_power_peak_timestamp');
$keys = array_keys($v1);
sort($keys);
sort($expectedKeys);
check('chaque champ ramené à son nom v2, rien de plus', $keys, $expectedKeys);
check('unique_id décodé de l\'hexadécimal', $v1['unique_id'], '1SAG1100000001');
check('pointe du mois : horodatage AAMMJJhhmmss', $v1['monthly_power_peak_timestamp'], '2026-09-25 06:15:00');
check('montly_power_peak_w (faute v1) → monthly_power_peak_w', $v1['monthly_power_peak_w'], 420);
check('smr_version → protocol_version', $v1['protocol_version'], 50);
check('active_tariff → tariff', $v1['tariff'], 2);
check('active_power_average_w → average_power_15m_w', $v1['average_power_15m_w'], 0);
checkNum('total_power_import_kwh → energy_import_kwh', $v1['energy_import_kwh'], 5764.273);
checkNum('tarif 1 gardé, puisque le tarif 2 existe', $v1['energy_import_t1_kwh'], 2761.107);
checkNum('export tarif 1 gardé aussi', $v1['energy_export_t1_kwh'], 2672.956);
check('puissance signée conservée', $v1['power_w'], -2157);
checkNum('active_voltage_l1_v → voltage_l1_v', $v1['voltage_l1_v'], 245.1);
check('texte laissé en texte', $v1['meter_model'], 'Fluvius 253972606_G');
check('external vide : aucun compteur externe', count(preg_grep('/^ext_/', array_keys($v1))), 0);

/* ======================================================== PRISE EN v1 */
section('Prise en API v1');

$sk = collect(homewizardbe::normalize(fixture('socket_v1')));
check('doublon tarif 1 sans tarif 2 : import écarté', isset($sk['energy_import_t1_kwh']), false);
check('doublon tarif 1 sans tarif 2 : export écarté', isset($sk['energy_export_t1_kwh']), false);
checkNum('énergie importée', $sk['energy_import_kwh'], 63.651);
checkNum('énergie exportée à zéro, pas omise', $sk['energy_export_kwh'], 0);
checkNum('active_voltage_v → voltage_v', $sk['voltage_v'], 230.4);
checkNum('active_frequency_hz → frequency_hz', $sk['frequency_hz'], 50.02);
checkNum('active_reactive_power_var → reactive_power_var', $sk['reactive_power_var'], -12.4);
checkNum('active_apparent_power_va → apparent_power_va', $sk['apparent_power_va'], 111.1);
checkNum('facteur de puissance 0,884 → 88,4 %', $sk['power_factor'], 88.4);

$state = collect(homewizardbe::normalizeState(fixture('socket_v1_state')));
check('état : power_on vrai → 1', $state['power_on'], 1);
check('état : switch_lock faux → 0', $state['switch_lock'], 0);
check('anneau lumineux 255 → 100 %', $state['status_led_brightness_pct'], 100);
check('anneau lumineux 0 → 0 %', homewizardbe::normalizeState(array('brightness' => 0))['status_led_brightness_pct'], 0);
check('anneau lumineux 128 → 50 %', homewizardbe::normalizeState(array('brightness' => 128))['status_led_brightness_pct'], 50);
check('état vide : rien', homewizardbe::normalizeState(array()), array());

/* =================================================== kWh TRIPHASÉ EN v1 */
section('Compteur kWh triphasé en API v1');

$k3 = collect(homewizardbe::normalize(fixture('kwh3_v1')));
check('doublon tarif 1 écarté', isset($k3['energy_import_t1_kwh']) || isset($k3['energy_export_t1_kwh']), false);
checkNum('active_power_l3_w → power_l3_w', $k3['power_l3_w'], 1100.1);
checkNum('active_voltage_l2_v → voltage_l2_v', $k3['voltage_l2_v'], 229.8);
checkNum('active_current_l1_a → current_l1_a', $k3['current_l1_a'], 4.8);
checkNum('active_apparent_current_a → apparent_current_a', $k3['apparent_current_a'], 14.9);
checkNum('active_apparent_current_l2_a → apparent_current_l2_a', $k3['apparent_current_l2_a'], 4.9);
checkNum('active_reactive_current_l3_a → reactive_current_l3_a', $k3['reactive_current_l3_a'], 0.7);
checkNum('active_apparent_power_l1_va → apparent_power_l1_va', $k3['apparent_power_l1_va'], 1150.2);
checkNum('active_reactive_power_l2_var → reactive_power_l2_var', $k3['reactive_power_l2_var'], 140.1);
checkNum('active_power_factor_l1 → power_factor_l1 en %', $k3['power_factor_l1'], 97.0);
checkNum('power_factor_l2 : 0,955 → 95,5 %', $k3['power_factor_l2'], 95.5);
checkNum('power_factor_l3', $k3['power_factor_l3'], 96.0);
check('aucun nom v1 résiduel (active_*, total_power_*)',
    array_values(preg_grep('/^(active_|total_power_)/', array_keys($k3))), array());

/* ================================================ COMPTEUR D'EAU EN v1 */
section('Compteur d\'eau en API v1');

$wt = collect(homewizardbe::normalize(fixture('water_v1')));
checkNum('active_liter_lpm garde son nom', $wt['active_liter_lpm'], 7.2);
checkNum('total_liter_m3', $wt['total_liter_m3'], 123.456);
/* Le numéro du compteur de gaz (DSMR 0-1:96.1.0) est codé en hexadécimal
 * exactement comme celui du compteur électrique. */
check('[bogue] gas_unique_id v1 décodé comme unique_id',
    homewizardbe::normalize(array('gas_unique_id' => '37464C4F32313139303333373333'))['unique_gas_id'], '7FLO2119033733');
check('total_liter_offset_m3 ignoré', isset($wt['total_liter_offset_m3']), false);

/* ============================================================== P1 EN v2 */
section('P1 en API v2 : mêmes identifiants qu\'en v1');

$v2 = collect(homewizardbe::normalize(fixture('p1_v2_measurement')));
check('timestamp de la mesure ignoré', isset($v2['timestamp']), false);
check('unique_id décodé', $v2['unique_id'], '1SAG1100000001');
check('pointe du mois : horodatage ISO', $v2['monthly_power_peak_timestamp'], '2026-09-25 06:15:00');
checkNum('compteur de gaz externe', $v2['ext_gas_meter'], 2569.646);
check('relevé du gaz, horodatage ISO', $v2['ext_gas_meter_timestamp'], '2026-09-25 11:15:00');
check('unité du gaz : m3 → m³', $v2['_unit_ext_gas_meter'], 'm³');
checkNum('creux de tension phase 1', $v2['voltage_sag_l1_count'], 1);

/* La promesse du plugin : passer de v1 à v2 ne change aucune commande. Tout
 * champ que la v1 produit (hors Wi-Fi, que la v2 met dans /api/system) doit
 * sortir de la v2 sous le même identifiant et avec la même valeur. */
$lost = array();
$differ = array();
foreach ($v1 as $key => $value) {
    if (strpos($key, 'wifi_') === 0) {
        continue;
    }
    if (!array_key_exists($key, $v2)) {
        $lost[] = $key;
    } elseif ((string) $v2[$key] !== (string) $value) {
        $differ[] = $key . ' : ' . var_export($value, true) . ' / ' . var_export($v2[$key], true);
    }
}
check('v1 → v2 : aucun identifiant perdu', $lost, array());
check('v1 → v2 : mêmes valeurs', $differ, array());

$k1 = collect(homewizardbe::normalize(fixture('kwh1_v2_measurement')));
check('kWh v2 : doublon tarif 1 écarté aussi', isset($k1['energy_import_t1_kwh']), false);
checkNum('kWh v2 : facteur de puissance en %', $k1['power_factor'], 97.4);
$bm = collect(homewizardbe::normalize(fixture('bat_v2_measurement')));
checkNum('batterie v2 : état de charge', $bm['state_of_charge_pct'], 76.5);
check('batterie v2 : cycles', $bm['cycles'], 42);

/* ================================================= COMPTEURS EXTERNES */
section('Compteurs externes du P1');

$ext = collect(homewizardbe::normalize(array('external' => array(
    array('unique_id' => 'a1', 'type' => 'gas_meter', 'timestamp' => 260925111500, 'value' => 2569.646, 'unit' => 'm3'),
    array('unique_id' => 'b2', 'type' => 'water_meter', 'timestamp' => 260925111000, 'value' => 81.2, 'unit' => 'm3'),
    array('unique_id' => 'c3', 'type' => 'gas_meter', 'timestamp' => 260925110000, 'value' => 12.5, 'unit' => 'm3'),
    array('unique_id' => 'd4', 'type' => 'heat_meter', 'value' => 3.2, 'unit' => 'GJ'),
    array('type' => 'gas_meter'),
    'illisible',
))));
checkNum('premier gaz → ext_gas_meter', $ext['ext_gas_meter'], 2569.646);
checkNum('second gaz → ext_gas_meter_2', $ext['ext_gas_meter_2'], 12.5);
check('horodatage v1 du second gaz', $ext['ext_gas_meter_2_timestamp'], '2026-09-25 11:00:00');
check('unité du premier gaz', $ext['_unit_ext_gas_meter'], 'm³');
check('unité du second gaz', $ext['_unit_ext_gas_meter_2'], 'm³');
checkNum('eau → ext_water_meter', $ext['ext_water_meter'], 81.2);
check('chaleur : unité GJ inchangée', $ext['_unit_ext_heat_meter'], 'GJ');
check('chaleur sans horodatage : pas de clé _timestamp', isset($ext['ext_heat_meter_timestamp']), false);
check('entrée sans valeur ni entrée illisible : ignorées', isset($ext['ext_gas_meter_3']), false);

/* ========================================================== SYSTÈME, BATTERIES */
section('Système, état et batteries');

$sys = collect(homewizardbe::normalizeSystem(fixture('p1_v2_system')));
check('RSSI conservé', $sys['wifi_rssi_db'], -67);
check('RSSI -67 dBm → 66 %', $sys['wifi_strength'], 66);
check('356 400 s → 99 h', $sys['uptime_h'], 99.0);
check('cloud désactivé → 0', $sys['cloud_enabled'], 0);
check('luminosité du voyant', $sys['status_led_brightness_pct'], 70);
check('api_v1_enabled ignoré', isset($sys['api_v1_enabled']), false);
check('RSSI -50 → 100 %', homewizardbe::normalizeSystem(array('wifi_rssi_db' => -50))['wifi_strength'], 100);
check('RSSI -40 → 100 %', homewizardbe::normalizeSystem(array('wifi_rssi_db' => -40))['wifi_strength'], 100);
check('RSSI -100 → 0 %', homewizardbe::normalizeSystem(array('wifi_rssi_db' => -100))['wifi_strength'], 0);
check('RSSI 0 (inconnu) → 0 %', homewizardbe::normalizeSystem(array('wifi_rssi_db' => 0))['wifi_strength'], 0);
check('système v1 : cloud seul', collect(homewizardbe::normalizeSystem(fixture('p1_system'))), array('cloud_enabled' => 1));

$bat = collect(homewizardbe::normalizeBatteries(fixture('batteries_v2')));
check('zero + charge seule → zero_charge_only', $bat['battery_mode'], 'zero_charge_only');
check('nombre de batteries', $bat['battery_count'], 2);
check('power_w → battery_group_power_w', $bat['battery_group_power_w'], -404);
check('target_power_w → battery_group_target_power_w', $bat['battery_group_target_power_w'], -400);
check('max_consumption_w', $bat['battery_max_consumption_w'], 1600);
check('max_production_w', $bat['battery_max_production_w'], 800);
check('zero + décharge seule → zero_discharge_only',
    homewizardbe::normalizeBatteries(array('mode' => 'zero', 'permissions' => array('discharge_allowed')))['battery_mode'],
    'zero_discharge_only');
check('zero + les deux → zero',
    homewizardbe::normalizeBatteries(array('mode' => 'zero', 'permissions' => array('charge_allowed', 'discharge_allowed')))['battery_mode'],
    'zero');
check('zero sans permissions → zero',
    homewizardbe::normalizeBatteries(array('mode' => 'zero'))['battery_mode'], 'zero');

$roundTrip = array();
foreach (array_keys(homewizardbe::BATTERY_MODES) as $mode) {
    $back = homewizardbe::normalizeBatteries(homewizardbe::batteryModeBody($mode));
    if ($back['battery_mode'] !== $mode) {
        $roundTrip[] = $mode . ' → ' . $back['battery_mode'];
    }
}
check('aller-retour batteryModeBody / normalizeBatteries', $roundTrip, array());
$refused = false;
try {
    homewizardbe::batteryModeBody('turbo');
} catch (Exception $e) {
    $refused = true;
}
check('stratégie inconnue refusée', $refused, true);

/* ===================================================== TARIF CAPACITAIRE */
section('Télégramme et tarif capacitaire');

$months = homewizardbe::parseTelegram(body('p1_telegram.txt'));
check('douze mois distincts (deux entrées en 2025-10)', count($months), 12);
check('rangés du plus récent au plus ancien', array_keys($months)[0] > array_keys($months)[11], true);
check('2025-10 : maximum des deux entrées', $months['2025-10'], array('w' => 4588, 'at' => '2025-10-21 18:00'));
check('2026-01 : 5,511 kW', $months['2026-01']['w'], 5511);
check('2026-09 : l\'historique (2,728) l\'emporte sur 1.6.0 (0,420)', $months['2026-09'],
    array('w' => 2728, 'at' => '2026-09-19 08:30'));

$eq = device('HWE-P1');
$cap = $eq->capacityValues($months);
if (date('Y-m') === '2026-09') {
    /* Le jour du relevé : les valeurs exactes. */
    check('moyenne 12 mois', $cap['capacity_avg_12m_w'], 4307);
    check('pointe du mois précédent (2026-08)', $cap['peak_previous_month_w'], 3604);
    check('pointe maximale 12 mois', $cap['peak_max_12m_w'], 5511);
    check('historique : douze entrées', count(explode(' | ', $cap['peak_history'])), 12);
    check('historique : première entrée', explode(' | ', $cap['peak_history'])[0], '2026-09 : 2,728 kW (2026-09-19 08:30)');
} else {
    /* Plus tard : seulement ce qui ne dépend pas du jour. */
    check('moyenne au moins égale au plancher', $cap['capacity_avg_12m_w'] >= homewizardbe::CAPACITY_FLOOR_W, true);
    check('historique : douze entrées au plus', count(explode(' | ', $cap['peak_history'])) <= 12, true);
    check('pointe maximale ≤ 5,511 kW', $cap['peak_max_12m_w'] <= 5511, true);
}
check('pas de télégramme : rien', $eq->capacityValues(array()), array());

/* Petits pics : chaque mois compte pour 2,5 kW au moins. */
$tele = "1-0:1.6.0(" . dsmrStamp(0) . ")(01.200*kW)\n"
      . "0-0:98.1.0(3)(1-0:1.6.0)(1-0:1.6.0)"
      . "(" . dsmrStamp(1, '01', '000000') . ")(" . dsmrStamp(2) . ")(00.800*kW)"
      . "(" . dsmrStamp(0, '01', '000000') . ")(" . dsmrStamp(1) . ")(03.000*kW)"
      . "(" . dsmrStamp(0, '02', '000000') . ")(" . dsmrStamp(0, '01', '101500') . ")(00.900*kW)\r\n"
      . "!ABCD\r\n";
$small = homewizardbe::parseTelegram($tele);
check('synthétique : trois mois', array_keys($small), array(monthAgo(0), monthAgo(1), monthAgo(2)));
check('synthétique : 1.6.0 (1,2) bat l\'historique (0,9)', $small[monthAgo(0)]['w'], 1200);
$cap = $eq->capacityValues($small);
check('plancher 2,5 kW : (2500 + 3000 + 2500) / 3', $cap['capacity_avg_12m_w'], 2667);
check('pointe du mois précédent', $cap['peak_previous_month_w'], 3000);
check('pointe maximale : valeur réelle, sans plancher', $cap['peak_max_12m_w'], 3000);
$cap = $eq->capacityValues(array(monthAgo(0) => array('w' => 800, 'at' => 'x')));
check('un seul mois, petit : moyenne au plancher', $cap['capacity_avg_12m_w'], 2500);
check('pas de mois précédent : null', $cap['peak_previous_month_w'], null);
/* Horloge de Jeedom en retard, ou compteur en avance : aucun mois n'est
 * antérieur au mois courant. max() d'un tableau vide lève une ValueError en
 * PHP 8, qui ferait échouer tout le relevé, mesures comprises. */
try {
    $cap = $eq->capacityValues(array(date('Y-m', strtotime('first day of +2 month')) => array('w' => 3000, 'at' => 'x')));
} catch (Throwable $e) {
    $cap = get_class($e) . ' : ' . $e->getMessage();
}
check('[bogue] seulement des mois futurs : rien, sans erreur', $cap, array());
check('télégramme sans pics : rien', homewizardbe::parseTelegram("/FLU5\n1-0:1.8.1(002761.107*kWh)\n"), array());

/* ===================================================== VALEURS CALCULÉES */
section('Valeurs calculées');

$yesterday = date('Y-m-d', strtotime('-1 day'));
$eq = device('HWE-P1');
$d = $eq->derivedValues(array('power_w' => -300));
check('injection : soutirage à 0', $d['power_import_w'], 0);
check('injection : 300 W injectés', $d['power_export_w'], 300.0);
$d = $eq->derivedValues(array('power_w' => 1200));
check('soutirage : 1200 W soutirés', $d['power_import_w'], 1200.0);
check('soutirage : injection à 0', $d['power_export_w'], 0);

$eq = device('HWE-P1');
$eq->setCache('day', array('date' => $yesterday, 'start_import' => 100.0, 'last_import' => 112.5,
    'start_export' => 50.0, 'last_export' => 50.75));
$d = $eq->derivedValues(array('energy_import_kwh' => 112.7, 'energy_export_kwh' => 50.8));
check('minuit : import de la veille', $d['import_yesterday'], 12.5);
check('minuit : export de la veille', $d['export_yesterday'], 0.75);
check('minuit : import du jour repart de zéro', $d['import_today'], 0.0);
check('minuit : export du jour repart de zéro', $d['export_today'], 0.0);
check('cache : nouvelle date', $eq->getCache('day')['date'], date('Y-m-d'));
$d = $eq->derivedValues(array('energy_import_kwh' => 113.2, 'energy_export_kwh' => 50.8));
checkNum('plus tard : import du jour', $d['import_today'], 0.5);
check('plus tard : la veille n\'est plus republiée', isset($d['import_yesterday']), false);

$eq = device('HWE-P1');
$eq->setCache('day', array('date' => date('Y-m-d', strtotime('-3 days')), 'start_import' => 100.0, 'last_import' => 112.5));
$d = $eq->derivedValues(array('energy_import_kwh' => 140.0));
check('coupure de plusieurs jours : pas de veille', isset($d['import_yesterday']), false);
check('coupure de plusieurs jours : jour à zéro', $d['import_today'], 0.0);

$eq = device('HWE-WTR');
$eq->setCache('day', array('date' => $yesterday, 'start_water' => 122.0, 'last_water' => 122.25));
$d = $eq->derivedValues(array('total_liter_m3' => 123.0));
check('eau : veille en litres (0,25 m³)', $d['water_yesterday'], 250);
$d = $eq->derivedValues(array('total_liter_m3' => 123.456));
check('eau : jour en litres entiers', $d['water_today'], 456);

$eq = device('HWE-SKT');
$eq->setCache('day', array('date' => date('Y-m-d'), 'start_import' => 70.0, 'last_import' => 70.0));
$d = $eq->derivedValues(array('energy_import_kwh' => 63.651));
check('index qui recule (compteur remplacé) : jamais négatif', $d['import_today'], 0.0);

/* Le démon n'envoie que les ressources dues : un lot fait du seul /system (ou
 * du seul télégramme) arrive régulièrement. S'il est le premier après minuit,
 * il ne doit pas effacer les index de la veille. */
$eq = device('HWE-P1');
$eq->setCache('day', array('date' => $yesterday, 'start_import' => 100.0, 'last_import' => 112.5));
$eq->derivedValues(array('cloud_enabled' => 1));
$d = $eq->derivedValues(array('energy_import_kwh' => 112.7));
check('lot sans index juste après minuit : veille gardée', isset($d['import_yesterday']) ? $d['import_yesterday'] : null, 12.5);

/* ============================================================ DÉFINITIONS */
section('Définitions et ordre');

$def = homewizardbe::definition('power_l2_w');
check('power_l2_w : nom', $def[0], 'Puissance phase 2');
check('power_l2_w : type générique, masquée par défaut', array($def[2], $def[3], $def[4]), array('W', 'POWER', 0));
$def = homewizardbe::definition('voltage_l3_v');
check('voltage_l3_v', array($def[0], $def[2], $def[3], $def[4]), array('Tension phase 3', 'V', 'VOLTAGE', 0));
check('current_l1_a', homewizardbe::definition('current_l1_a')[0], 'Courant phase 1');
check('power_l1_w : non historisée par défaut', homewizardbe::definition('power_l1_w')[5], 0);
check('current_l1_a : non historisée par défaut', homewizardbe::definition('current_l1_a')[5], 0);
check('apparent_power_l2_va : non historisée par défaut', homewizardbe::definition('apparent_power_l2_va')[5], 0);
check('voltage_l1_v : reste historisée', homewizardbe::definition('voltage_l1_v')[5], 1);
check('wifi_strength : non historisée', homewizardbe::definition('wifi_strength')[5], 0);
check('apparent_power_l1_va', array_slice(homewizardbe::definition('apparent_power_l1_va'), 0, 3),
    array('Puissance apparente phase 1', 'numeric', 'VA'));
check('reactive_power_l3_var : unité', homewizardbe::definition('reactive_power_l3_var')[2], 'var');
check('apparent_current_l2_a', homewizardbe::definition('apparent_current_l2_a')[0], 'Courant apparent phase 2');
check('reactive_current_l2_a : pas de type générique', homewizardbe::definition('reactive_current_l2_a')[3], '');
check('power_factor_l3', homewizardbe::definition('power_factor_l3'),
    array('Facteur de puissance phase 3', 'numeric', '%', '', 0, 1));
check('energy_import_t2_kwh', homewizardbe::definition('energy_import_t2_kwh')[0], 'Énergie importée tarif 2');
check('energy_export_t4_kwh', homewizardbe::definition('energy_export_t4_kwh')[0], 'Énergie exportée tarif 4');
check('energy_import_t5_kwh : inconnu', homewizardbe::definition('energy_import_t5_kwh'), null);
check('voltage_sag_l1_count', homewizardbe::definition('voltage_sag_l1_count')[0], 'Creux de tension détectés phase 1');
check('voltage_swell_l3_count', homewizardbe::definition('voltage_swell_l3_count')[0], 'Surtensions détectées phase 3');
check('ext_gas_meter', homewizardbe::definition('ext_gas_meter'), array('Compteur de gaz', 'numeric', '', '', 1, 1));
check('ext_gas_meter_2', homewizardbe::definition('ext_gas_meter_2')[0], 'Compteur de gaz 2');
check('ext_gas_meter_2_timestamp', homewizardbe::definition('ext_gas_meter_2_timestamp'),
    array('Compteur de gaz 2 (relevé)', 'string', '', '', 0, 0));
check('ext_inlet_heat_meter', homewizardbe::definition('ext_inlet_heat_meter')[0], 'Compteur de chaleur (entrée)');
check('ext_warm_water_meter_timestamp', homewizardbe::definition('ext_warm_water_meter_timestamp')[1], 'string');
check('ext d\'un type inconnu : null', homewizardbe::definition('ext_plasma_meter'), null);
check('clé inconnue : null', homewizardbe::definition('foo_bar'), null);
check('phase 4 : null', homewizardbe::definition('power_l4_w'), null);
/* L'expression des phases accepte n'importe quelle unité ; une paire qui n'a
 * pas de forme générale doit rester inconnue, pas produire une définition
 * bancale (et un avertissement « Undefined array key »). */
$warning = null;
set_error_handler(function ($no, $str) use (&$warning) { $warning = $str; return true; });
$bad = homewizardbe::definition('power_l1_v');
restore_error_handler();
check('[bogue] power_l1_v (unité incohérente) : null', $bad, null);
check('[bogue] power_l1_v : sans avertissement PHP', $warning, null);

/* Toute clé produite par une fixture doit avoir sa commande. */
$cap = device('HWE-P1')->capacityValues($months);
collect($cap);
collect(device('HWE-P1')->derivedValues(array('power_w' => 1, 'energy_import_kwh' => 1, 'energy_export_kwh' => 1, 'total_liter_m3' => 1)));
$eq = device('HWE-P1');
$eq->setCache('day', array('date' => $yesterday, 'start_import' => 1.0, 'last_import' => 2.0,
    'start_export' => 1.0, 'last_export' => 2.0, 'start_water' => 1.0, 'last_water' => 2.0));
collect($eq->derivedValues(array('energy_import_kwh' => 3, 'energy_export_kwh' => 3, 'total_liter_m3' => 3)));
collect(homewizardbe::normalize(array('total_gas_m3' => 12.3, 'gas_timestamp' => 260925110000, 'gas_unique_id' => '3132',
    'any_power_fail_count' => 1, 'long_power_fail_count' => 1)));
$allKeys['online'] = true;
$orphans = array();
foreach (array_keys($allKeys) as $key) {
    if ($key[0] !== '_' && homewizardbe::definition($key) === null) {
        $orphans[] = $key;
    }
}
check('chaque clé produite a une définition (' . count($allKeys) . ' clés)', $orphans, array());

$orderIssues = array();
foreach (array_keys($allKeys) as $key) {
    if ($key[0] !== '_' && homewizardbe::orderOf($key) >= 900) {
        $orderIssues[] = $key;
    }
}
check('chaque clé produite a un rang (< 900)', $orderIssues, array());
check('ordre : puissance avant énergie', homewizardbe::orderOf('power_w') < homewizardbe::orderOf('energy_import_kwh'), true);
check('ordre : énergie avant Wi-Fi', homewizardbe::orderOf('energy_import_kwh') < homewizardbe::orderOf('wifi_ssid'), true);
$ranks = array();
foreach (array_keys($allKeys) as $key) {
    if ($key[0] !== '_') {
        $ranks[] = homewizardbe::orderOf($key);
    }
}
check('ordre : en ligne en dernier', homewizardbe::orderOf('online') >= max($ranks), true);
check('ordre : power_factor_l1 n\'est pas pris pour power_l1',
    homewizardbe::orderOf('power_factor_l1') === homewizardbe::orderOf('power_factor'), true);
check('ordre : inconnu à la fin', homewizardbe::orderOf('foo'), 900);

/* cmd::setName() passe par cleanComponanteName(), qui retire l'apostrophe sans
 * rien dire : « Importation d'énergie » arriverait en base sous la forme
 * « Importation dénergie ». Et byEqLogicIdCmdName(), appelé avec le nom brut,
 * ne retrouve alors jamais la commande homonyme. */
$names = array();
foreach (array_keys($allKeys) as $key) {
    $d = homewizardbe::definition($key);
    if ($d !== null) {
        $names[$key] = $d[0];
    }
}
foreach (array('ext_water_meter', 'ext_warm_water_meter', 'ext_heat_meter', 'ext_inlet_heat_meter', 'energy_import_t3_kwh',
               'battery_mode', 'state_of_charge_pct', 'cycles') as $key) {
    $names[$key] = homewizardbe::definition($key)[0];
}
$apostrophes = array_values(array_filter($names, function ($n) { return strpos($n, "'") !== false; }));
check('aucune apostrophe droite dans un nom de commande (typographique seulement)', $apostrophes, array());
$doublons = array_diff_assoc(array_map('cleanComponanteName', $names), array_unique(array_map('cleanComponanteName', $names)));
check('aucun nom de commande en double', array_values($doublons), array());

/* ================================================= HORODATAGE, IDENTIFIANT */
section('timestamp() et decodeHexId()');

check('entier AAMMJJhhmmss', homewizardbe::timestamp(260925061500), '2026-09-25 06:15:00');
check('même chose en chaîne', homewizardbe::timestamp('260925061500'), '2026-09-25 06:15:00');
check('zéro initial perdu par l\'entier (janvier 2001)', homewizardbe::timestamp(10101000000), '2001-01-01 00:00:00');
check('ISO v2', homewizardbe::timestamp('2026-09-25T06:15:00'), '2026-09-25 06:15:00');
check('déjà lisible : inchangé', homewizardbe::timestamp('2026-09-25 06:15:00'), '2026-09-25 06:15:00');

check('hexadécimal imprimable décodé', homewizardbe::decodeHexId('3153414731313030303030303031'), '1SAG1100000001');
check('majuscules acceptées', homewizardbe::decodeHexId('4A4B'), 'JK');
check('longueur impaire : inchangé', homewizardbe::decodeHexId('315'), '315');
check('pas hexadécimal : inchangé', homewizardbe::decodeHexId('1SAG1100000001'), '1SAG1100000001');
check('décodage non imprimable : inchangé', homewizardbe::decodeHexId('0001'), '0001');
check('vide : vide', homewizardbe::decodeHexId(''), '');
check('null : chaîne vide', homewizardbe::decodeHexId(null), '');

/* ============================================================= INGESTION */
section('ingest() de bout en bout');

cmd::reset();
log::$lines = array();
$eq = device('HWE-P1', '', 7);
$ok = $eq->ingest(array(
    'info'     => array('ok' => true, 'body' => body('p1_api')),
    'data'     => array('ok' => true, 'body' => body('p1_data')),
    'system'   => array('ok' => true, 'body' => body('p1_system')),
    'telegram' => array('ok' => true, 'body' => body('p1_telegram.txt')),
));
$p = $eq->published;
check('relevé accepté', $ok, true);
check('en ligne', $p['online'], 1);
check('puissance publiée', $p['power_w'], -2157);
check('puissance injectée calculée', $p['power_export_w'], 2157.0);
check('identifiant du compteur publié', $p['unique_id'], '1SAG1100000001');
check('cloud publié', $p['cloud_enabled'], 1);
check('moyenne capacitaire publiée', isset($p['capacity_avg_12m_w']), true);
check('import du jour publié, à zéro au premier relevé', $p['import_today'], 0.0);
check('info : numéro de série en minuscules', $eq->getConfiguration('serial'), '5c2faf000001');
check('info : firmware recopié', $eq->getConfiguration('firmware'), '6.0305');
check('info : équipement enregistré une fois', $eq->saved, 1);
check('brut gardé pour la page', array_keys($eq->getCache('raw')), array('info', 'data', 'system', 'telegram'));
check('horodatage du relevé', $eq->getCache('raw_at') !== '', true);

$info = array();
$actions = array();
foreach (cmd::$table as $cmd) {
    if ($cmd->type === 'info') {
        $info[$cmd->logicalId] = $cmd;
    } else {
        $actions[] = $cmd->logicalId;
    }
}
sort($actions);
check('une commande info par grandeur publiée', count(array_diff_key($p, $info)), 0);
check('actions d\'un P1 en v1', $actions, array('cloud_off', 'cloud_on', 'identify', 'refresh'));
check('action cloud rattachée à son info', cmd::byEqLogicIdCmdName(7, 'Activer le cloud')->getValue(), $info['cloud_enabled']->getId());
check('puissance : unité, générique, visible, historisée',
    array($info['power_w']->unite, $info['power_w']->generic_type, $info['power_w']->isVisible, $info['power_w']->isHistorized),
    array('W', 'POWER', 1, 1));
/* L'apostrophe typographique, elle, traverse cleanComponanteName(). */
check('nom enregistré sans perdre son apostrophe',
    $info['energy_import_kwh']->name, 'Importation d’énergie');

$saves = cmd::$saves;
$count = count(cmd::$table);
$eq->ingest(array('data' => array('ok' => true, 'body' => body('p1_data'))));
check('second relevé : aucune commande recréée ni récrite', array(count(cmd::$table), cmd::$saves), array($count, $saves));

/* Passage en v2 sur le même équipement : rien ne se recrée pour ce qui
 * existait, seules les grandeurs nouvelles apparaissent. */
$before = array_keys($info);
$eq->setConfiguration('token', 'abcdef');
$eq->ingest(array(
    'data'   => array('ok' => true, 'body' => body('p1_v2_measurement')),
    'system' => array('ok' => true, 'body' => body('p1_v2_system')),
));
$after = array();
foreach (cmd::$table as $cmd) {
    if ($cmd->type === 'info') {
        $after[$cmd->logicalId] = $cmd;
    }
}
$dup = array();
foreach (cmd::$table as $a) {
    foreach (cmd::$table as $b) {
        if ($a !== $b && $a->type === $b->type && $a->logicalId === $b->logicalId) {
            $dup[$a->logicalId] = true;
        }
    }
}
check('v1 → v2 : aucune commande en double', array_keys($dup), array());
check('v1 → v2 : commandes v1 toujours alimentées', $eq->published['energy_import_kwh'], 5764.273);
check('v2 : compteur de gaz créé', isset($after['ext_gas_meter']), true);
check('v2 : unité du gaz sur la commande', $after['ext_gas_meter']->unite, 'm³');
check('v2 : clé d\'unité jamais publiée', isset($eq->published['_unit_ext_gas_meter']), false);
check('v2 : force du Wi-Fi recalculée', $eq->published['wifi_strength'], 66);

/* Une ressource secondaire en erreur ne met pas l'appareil hors ligne. */
$ok = $eq->ingest(array(
    'data'     => array('ok' => true, 'body' => body('p1_v2_measurement')),
    'telegram' => array('ok' => false, 'code' => 503, 'error' => 'Le P1 n\'a pas encore reçu de télégramme du compteur.'),
));
check('télégramme en 503 : relevé accepté', $ok, true);
check('télégramme en 503 : toujours en ligne', $eq->published['online'], 1);
check('télégramme en 503 : aucun échec compté', (int) $eq->getCache('failures', 0), 0);
check('télégramme en 503 : noté au journal (debug)',
    count(preg_grep('/^debug .*\[telegram\]/', log::$lines)), 1);

/* Le démon transmet code et corps bruts : ingest() les traduit. */
log::$lines = array();
$eq->ingest(array(
    'data'     => array('ok' => true, 'body' => body('p1_v2_measurement')),
    'telegram' => array('ok' => false, 'code' => 503, 'body' => '{"error":"telegram:no-telegram-received"}'),
));
check('erreur brute du démon traduite', count(preg_grep('/\[telegram\] : Le P1 n.a pas encore reçu/', log::$lines)), 1);
$eq->ingest(array('data' => array('ok' => false, 'code' => 401, 'body' => '{"error":"user:unauthorized"}')));
check('jeton refusé : message qui dit quoi faire', $eq->getCache('problem'), 'Jeton v2 refusé. Refaites l\'association depuis la page de l\'équipement.');
$eq->ingest(array('data' => array('ok' => true, 'body' => body('p1_v2_measurement'))));

/* Mesures injoignables : hors ligne, échecs comptés. */
$ok = $eq->ingest(array('data' => array('ok' => false, 'error' => 'Appareil injoignable')));
check('mesures en échec : refusé', $ok, false);
check('mesures en échec : hors ligne', $eq->published['online'], 0);
check('mesures en échec : un échec', $eq->getCache('failures'), 1);
check('mesures en échec : problème retenu', $eq->getCache('problem'), 'Appareil injoignable');
$eq->ingest(array('data' => array('ok' => false, 'error' => 'Appareil injoignable'),
                  'system' => array('ok' => true, 'body' => body('p1_v2_system'))));
check('mesures en échec, système joignable : toujours hors ligne', $eq->getCache('failures'), 2);
$eq->ingest(array('system' => array('ok' => false, 'error' => 'Délai dépassé')));
check('rien ne revient : hors ligne aussi', $eq->getCache('failures'), 3);
check('troisième échec : un seul avertissement', count(preg_grep('/^warning /', log::$lines)), 1);
$eq->ingest(array('data' => array('ok' => true, 'body' => body('p1_v2_measurement'))));
check('retour : échecs remis à zéro', $eq->getCache('failures'), 0);
check('retour : problème effacé', $eq->getCache('problem'), '');
check('retour : de nouveau en ligne', $eq->published['online'], 1);
check('retour : annoncé au journal', count(preg_grep('/^info .*de nouveau joignable/', log::$lines)), 1);

/* Prise en v1 : l'état arrive par /api/v1/state, les actions s'y rattachent. */
cmd::reset();
$eq = device('HWE-SKT', '', 9);
$eq->ingest(array(
    'data'  => array('ok' => true, 'body' => body('socket_v1')),
    'state' => array('ok' => true, 'body' => body('socket_v1_state')),
));
$state = null;
$actions = array();
foreach (cmd::$table as $cmd) {
    if ($cmd->type === 'info' && $cmd->logicalId === 'power_on') {
        $state = $cmd;
    }
    if ($cmd->type === 'action') {
        $actions[$cmd->logicalId] = $cmd;
    }
}
check('prise : état publié', $eq->published['power_on'], 1);
check('prise : Allumer rattaché à l\'état', $actions['power_on_set']->value, $state->id);
check('prise : réglage du voyant 0-100', array($actions['led_set']->subType,
    $actions['led_set']->configuration['minValue'], $actions['led_set']->configuration['maxValue']), array('slider', 0, 100));
check('prise v1 : ni reboot ni batteries', isset($actions['reboot']) || isset($actions['battery_mode_set']), false);
check('prise : facteur de puissance publié en %', $eq->published['power_factor'], 88.4);

/* ================================================================== BILAN */
echo "\n" . str_repeat('=', 70) . "\n";
printf("%d réussite(s), %d échec(s)\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
