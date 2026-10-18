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
 * Démon de relevé rapide des appareils HomeWizard.
 *
 * Il n'existe que pour descendre sous la minute : le cron de Jeedom ne sait pas
 * faire mieux, et un P1 publie une nouvelle valeur chaque seconde. Il est
 * volontairement bête. Il ne connaît ni les champs, ni les commandes, ni les
 * unités : il reçoit du plugin une liste d'URL à lire et une période pour
 * chacune, les lit, et renvoie les réponses brutes au plugin, qui décode.
 * Toute la connaissance de l'API reste ainsi dans la classe, à un seul
 * endroit, et le relevé par cron passe exactement par le même décodage.
 *
 * Il ne charge pas core.inc.php : un démon qui embarque le coeur vieillit mal
 * (cache de configuration jamais invalidé, mises à jour du coeur sous ses
 * pieds). PHP en ligne de commande et l'extension curl, déjà exigée par Jeedom,
 * suffisent : aucune dépendance à installer.
 *
 *   php homewizardbed.php --callback URL --pid FICHIER --stamp FICHIER --loglevel debug --timezone Europe/Brussels
 *
 * La clé API arrive par l'entrée standard, jamais en argument : ps est lisible
 * par tous les utilisateurs de la machine.
 */

error_reporting(E_ALL);
set_time_limit(0);
$options = getopt('', array('callback:', 'pid:', 'stamp:', 'loglevel:', 'timezone:'));
/* Le PHP en ligne de commande est souvent en UTC quand Jeedom est à l'heure
 * locale : sans le fuseau du plugin, le journal du démon serait décalé. */
if (!empty($options['timezone']) && in_array($options['timezone'], timezone_identifiers_list(), true)) {
    date_default_timezone_set($options['timezone']);
}
$callback = isset($options['callback']) ? $options['callback'] : '';
$pidFile  = isset($options['pid']) ? $options['pid'] : '';
$stamp    = isset($options['stamp']) ? $options['stamp'] : '';
$logLevel = isset($options['loglevel']) ? $options['loglevel'] : 'error';
$apiKey   = trim((string) fgets(STDIN));

$levels = array('debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'none' => 4);
$threshold = isset($levels[$logLevel]) ? $levels[$logLevel] : 3;

function hwLog($_level, $_message) {
    global $levels, $threshold;
    if ($levels[$_level] < $threshold) {
        return;
    }
    echo '[' . date('Y-m-d H:i:s') . '][' . strtoupper($_level) . '] : ' . $_message . "\n";
}

if ($callback === '' || $pidFile === '' || $apiKey === '') {
    hwLog('error', 'Arguments manquants : --callback, --pid et la clé API sur l\'entrée standard sont obligatoires.');
    exit(1);
}
if (!function_exists('curl_init')) {
    hwLog('error', 'L\'extension PHP curl est absente.');
    exit(1);
}

file_put_contents($pidFile, (string) getmypid());

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) { $running = false; };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

/* ------------------------------------------------------------- DIALOGUE */

function hwCallback($_query, $_body = null) {
    global $callback, $apiKey;
    $url = $callback . (strpos($callback, '?') === false ? '?' : '&')
         . 'apikey=' . rawurlencode($apiKey) . ($_query !== '' ? '&' . $_query : '');
    $ch = curl_init($url);
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_PROXY          => '',
        /* Le callback est en boucle locale, parfois derrière un certificat
         * auto-signé si l'accès interne est en https. */
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    );
    if ($_body !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $_body;
        $opts[CURLOPT_HTTPHEADER] = array('Content-Type: application/json');
    }
    curl_setopt_array($ch, $opts);
    $answer = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($answer === false || $code !== 200) {
        hwLog('warning', 'Callback Jeedom en échec (' . $code . ') ' . $error);
        return null;
    }
    return $answer;
}

function hwLoadConfig() {
    $answer = hwCallback('action=config');
    if ($answer === null) {
        return null;
    }
    $config = json_decode($answer, true);
    if (!is_array($config) || !isset($config['devices']) || !is_array($config['devices'])) {
        hwLog('error', 'Configuration illisible reçue de Jeedom.');
        return null;
    }
    return $config;
}

/* ------------------------------------------------------------- APPAREILS */

/*
 * Lecture d'une ressource d'un appareil. Le plugin fournit l'URL complète, les
 * en-têtes (jeton v2 compris) et dit s'il faut accepter un certificat
 * auto-signé : l'API v2 est en https avec un certificat signé par HomeWizard,
 * que rien sur la machine ne sait vérifier.
 */
function hwFetch($_request, $_timeout) {
    $ch = curl_init($_request['url']);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => max(1, min(3, $_timeout)),
        CURLOPT_TIMEOUT        => $_timeout,
        CURLOPT_PROXY          => '',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => isset($_request['headers']) ? $_request['headers'] : array(),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false || $error !== '') {
        return array('ok' => false, 'error' => $error !== '' ? $error : 'pas de réponse');
    }
    if ($code !== 200) {
        return array('ok' => false, 'error' => 'HTTP ' . $code, 'code' => $code, 'body' => substr((string) $body, 0, 500));
    }
    return array('ok' => true, 'body' => $body);
}

/* ----------------------------------------------------------------- BOUCLE */

$config = null;
$configHash = '';
$stampSeen = -1;
$configAt = 0;
$configRetryAt = 0;   /* pas de nouvelle lecture de config avant cet instant */
$configFailures = 0;  /* lectures de config ratées d'affilée */
$pushFailures = 0;    /* envois de relevés ratés d'affilée */
$due = array();       /* [eq][clé] => instant du prochain relevé */
$failures = array();  /* [eq] => échecs consécutifs */

/* Seuil à partir duquel un callback en échec est signalé en erreur : le démon
 * tourne d'ordinaire en --loglevel error, qui masque les avertissements. */
define('HW_FAILURE_ALERT', 3);

hwLog('info', 'Démarrage du démon HomeWizard (PID ' . getmypid() . ')');

while ($running) {
    $now = microtime(true);

    /* La configuration est relue quand le plugin le signale — il touche un
     * fichier témoin à chaque enregistrement d'équipement — et de toute façon
     * toutes les cinq minutes, pour ne jamais rester désynchronisé. Un relevé
     * n'est relancé en rafale que si la config a vraiment changé : la relecture
     * périodique seule ne doit pas réinitialiser les échéances. */
    clearstatcache();
    $mtime = ($stamp !== '' && file_exists($stamp)) ? filemtime($stamp) : 0;
    /* Si Jeedom ne répond pas, on espace les relectures (5, 10, 20, 40 puis
     * 60 s) au lieu de le réinterroger à chaque tour de boucle. */
    if (($config === null || $mtime !== $stampSeen || $now - $configAt > 300) && $now >= $configRetryAt) {
        $fresh = hwLoadConfig();
        if ($fresh !== null) {
            if ($configFailures >= HW_FAILURE_ALERT) {
                hwLog('error', 'Configuration de nouveau lue après ' . $configFailures . ' échec(s)');
            }
            $configFailures = 0;
            $configRetryAt = 0;
            $freshHash = md5(json_encode($fresh));
            if ($freshHash !== $configHash) {
                $config = $fresh;
                $configHash = $freshHash;
                $due = array();
                hwLog('info', count($config['devices']) . ' appareil(s) à relever');
            }
            $stampSeen = $mtime;
            $configAt = $now;
        } else {
            $configFailures++;
            $retryIn = min(60, 5 * (1 << min(4, $configFailures - 1)));
            $configRetryAt = $now + $retryIn;
            if ($configFailures === HW_FAILURE_ALERT) {
                hwLog('error', 'Configuration illisible depuis Jeedom (' . $configFailures . ' échecs) — nouvel essai toutes les ' . $retryIn . ' s au plus');
            }
        }
    }
    if ($config === null) {
        sleep(1);
        continue;
    }

    $timeout = isset($config['timeout']) ? max(1, (int) $config['timeout']) : 3;
    $batch = array();

    foreach ($config['devices'] as $device) {
        $id = (int) $device['id'];
        $results = array();
        foreach ($device['requests'] as $key => $request) {
            if (isset($due[$id][$key]) && $due[$id][$key] > $now) {
                continue;
            }
            $every = max(1, (int) $request['every']);
            $result = hwFetch($request, $timeout);
            $results[$key] = $result;

            /* Une réponse HTTP, même en erreur (télégramme pas encore reçu,
             * ressource absente), prouve que l'appareil est là : on la
             * reprogramme à son rythme sans ralentir le reste. */
            if ($result['ok'] || isset($result['code'])) {
                $due[$id][$key] = $now + $every;
                continue;
            }
            /* Un appareil injoignable est retenté de plus en plus rarement,
             * pour ne pas bloquer les autres à chaque tour sur son délai. */
            $failures[$id] = isset($failures[$id]) ? $failures[$id] + 1 : 1;
            $wait = min(120, $every * (1 << min(5, $failures[$id])));
            foreach ($device['requests'] as $k => $r) {
                $due[$id][$k] = $now + $wait;
            }
            hwLog('debug', $device['name'] . ' : ' . $result['error'] . ' — nouvel essai dans ' . $wait . ' s');
            break;
        }
        if (!empty($results)) {
            $allOk = true;
            foreach ($results as $r) {
                $allOk = $allOk && $r['ok'];
            }
            if ($allOk) {
                $failures[$id] = 0;
            }
            $batch[] = array('eq_id' => $id, 'results' => $results);
        }
    }

    if (!empty($batch)) {
        $answer = hwCallback('', json_encode(array('batch' => $batch)));
        if ($answer === null) {
            $pushFailures++;
            if ($pushFailures === HW_FAILURE_ALERT) {
                hwLog('error', 'Envoi des relevés à Jeedom en échec (' . $pushFailures . ' fois d\'affilée)');
            }
        } else {
            if ($pushFailures >= HW_FAILURE_ALERT) {
                hwLog('error', 'Envoi des relevés rétabli après ' . $pushFailures . ' échec(s)');
            }
            $pushFailures = 0;
            if (trim($answer) !== 'OK') {
                hwLog('warning', 'Réponse inattendue de Jeedom : ' . substr($answer, 0, 200));
            }
        }
    }

    /* Le plus petit pas utile : les périodes sont en secondes entières. */
    usleep(250000);
}

@unlink($pidFile);
hwLog('info', 'Arrêt du démon HomeWizard');
