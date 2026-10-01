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

/*
 * Relevé local des appareils HomeWizard Energy.
 *
 * Deux API coexistent sur les appareils :
 *   - v1 : HTTP en clair, sans authentification, à activer dans l'application
 *     (« API locale »). Plus développée, mais toujours là sur P1, prises,
 *     compteurs kWh et compteur d'eau.
 *   - v2 : HTTPS avec un jeton obtenu en appuyant sur le bouton de l'appareil.
 *     Seule voie pour la batterie, et seule voie pour régler la LED d'un P1.
 *
 * Les deux ne nomment pas les champs de la même façon. Tout est ramené aux noms
 * de la v2 dès la réception (normalize()), et les commandes portent ces noms
 * pour identifiant logique : passer un appareil de v1 à v2 ne change donc aucune
 * commande, et ne perd ni historique ni scénario.
 *
 * Les commandes ne sont pas devinées d'après le modèle : elles sont créées au
 * premier relevé qui contient le champ, exactement comme Home Assistant. Un P1
 * monophasé n'a pas de phase 3, un compteur sans gaz n'a pas de gaz.
 */
class homewizardbe extends eqLogic {

    /* ============================================================= RÉGLAGES */

    const DEFAULT_TIMEOUT = 3;

    /* Secondes entre deux relevés, si rien n'a été choisi. Le P1 publie chaque
     * seconde ; dix secondes suivent bien une consommation sans remplir
     * l'historique. */
    const DEFAULT_INTERVAL = 10;

    /* Temps que cron() s'autorise quand il relève à la place du démon. */
    const CRON_BUDGET = 40;

    /* Garde-fou du bouton « Relever maintenant ». */
    const FORCE_MIN_INTERVAL = 3;

    /* Plancher du tarif capacitaire belge : un pic mensuel plus faible compte
     * pour 2,5 kW dans la moyenne. */
    const CAPACITY_FLOOR_W = 2500;

    /* Le nom sous lequel Jeedom s'annonce en demandant un jeton v2. */
    const USER_NAME = 'local/jeedom';

    private static $_reloadScheduled = false;

    /* ============================================================ MODÈLES */

    const MODELS = array(
        'HWE-P1'      => array('P1 Meter', 'fas fa-tachometer-alt', 'p1'),
        'HWE-SKT'     => array('Energy Socket', 'fas fa-plug', 'socket'),
        'HWE-WTR'     => array('Watermeter', 'fas fa-tint', 'water'),
        'HWE-KWH1'    => array('kWh Meter 1 phase', 'fas fa-bolt', 'kwh'),
        'HWE-KWH3'    => array('kWh Meter 3 phases', 'fas fa-bolt', 'kwh'),
        'SDM230-wifi' => array('kWh Meter 1 phase (SDM230)', 'fas fa-bolt', 'kwh'),
        'SDM630-wifi' => array('kWh Meter 3 phases (SDM630)', 'fas fa-bolt', 'kwh'),
        'HWE-BAT'     => array('Plug-In Battery', 'fas fa-car-battery', 'battery'),
        'HWE-DSP'     => array('Energy Display', 'fas fa-desktop', 'display'),
    );

    const EXTERNAL_TYPES = array(
        'gas_meter'        => 'Compteur de gaz',
        'water_meter'      => 'Compteur d’eau',
        'heat_meter'       => 'Compteur de chaleur',
        'warm_water_meter' => 'Compteur d’eau chaude',
        'inlet_heat_meter' => 'Compteur de chaleur (entrée)',
    );

    const BATTERY_MODES = array(
        'zero'                => 'Zéro net',
        'zero_charge_only'    => 'Zéro net (charge uniquement)',
        'zero_discharge_only' => 'Zéro net (décharge uniquement)',
        'to_full'             => 'Charge complète unique',
        'standby'             => 'En veille',
        'predictive'          => 'Charge intelligente',
    );

    /* ================================================== DÉMON ET CRON */

    public static function deamon_info() {
        $return = array('log' => __CLASS__ . 'd', 'state' => 'nok', 'launchable' => 'nok');

        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '' && @posix_getsid((int) $pid)) {
                $return['state'] = 'ok';
            } else {
                @unlink($pid_file);
            }
        }

        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            if ($eqLogic->isConfigured()) {
                $return['launchable'] = 'ok';
                break;
            }
        }
        if ($return['launchable'] != 'ok') {
            $return['launchable_message'] = __('Aucun appareil actif n\'est configuré.', __FILE__);
        }
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();

        $info = self::deamon_info();
        if ($info['launchable'] != 'ok') {
            throw new Exception(__('Le démon ne peut pas être lancé :', __FILE__) . ' ' . $info['launchable_message']);
        }

        $daemon = realpath(__DIR__ . '/../../resources/homewizardbed/homewizardbed.php');
        $cmd  = 'php ' . escapeshellarg($daemon);
        $cmd .= ' --callback ' . escapeshellarg(self::getCallbackUrl());
        $cmd .= ' --pid ' . escapeshellarg(jeedom::getTmpFolder(__CLASS__) . '/deamon.pid');
        $cmd .= ' --stamp ' . escapeshellarg(self::stampFile());
        $cmd .= ' --loglevel ' . escapeshellarg(log::convertLogLevel(log::getLogLevel(__CLASS__)));
        $cmd .= ' --timezone ' . escapeshellarg(date_default_timezone_get());

        /* La clé API passe par l'entrée standard, jamais en argument : ps est
         * lisible par n'importe quel utilisateur local. */
        $full = 'echo ' . escapeshellarg(jeedom::getApiKey(__CLASS__)) . ' | ' . $cmd
              . ' >> ' . log::getPathToLog(__CLASS__ . 'd') . ' 2>&1 &';

        log::add(__CLASS__, 'info', __('Lancement du démon', __FILE__));
        exec($full);

        for ($i = 1; $i <= 20; $i++) {
            if (self::deamon_info()['state'] == 'ok') {
                message::removeAll(__CLASS__, 'unableStartDeamon');
                return true;
            }
            sleep(1);
        }
        log::add(__CLASS__, 'error', __('Le démon n\'a pas démarré. Consultez le journal', __FILE__) . ' ' . __CLASS__ . 'd.');
        return false;
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '') {
                system::kill($pid);
            }
            @unlink($pid_file);
        }
        system::kill('resources/homewizardbed/homewizardbed.php');
        return true;
    }

    public static function getCallbackUrl() {
        return network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp')
             . '/plugins/homewizardbe/core/php/jeeHomewizardbe.php';
    }

    public static function stampFile() {
        return jeedom::getTmpFolder(__CLASS__) . '/config.stamp';
    }

    /* Le démon relit sa configuration quand ce fichier change. Pas de socket à
     * ouvrir : un simple horodatage suffit, et il ne se perd jamais. */
    public static function reloadDaemonConfig() {
        if (self::$_reloadScheduled) {
            return;
        }
        self::$_reloadScheduled = true;
        register_shutdown_function(function () {
            try {
                @touch(homewizardbe::stampFile());
                if (homewizardbe::deamon_info()['state'] != 'ok'
                    && config::byKey('deamonAutoMode', 'homewizardbe', 1) == 1
                    && homewizardbe::deamon_info()['launchable'] == 'ok') {
                    /* La réponse est déjà complète : on la rend au navigateur
                     * avant d'attendre que le démon ait écrit son PID. */
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    homewizardbe::deamon_start();
                }
            } catch (Throwable $e) {
                log::add('homewizardbe', 'error', __('Rechargement du démon :', __FILE__) . ' ' . $e->getMessage());
            }
        });
    }

    /* Ce que le démon doit lire, appareil par appareil. Autosuffisant : il ne
     * charge pas le coeur et ne peut rien relire d'autre. */
    public static function getDaemonConfig() {
        $devices = array();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            if (!$eqLogic->isConfigured()) {
                continue;
            }
            $devices[] = array(
                'id'       => (int) $eqLogic->getId(),
                'name'     => $eqLogic->getHumanName(),
                'requests' => $eqLogic->pollPlan(),
            );
        }
        return array('timeout' => self::timeout(), 'devices' => $devices);
    }

    /*
     * Relevé de secours, quand le démon ne tourne pas : une fois par minute au
     * mieux, ce que le cron de Jeedom permet. Le démon arrêté ne doit pas
     * rendre le plugin muet.
     */
    public static function cron() {
        if (self::deamon_info()['state'] == 'ok') {
            return;
        }
        $deadline = microtime(true) + self::CRON_BUDGET;
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            if (microtime(true) > $deadline) {
                break;
            }
            try {
                if ($eqLogic->isConfigured()) {
                    $eqLogic->pollNow(false);
                }
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* =================================================== CYCLE DE VIE */

    /* Aucune exception ici : le coeur crée l'équipement avec son seul nom. */
    public function preSave() {
        if ($this->getId() == '') {
            $this->setIsEnable(1);
            $this->setIsVisible(1);
        }
        if ((int) $this->getConfiguration('interval', 0) <= 0) {
            $this->setConfiguration('interval', self::DEFAULT_INTERVAL);
        }
        if ($this->getConfiguration('capacity', '') === '') {
            $this->setConfiguration('capacity', 1);
        }
        $this->setConfiguration('ip', trim((string) $this->getConfiguration('ip', '')));
        $this->setConfiguration('token', trim((string) $this->getConfiguration('token', '')));
        $serial = strtolower(trim((string) $this->getConfiguration('serial', '')));
        if ($serial !== '') {
            $this->setLogicalId($serial);
        }
    }

    public function postSave() {
        $this->createCommands(array());
        self::reloadDaemonConfig();

        if (!$this->isConfigured()) {
            return;
        }
        $signature = $this->getConfiguration('ip') . '|' . $this->getConfiguration('token');
        if ($this->getCache('signature', '') !== $signature) {
            $this->setCache('signature', $signature);
            $this->setCache('due', array());
            $this->setCache('failures', 0);
        }
    }

    /* Supprimer l'équipement laisserait sinon le jeton gravé dans l'appareil,
     * qui accumule les utilisateurs orphelins au fil des recréations. On tente
     * le DELETE côté appareil sans sauvegarder : la suppression suit juste
     * après, inutile de récrire le token. */
    public function preRemove() {
        if (!$this->usesV2()) {
            return;
        }
        try {
            $this->call('DELETE', '/api/user', array('name' => self::USER_NAME . '#' . $this->getId()));
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', __('Dépairage avant suppression :', __FILE__) . ' ' . $e->getMessage());
        }
    }

    public function postRemove() {
        self::reloadDaemonConfig();
    }

    public function isConfigured() {
        return trim((string) $this->getConfiguration('ip', '')) !== '';
    }

    public function usesV2() {
        return trim((string) $this->getConfiguration('token', '')) !== '';
    }

    public function family() {
        $type = (string) $this->getConfiguration('product_type', '');
        return isset(self::MODELS[$type]) ? self::MODELS[$type][2] : '';
    }

    /* La prise et le compteur d'eau restent en v1 pour l'instant ; un type
     * inconnu (appareil v2 seul, pas encore identifié) peut être associé. */
    public static function supportsV2($_productType) {
        return !in_array($_productType, array('HWE-SKT', 'HWE-WTR'), true);
    }

    public static function iconOf($_productType) {
        return isset(self::MODELS[$_productType]) ? self::MODELS[$_productType][1] : 'fas fa-bolt';
    }

    /* ==================================================== COUCHE RÉSEAU */

    public static function timeout() {
        $timeout = (int) config::byKey('api_timeout', __CLASS__, self::DEFAULT_TIMEOUT);
        return ($timeout > 0 && $timeout <= 15) ? $timeout : self::DEFAULT_TIMEOUT;
    }

    public static function baseUrl($_ip, $_v2) {
        $ip = trim((string) $_ip);
        if (strpos($ip, ':') !== false && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ip = '[' . $ip . ']';
        }
        return ($_v2 ? 'https://' : 'http://') . $ip;
    }

    public static function headers($_token) {
        $headers = array('Accept: application/json');
        if ($_token !== '') {
            $headers[] = 'Authorization: Bearer ' . $_token;
            /* Sans cet en-tête l'appareil répond dans sa version la plus
             * récente : on épingle la 2 pour ne pas subir un renommage futur. */
            $headers[] = 'X-Api-Version: 2';
        }
        return $headers;
    }

    /*
     * Un appel à l'appareil. L'API v2 est en HTTPS avec un certificat signé par
     * l'autorité de HomeWizard, que rien sur la machine ne connaît : la
     * vérification est désactivée, comme le fait l'application elle-même sur un
     * réseau local. Le jeton, lui, reste la vraie protection.
     */
    public static function request($_method, $_url, $_headers, $_body = null) {
        $ch = curl_init($_url);
        if ($ch === false) {
            throw new Exception(__('Impossible d\'initialiser la requête HTTP.', __FILE__));
        }
        $timeout = self::timeout();
        $headers = $_headers;
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, min(3, $timeout)),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY          => '',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CUSTOMREQUEST  => $_method,
        );
        if ($_body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($_body);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new Exception(sprintf(__('Appareil injoignable (%s) : %s', __FILE__), $_url, $error));
        }
        return array('code' => $code, 'body' => (string) $body);
    }

    /* Traduit une réponse d'erreur de l'appareil en message qui dit quoi faire. */
    public static function explainError($_code, $_body) {
        $data = json_decode($_body, true);
        $id = '';
        if (is_array($data) && isset($data['error'])) {
            $id = is_array($data['error']) ? (isset($data['error']['id']) ? (string) $data['error']['id'] : '') : (string) $data['error'];
        }
        if ($id === '202' || ($_code == 403 && strpos($_body, 'API not enabled') !== false)) {
            return __('L\'API locale v1 est désactivée. Activez-la dans l\'application HomeWizard Energy (Réglages → Compteurs → l\'appareil → API locale), ou associez l\'appareil en v2.', __FILE__);
        }
        if ($id === 'user:unauthorized') {
            return __('Jeton v2 refusé. Refaites l\'association depuis la page de l\'équipement.', __FILE__);
        }
        if ($id === 'telegram:no-telegram-received') {
            return __('Le P1 n\'a pas encore reçu de télégramme du compteur.', __FILE__);
        }
        return sprintf(__('L\'appareil a répondu %s', __FILE__), $_code) . ($id !== '' ? ' (' . $id . ')' : '');
    }

    /* Appel vers l'appareil de cet équipement, dans l'API qu'il utilise. */
    public function call($_method, $_path, $_body = null) {
        if (!$this->isConfigured()) {
            throw new Exception(__('Aucune adresse IP n\'est renseignée.', __FILE__));
        }
        $v2 = $this->usesV2();
        $answer = self::request($_method, self::baseUrl($this->getConfiguration('ip'), $v2) . $_path,
            self::headers($v2 ? (string) $this->getConfiguration('token') : ''), $_body);
        if ($answer['code'] < 200 || $answer['code'] >= 300) {
            throw new Exception(self::explainError($answer['code'], $answer['body']));
        }
        $data = json_decode($answer['body'], true);
        return is_array($data) ? $data : array();
    }

    /* =================================================== IDENTIFICATION */

    /*
     * Ce qui répond à une adresse. En v1, /api dit tout sans authentification.
     * Un appareil v2 seul (la batterie) répond 401 en HTTPS : on sait alors que
     * c'est un HomeWizard, pas lequel — l'association le dira.
     */
    public static function probe($_ip, $_token = '') {
        $ip = trim((string) $_ip);
        if ($ip === '') {
            throw new Exception(__('Aucune adresse IP n\'est renseignée.', __FILE__));
        }
        if ($_token !== '') {
            $answer = self::request('GET', self::baseUrl($ip, true) . '/api', self::headers($_token));
            if ($answer['code'] == 200) {
                $info = json_decode($answer['body'], true);
                if (is_array($info) && isset($info['product_type'])) {
                    $info['ip'] = $ip;
                    return $info;
                }
            }
        }
        try {
            $answer = self::request('GET', self::baseUrl($ip, false) . '/api', self::headers(''));
            $info = json_decode($answer['body'], true);
            if ($answer['code'] == 200 && is_array($info) && isset($info['product_type'])) {
                $info['ip'] = $ip;
                return $info;
            }
        } catch (Throwable $e) {
            /* Pas de HTTP : peut-être un appareil v2 seul. */
        }
        $answer = self::request('GET', self::baseUrl($ip, true) . '/api', self::headers(''));
        if ($answer['code'] == 401 && strpos($answer['body'], 'user:unauthorized') !== false) {
            return array('ip' => $ip, 'product_type' => '', 'product_name' => 'HomeWizard (API v2)',
                'serial' => '', 'firmware_version' => '', 'api_version' => 'v2');
        }
        throw new Exception(sprintf(__('Aucun appareil HomeWizard ne répond à l\'adresse %s.', __FILE__), $ip));
    }

    /*
     * Recherche sur le réseau local. Pas de mDNS : le multicast ne traverse ni
     * Docker ni bien des VLAN, alors qu'une question HTTP à chaque adresse du
     * sous-réseau passe partout. Les 254 adresses sont interrogées en parallèle,
     * l'ensemble tient en deux ou trois secondes.
     */
    public static function scan($_subnet = '') {
        $prefixes = array();
        $subnet = trim((string) $_subnet);
        if ($subnet !== '') {
            if (!preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3})(\.\d{1,3}(\/\d+)?)?$/', $subnet, $m)) {
                throw new Exception(__('Sous-réseau illisible : saisissez par exemple 192.168.1.0/24.', __FILE__));
            }
            $prefixes[] = $m[1];
        } else {
            foreach (self::localIps() as $ip) {
                $prefixes[] = substr($ip, 0, strrpos($ip, '.'));
            }
        }
        $prefixes = array_values(array_unique($prefixes));
        if (empty($prefixes)) {
            throw new Exception(__('Impossible de déterminer le réseau local : précisez un sous-réseau.', __FILE__));
        }

        /* Jeedom lui-même n'est pas un HomeWizard : l'interroger ne ferait que
         * salir le journal d'erreurs d'Apache. */
        $self = self::localIps();
        $multi = curl_multi_init();
        $handles = array();
        foreach ($prefixes as $prefix) {
            for ($i = 1; $i <= 254; $i++) {
                $ip = $prefix . '.' . $i;
                if (in_array($ip, $self, true)) {
                    continue;
                }
                $ch = curl_init('http://' . $ip . '/api');
                curl_setopt_array($ch, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT_MS => 1500,
                    CURLOPT_TIMEOUT_MS => 2500,
                    CURLOPT_PROXY => '',
                    CURLOPT_HTTPHEADER => array('Accept: application/json'),
                ));
                curl_multi_add_handle($multi, $ch);
                $handles[$ip] = $ch;
            }
        }
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 0.2);
            }
        } while ($active && $status == CURLM_OK);

        $found = array();
        foreach ($handles as $ip => $ch) {
            $body = curl_multi_getcontent($ch);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            $info = json_decode((string) $body, true);
            if (!is_array($info) || !isset($info['product_type']) || !isset($info['serial'])) {
                continue;
            }
            $info['ip'] = $ip;
            $existing = self::byLogicalId(strtolower((string) $info['serial']), __CLASS__);
            $info['known'] = is_object($existing) ? $existing->getHumanName() : '';
            $found[] = $info;
        }
        curl_multi_close($multi);
        return $found;
    }

    private static function localIps() {
        $ips = array();
        $out = array();
        @exec('hostname -I 2>/dev/null', $out);
        foreach (preg_split('/\s+/', trim(implode(' ', $out))) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($ip, '127.') !== 0
                && strpos($ip, '172.17.') !== 0) {
                $ips[] = $ip;
            }
        }
        if (empty($ips)) {
            $internal = parse_url(network::getNetworkAccess('internal'), PHP_URL_HOST);
            if (filter_var($internal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = $internal;
            }
        }
        return $ips;
    }

    /* Crée un équipement pour un appareil trouvé, ou met son adresse à jour
     * s'il existe déjà : une adresse qui change ne doit pas dédoubler. */
    public static function createFromProbe($_info) {
        $serial = isset($_info['serial']) ? strtolower(trim((string) $_info['serial'])) : '';
        $ip = isset($_info['ip']) ? trim((string) $_info['ip']) : '';
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new Exception(__('Adresse IP invalide.', __FILE__));
        }
        $eqLogic = $serial !== '' ? self::byLogicalId($serial, __CLASS__) : null;
        if (is_object($eqLogic)) {
            if ($eqLogic->getConfiguration('ip') !== $ip) {
                $eqLogic->setConfiguration('ip', $ip);
                $eqLogic->save();
            }
            return $eqLogic;
        }

        $type = isset($_info['product_type']) ? (string) $_info['product_type'] : '';
        $eqLogic = new self();
        $eqLogic->setEqType_name(__CLASS__);
        $eqLogic->setConfiguration('ip', $ip);
        $eqLogic->applyInfo($_info);
        $eqLogic->setConfiguration('interval', self::DEFAULT_INTERVAL);
        $eqLogic->setIsEnable(1);
        $eqLogic->setIsVisible(1);

        $base = isset(self::MODELS[$type]) ? 'HomeWizard ' . self::MODELS[$type][0]
            : (isset($_info['product_name']) && $_info['product_name'] !== '' ? (string) $_info['product_name'] : 'HomeWizard');
        /* Unicité (name, object_id) sur toute la table eqLogic : on numérote
         * plutôt que d'échouer. Toute autre erreur (base, contrainte étrangère)
         * ne se résoudra pas en changeant le numéro : on la laisse remonter. */
        for ($try = 1; $try <= 10; $try++) {
            $eqLogic->setName($try === 1 ? $base : $base . ' ' . $try);
            try {
                $eqLogic->save();
                /* Premier relevé tout de suite : l'utilisateur ouvre
                 * l'équipement et y trouve ses commandes, sans attendre le
                 * démon. L'appareil vient de répondre, c'est l'affaire de
                 * quelques millisecondes. */
                try {
                    $eqLogic->pollNow(true);
                } catch (Throwable $e) {
                    log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
                }
                return $eqLogic;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $nameClash = stripos($msg, 'duplicate') !== false
                          || stripos($msg, 'déjà') !== false
                          || stripos($msg, 'existe') !== false;
                if (!$nameClash || $try === 10) {
                    throw $e;
                }
            }
        }
        return $eqLogic;
    }

    /* Recopie ce que /api dit de l'appareil. Renvoie vrai si quelque chose a
     * changé — l'appelant décide alors d'enregistrer. */
    public function applyInfo($_info) {
        $changed = false;
        $map = array('product_type' => 'product_type', 'product_name' => 'product_name',
                     'serial' => 'serial', 'firmware_version' => 'firmware', 'api_version' => 'api_version');
        foreach ($map as $field => $key) {
            if (!isset($_info[$field]) || (string) $_info[$field] === '') {
                continue;
            }
            $value = $field === 'serial' ? strtolower((string) $_info[$field]) : (string) $_info[$field];
            if ((string) $this->getConfiguration($key, '') !== $value) {
                $this->setConfiguration($key, $value);
                $changed = true;
            }
        }
        return $changed;
    }

    /* ======================================================= ASSOCIATION v2 */

    /*
     * Une tentative de création de jeton. L'appareil répond 403
     * « user:creation-not-enabled » tant qu'on n'a pas appuyé sur son bouton ;
     * la page rappelle donc cette méthode toutes les deux secondes, et le
     * premier 200 porte le jeton.
     *
     * Redemander un jeton sous le même nom invalide l'ancien : c'est voulu, une
     * nouvelle association remplace la précédente.
     */
    public function pairStep() {
        $ip = trim((string) $this->getConfiguration('ip', ''));
        if ($ip === '') {
            throw new Exception(__('Renseignez et enregistrez l\'adresse IP avant d\'associer.', __FILE__));
        }
        /* La prise et le compteur d'eau n'ont pas encore d'API v2 : un jeton
         * obtenu ferait passer tous leurs appels en HTTPS, dans le vide. */
        if (!self::supportsV2((string) $this->getConfiguration('product_type', ''))) {
            throw new Exception(__('Cet appareil ne propose que l\'API v1 : l\'association v2 n\'est pas possible.', __FILE__));
        }
        $answer = self::request('POST', self::baseUrl($ip, true) . '/api/user',
            array('Accept: application/json', 'X-Api-Version: 2'),
            array('name' => self::USER_NAME . '#' . $this->getId()));

        if ($answer['code'] == 403 && strpos($answer['body'], 'creation-not-enabled') !== false) {
            return array('state' => 'waiting');
        }
        $data = json_decode($answer['body'], true);
        if ($answer['code'] != 200 || !is_array($data) || empty($data['token'])) {
            throw new Exception(self::explainError($answer['code'], $answer['body']));
        }

        $this->setConfiguration('token', (string) $data['token']);
        try {
            $this->applyInfo(self::probe($ip, (string) $data['token']));
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', $e->getMessage());
        }
        $this->save();
        log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('associé en API v2.', __FILE__));
        return array('state' => 'paired');
    }

    public function unpair() {
        if ($this->usesV2()) {
            try {
                $this->call('DELETE', '/api/user', array('name' => self::USER_NAME . '#' . $this->getId()));
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', __('Suppression du jeton sur l\'appareil :', __FILE__) . ' ' . $e->getMessage());
            }
        }
        $this->setConfiguration('token', '');
        $this->save();
    }

    /* ============================================================ RELEVÉ */

    public function interval() {
        return max(1, (int) $this->getConfiguration('interval', self::DEFAULT_INTERVAL));
    }

    /*
     * Ce qu'il faut lire, et à quel rythme. Seules les mesures suivent
     * l'intervalle choisi ; le reste change rarement et n'a pas à être relu
     * chaque seconde.
     */
    public function pollPlan() {
        $v2 = $this->usesV2();
        $base = self::baseUrl($this->getConfiguration('ip'), $v2);
        $headers = self::headers($v2 ? (string) $this->getConfiguration('token') : '');
        $every = $this->interval();
        $family = $this->family();
        $plan = array();

        $plan['info'] = array('url' => $base . '/api', 'every' => 3600);
        $plan['data'] = array('url' => $base . ($v2 ? '/api/measurement' : '/api/v1/data'), 'every' => $every);

        if ($family !== 'kwh' || $v2) {
            $plan['system'] = array('url' => $base . ($v2 ? '/api/system' : '/api/v1/system'), 'every' => 60);
        } else {
            $plan['system'] = array('url' => $base . '/api/v1/system', 'every' => 300);
        }
        if ($family === 'socket' && !$v2) {
            $plan['state'] = array('url' => $base . '/api/v1/state', 'every' => $every);
        }
        if ($v2 && in_array($family, array('p1', 'kwh'), true)) {
            $plan['batteries'] = array('url' => $base . '/api/batteries', 'every' => 60);
        }
        /* Le télégramme porte l'historique des pics sur treize mois, que l'API
         * ne donne pas. Il ne bouge qu'au quart d'heure. */
        if ($family === 'p1' && (int) $this->getConfiguration('capacity', 1) === 1) {
            $plan['telegram'] = array('url' => $base . ($v2 ? '/api/telegram' : '/api/v1/telegram'), 'every' => 900);
        }

        foreach ($plan as $key => $request) {
            $plan[$key]['headers'] = $headers;
        }
        return $plan;
    }

    /*
     * Relevé fait par PHP lui-même : cron de secours et bouton de la page.
     * Même plan et même décodage que le démon.
     */
    public function pollNow($_force) {
        $plan = $this->pollPlan();
        $due = $_force ? array() : $this->getCache('due', array());
        $now = time();
        $results = array();

        foreach ($plan as $key => $request) {
            /* Le cron ne passe qu'une fois par minute : un intervalle plus court
             * revient à relire à chaque passage. */
            if (!$_force && isset($due[$key]) && $due[$key] > $now) {
                continue;
            }
            try {
                $answer = self::request('GET', $request['url'], $request['headers']);
                if ($answer['code'] == 200) {
                    $results[$key] = array('ok' => true, 'body' => $answer['body']);
                } else {
                    $results[$key] = array('ok' => false, 'code' => $answer['code'],
                        'error' => self::explainError($answer['code'], $answer['body']));
                }
            } catch (Throwable $e) {
                $results[$key] = array('ok' => false, 'error' => $e->getMessage());
                break;
            }
            $due[$key] = $now + (int) $request['every'] - 5;
        }
        $this->setCache('due', $due);
        if (empty($results)) {
            return false;
        }
        return $this->ingest($results);
    }

    public function forcePoll() {
        $now = time();
        if ($now - (int) $this->getCache('forced_at', 0) < self::FORCE_MIN_INTERVAL) {
            throw new Exception(sprintf(__('Patientez %s secondes entre deux relevés manuels.', __FILE__), self::FORCE_MIN_INTERVAL));
        }
        $this->setCache('forced_at', $now);
        if (!$this->pollNow(true)) {
            throw new Exception($this->getCache('problem', __('Relevé impossible.', __FILE__)));
        }
        return true;
    }

    /*
     * Point d'arrivée unique des réponses, qu'elles viennent du démon ou du
     * cron. $_results : clé du plan => {ok, body} ou {ok: false, error}.
     */
    public function ingest($_results) {
        $values = array();
        $raw = $this->getCache('raw', array());

        /* L'appareil est hors ligne quand ses mesures ne viennent pas, ou quand
         * rien de ce qu'on lui a demandé n'est revenu. Une ressource secondaire
         * en erreur (télégramme pas encore reçu, batteries absentes) ne l'est
         * pas : on la note au journal et on publie le reste. */
        $anyOk = false;
        foreach ($_results as $key => $result) {
            $anyOk = $anyOk || $result['ok'];
            /* Le démon rend le code et le corps bruts : c'est ici qu'on les
             * traduit en message qui dit quoi faire. */
            if (!$result['ok'] && isset($result['code'], $result['body'])) {
                $_results[$key]['error'] = self::explainError($result['code'], $result['body']);
            }
        }
        $failed = isset($_results['data']) && !$_results['data']['ok'] ? $_results['data'] : ($anyOk ? null : reset($_results));
        if ($failed !== null) {
            $this->noteFailure(isset($failed['error']) ? $failed['error'] : '');
            return false;
        }
        foreach ($_results as $key => $result) {
            if (!$result['ok']) {
                log::add(__CLASS__, 'debug', $this->getHumanName() . ' [' . $key . '] : ' . (isset($result['error']) ? $result['error'] : ''));
                continue;
            }
            if ($key === 'telegram') {
                $raw['telegram'] = (string) $result['body'];
                $values = array_merge($values, $this->capacityValues(self::parseTelegram($result['body'])));
                continue;
            }
            $data = json_decode((string) $result['body'], true);
            if (!is_array($data)) {
                continue;
            }
            $raw[$key] = $data;
            switch ($key) {
                case 'info':
                    if ($this->applyInfo($data)) {
                        $this->save(true);
                    }
                    break;
                case 'data':
                    $values = array_merge($values, self::normalize($data));
                    break;
                case 'system':
                    $values = array_merge($values, self::normalizeSystem($data));
                    break;
                case 'state':
                    $values = array_merge($values, self::normalizeState($data));
                    break;
                case 'batteries':
                    $values = array_merge($values, self::normalizeBatteries($data));
                    break;
            }
        }

        $this->setCache('raw', $raw);
        $this->setCache('raw_at', date('Y-m-d H:i:s'));
        $this->clearFailure();

        $values = array_merge($values, $this->derivedValues($values));
        $values['online'] = 1;

        $this->ensureCommands($values);
        foreach ($values as $logicalId => $value) {
            $this->publishCmd($logicalId, $value);
        }
        return true;
    }

    /* ======================================================== DÉCODAGE */

    /*
     * Ramène une réponse de mesure (v1 ou v2) aux noms de la v2, en valeurs
     * prêtes à publier. Les champs nuls ou absents ne produisent rien : une
     * commande garde alors sa dernière valeur plutôt que de tomber à zéro.
     */
    public static function normalize($_data) {
        $out = array();
        $hasT2 = isset($_data['total_power_import_t2_kwh']) || isset($_data['energy_import_t2_kwh'])
              || isset($_data['total_power_export_t2_kwh']) || isset($_data['energy_export_t2_kwh']);

        foreach ($_data as $field => $value) {
            if ($value === null || $field === 'external') {
                continue;
            }
            $key = self::v2Name($field);
            if ($key === null) {
                continue;
            }
            /* Une prise ou un compteur kWh répète le total en « tarif 1 » :
             * sans tarif 2, ce doublon n'apprend rien. */
            if (!$hasT2 && preg_match('/^energy_(import|export)_t1_kwh$/', $key)) {
                continue;
            }
            switch ($key) {
                case 'unique_id':
                case 'unique_gas_id':
                    $out[$key] = self::decodeHexId($value);
                    break;
                case 'monthly_power_peak_timestamp':
                case 'gas_timestamp':
                    $out[$key] = self::timestamp($value);
                    break;
                case 'meter_model':
                case 'wifi_ssid':
                    $out[$key] = (string) $value;
                    break;
                case 'power_factor':
                case 'power_factor_l1':
                case 'power_factor_l2':
                case 'power_factor_l3':
                    /* Présenté en pourcentage, comme dans Home Assistant. */
                    $out[$key] = is_numeric($value) ? round((float) $value * 100, 1) : null;
                    break;
                default:
                    if (is_bool($value)) {
                        $out[$key] = $value ? 1 : 0;
                    } elseif (is_numeric($value)) {
                        $out[$key] = 0 + $value;
                    }
            }
        }

        /* Les compteurs raccordés au P1 : gaz, eau, chaleur. Chacun a son
         * identifiant ; on les numérote par type dans l'ordre où ils viennent,
         * ce qui reste stable tant qu'on n'en débranche pas un. */
        if (isset($_data['external']) && is_array($_data['external'])) {
            $seen = array();
            foreach ($_data['external'] as $meter) {
                if (!is_array($meter) || !isset($meter['type']) || !isset($meter['value'])) {
                    continue;
                }
                $type = preg_replace('/[^a-z_]/', '', (string) $meter['type']);
                $seen[$type] = isset($seen[$type]) ? $seen[$type] + 1 : 1;
                $key = 'ext_' . $type . ($seen[$type] > 1 ? '_' . $seen[$type] : '');
                $out[$key] = is_numeric($meter['value']) ? 0 + $meter['value'] : null;
                if (isset($meter['timestamp'])) {
                    $out[$key . '_timestamp'] = self::timestamp($meter['timestamp']);
                }
                if (isset($meter['unit'])) {
                    $out['_unit_' . $key] = str_replace('m3', 'm³', (string) $meter['unit']);
                }
            }
        }
        return $out;
    }

    /* Nom v2 d'un champ v1 (inchangé s'il est déjà en v2), null si le champ ne
     * mérite pas de commande. */
    public static function v2Name($_field) {
        $renamed = array(
            'smr_version'                 => 'protocol_version',
            'active_tariff'               => 'tariff',
            'active_power_average_w'      => 'average_power_15m_w',
            'montly_power_peak_w'         => 'monthly_power_peak_w',
            'montly_power_peak_timestamp' => 'monthly_power_peak_timestamp',
            'gas_unique_id'               => 'unique_gas_id',
            'wifi_strength'               => 'wifi_strength',
        );
        $ignored = array('timestamp', 'total_liter_offset_m3', 'wifi_rssi_db');
        if (in_array($_field, $ignored, true)) {
            return null;
        }
        if (isset($renamed[$_field])) {
            return $renamed[$_field];
        }
        if (preg_match('/^total_power_(import|export)(_t\d)?_kwh$/', $_field, $m)) {
            return 'energy_' . $m[1] . (isset($m[2]) ? $m[2] : '') . '_kwh';
        }
        if ($_field !== 'active_liter_lpm' && strpos($_field, 'active_') === 0) {
            return substr($_field, 7);
        }
        return $_field;
    }

    public static function normalizeSystem($_data) {
        $out = array();
        if (isset($_data['cloud_enabled'])) {
            $out['cloud_enabled'] = $_data['cloud_enabled'] ? 1 : 0;
        }
        if (isset($_data['status_led_brightness_pct']) && is_numeric($_data['status_led_brightness_pct'])) {
            $out['status_led_brightness_pct'] = (int) $_data['status_led_brightness_pct'];
        }
        if (isset($_data['wifi_ssid'])) {
            $out['wifi_ssid'] = (string) $_data['wifi_ssid'];
        }
        if (isset($_data['wifi_rssi_db']) && is_numeric($_data['wifi_rssi_db'])) {
            $rssi = (int) $_data['wifi_rssi_db'];
            $out['wifi_rssi_db'] = $rssi;
            /* Même conversion que la bibliothèque officielle. */
            $out['wifi_strength'] = ($rssi <= -100 || $rssi === 0) ? 0 : ($rssi >= -50 ? 100 : 2 * ($rssi + 100));
        }
        if (isset($_data['uptime_s']) && is_numeric($_data['uptime_s'])) {
            $out['uptime_h'] = round((float) $_data['uptime_s'] / 3600, 1);
        }
        return $out;
    }

    /* La prise en v1 : son anneau lumineux va de 0 à 255, ramené en %. */
    public static function normalizeState($_data) {
        $out = array();
        if (isset($_data['power_on'])) {
            $out['power_on'] = $_data['power_on'] ? 1 : 0;
        }
        if (isset($_data['switch_lock'])) {
            $out['switch_lock'] = $_data['switch_lock'] ? 1 : 0;
        }
        if (isset($_data['brightness']) && is_numeric($_data['brightness'])) {
            $out['status_led_brightness_pct'] = (int) round((float) $_data['brightness'] / 2.55);
        }
        return $out;
    }

    public static function normalizeBatteries($_data) {
        $out = array();
        if (isset($_data['mode'])) {
            $mode = (string) $_data['mode'];
            /* « zéro » se décline selon les permissions accordées. */
            if ($mode === 'zero' && isset($_data['permissions']) && is_array($_data['permissions'])) {
                $charge = in_array('charge_allowed', $_data['permissions'], true);
                $discharge = in_array('discharge_allowed', $_data['permissions'], true);
                if ($charge && !$discharge) {
                    $mode = 'zero_charge_only';
                } elseif ($discharge && !$charge) {
                    $mode = 'zero_discharge_only';
                }
            }
            $out['battery_mode'] = $mode;
        }
        foreach (array('battery_count' => 'battery_count', 'power_w' => 'battery_group_power_w',
                       'target_power_w' => 'battery_group_target_power_w',
                       'max_consumption_w' => 'battery_max_consumption_w',
                       'max_production_w' => 'battery_max_production_w') as $field => $key) {
            if (isset($_data[$field]) && is_numeric($_data[$field])) {
                $out[$key] = 0 + $_data[$field];
            }
        }
        return $out;
    }

    /* L'identifiant du compteur arrive en hexadécimal : « 3153414731… » est
     * « 1SAG1… », le numéro imprimé sur le compteur. */
    public static function decodeHexId($_value) {
        $value = (string) $_value;
        if ($value !== '' && strlen($value) % 2 === 0 && ctype_xdigit($value)) {
            $decoded = hex2bin($value);
            if ($decoded !== false && preg_match('/^[\x20-\x7E]+$/', $decoded)) {
                return $decoded;
            }
        }
        return $value;
    }

    /* v1 : entier AAMMJJhhmmss. v2 : chaîne ISO sans fuseau. */
    public static function timestamp($_value) {
        if (is_numeric($_value)) {
            $s = str_pad((string) $_value, 12, '0', STR_PAD_LEFT);
            return sprintf('20%s-%s-%s %s:%s:%s', substr($s, 0, 2), substr($s, 2, 2), substr($s, 4, 2),
                substr($s, 6, 2), substr($s, 8, 2), substr($s, 10, 2));
        }
        return str_replace('T', ' ', (string) $_value);
    }

    /* ============================================== TARIF CAPACITAIRE */

    /*
     * Le télégramme DSMR belge (e-MUCS) porte, en 0-0:98.1.0, les pics de
     * puissance quart-horaire des treize derniers mois, et en 1-0:1.6.0 celui
     * du mois en cours. Chaque entrée de l'historique est un triplet :
     * (fin de période)(horodatage du pic)(valeur*kW).
     *
     * On range par mois du pic et on garde le maximum : un compteur remis à
     * l'heure en cours de mois produit deux entrées pour le même mois.
     */
    public static function parseTelegram($_text) {
        $months = array();
        foreach (preg_split('/\r?\n/', (string) $_text) as $line) {
            if (strpos($line, '0-0:98.1.0') === 0) {
                preg_match_all('/\(([^)]*)\)/', $line, $m);
                $groups = $m[1];
                /* count, deux OBIS, puis les triplets. */
                for ($i = 3; $i + 2 < count($groups) + 0; $i += 3) {
                    self::addPeak($months, $groups[$i + 1], $groups[$i + 2]);
                }
            } elseif (strpos($line, '1-0:1.6.0') === 0) {
                preg_match_all('/\(([^)]*)\)/', $line, $m);
                if (count($m[1]) >= 2) {
                    self::addPeak($months, $m[1][0], $m[1][1]);
                }
            }
        }
        krsort($months);
        return $months;
    }

    private static function addPeak(&$_months, $_stamp, $_value) {
        if (!preg_match('/^(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})[SW]?$/', $_stamp, $t)) {
            return;
        }
        if (!preg_match('/^([\d.]+)\*kW$/', $_value, $v)) {
            return;
        }
        $month = '20' . $t[1] . '-' . $t[2];
        $watts = (int) round((float) $v[1] * 1000);
        if (!isset($_months[$month]) || $watts > $_months[$month]['w']) {
            $_months[$month] = array('w' => $watts,
                'at' => sprintf('20%s-%s-%s %s:%s', $t[1], $t[2], $t[3], $t[4], $t[5]));
        }
    }

    /*
     * La moyenne sur douze mois glissants, mois en cours compris, chaque mois
     * comptant pour 2,5 kW au moins : c'est l'assiette du tarif capacitaire
     * appliqué par Fluvius en Flandre.
     */
    public function capacityValues($_months) {
        if (empty($_months)) {
            return array();
        }
        $current = date('Y-m');
        $previous = date('Y-m', strtotime('first day of last month'));
        $last12 = array();
        foreach ($_months as $month => $peak) {
            if ($month > $current) {
                continue;
            }
            $last12[$month] = $peak;
            if (count($last12) === 12) {
                break;
            }
        }
        /* Horloge de Jeedom en retard sur celle du compteur : rien d'exploitable. */
        if (empty($last12)) {
            return array();
        }
        $sum = 0;
        foreach ($last12 as $peak) {
            $sum += max(self::CAPACITY_FLOOR_W, $peak['w']);
        }
        $history = array();
        foreach ($last12 as $month => $peak) {
            $history[] = $month . ' : ' . number_format($peak['w'] / 1000, 3, ',', '') . ' kW (' . $peak['at'] . ')';
        }
        return array(
            'capacity_avg_12m_w'   => (int) round($sum / max(1, count($last12))),
            'peak_previous_month_w' => isset($_months[$previous]) ? $_months[$previous]['w'] : null,
            'peak_max_12m_w'       => max(array_map(function ($p) { return $p['w']; }, $last12)),
            'peak_history'         => implode(' | ', $history),
        );
    }

    /* ============================================== VALEURS CALCULÉES */

    /*
     * Ce que l'appareil ne donne pas mais que tout le monde recalcule :
     *   - la puissance signée coupée en deux grandeurs positives, soutirage et
     *     injection, que les widgets énergie de Jeedom attendent ;
     *   - l'énergie du jour et de la veille, tirée des index à minuit.
     */
    public function derivedValues($_values) {
        $out = array();
        if (isset($_values['power_w'])) {
            $p = (float) $_values['power_w'];
            $out['power_import_w'] = $p > 0 ? $p : 0;
            $out['power_export_w'] = $p < 0 ? -$p : 0;
        }

        $daily = array('energy_import_kwh' => 'import', 'energy_export_kwh' => 'export', 'total_liter_m3' => 'water');
        /* Un relevé qui ne porte aucun index (les seules données système, par
         * exemple) ne doit pas faire basculer la journée : il effacerait les
         * index de la veille sans pouvoir poser ceux du jour. */
        if (count(array_intersect_key($daily, $_values)) === 0) {
            return $out;
        }
        $day = $this->getCache('day', array());
        $today = date('Y-m-d');
        if (!isset($day['date']) || $day['date'] !== $today) {
            $previous = $day;
            $day = array('date' => $today);
            foreach ($daily as $field => $name) {
                if (!isset($_values[$field])) {
                    continue;
                }
                /* La veille ne se calcule que si le relevé de minuit a vu
                 * l'index d'hier : après une coupure de plusieurs jours, la
                 * différence couvrirait toute la période. */
                if (isset($previous['date'], $previous['start_' . $name], $previous['last_' . $name])
                    && $previous['date'] === date('Y-m-d', strtotime('-1 day'))) {
                    $out[$name . '_yesterday'] = self::dailyValue($name, $previous['last_' . $name] - $previous['start_' . $name]);
                }
                $day['start_' . $name] = (float) $_values[$field];
            }
        }
        foreach ($daily as $field => $name) {
            if (!isset($_values[$field])) {
                continue;
            }
            if (!isset($day['start_' . $name])) {
                $day['start_' . $name] = (float) $_values[$field];
            }
            $day['last_' . $name] = (float) $_values[$field];
            $out[$name . '_today'] = self::dailyValue($name, $day['last_' . $name] - $day['start_' . $name]);
        }
        $this->setCache('day', $day);
        return $out;
    }

    /* L'eau se compte en litres à l'échelle d'une journée, pas en m³. */
    private static function dailyValue($_name, $_delta) {
        $delta = max(0, $_delta);
        return $_name === 'water' ? (int) round($delta * 1000) : round($delta, 3);
    }

    /* ======================================================== COMMANDES */

    /*
     * Définition d'une commande info d'après son identifiant logique :
     * nom, sous-type, unité, type générique, visible par défaut, historisée.
     * null pour un champ inconnu, qui ne produit alors aucune commande.
     */
    public static function definition($_key) {
        static $fixed = null;
        if ($fixed === null) {
            $fixed = array(
                'power_w'                  => array('Puissance', 'numeric', 'W', 'POWER', 1, 1),
                'power_import_w'           => array('Puissance soutirée', 'numeric', 'W', 'POWER', 0, 1),
                'power_export_w'           => array('Puissance injectée', 'numeric', 'W', 'POWER', 0, 1),
                'tariff'                   => array('Tarif', 'numeric', '', '', 1, 1),
                'energy_import_kwh'        => array('Importation d’énergie', 'numeric', 'kWh', 'CONSUMPTION', 1, 1),
                'energy_export_kwh'        => array('Exportation d’énergie', 'numeric', 'kWh', 'PRODUCTION', 1, 1),
                'import_today'             => array('Import du jour', 'numeric', 'kWh', 'DAILY_CONSUMPTION', 1, 1),
                'export_today'             => array('Export du jour', 'numeric', 'kWh', 'DAILY_PRODUCTION', 1, 1),
                'import_yesterday'         => array('Import de la veille', 'numeric', 'kWh', '', 0, 1),
                'export_yesterday'         => array('Export de la veille', 'numeric', 'kWh', '', 0, 1),
                'average_power_15m_w'      => array('Demande moyenne', 'numeric', 'W', '', 1, 1),
                'monthly_power_peak_w'     => array('Demande de pointe du mois en cours', 'numeric', 'W', '', 1, 1),
                'monthly_power_peak_timestamp' => array('Heure de la pointe du mois', 'string', '', '', 0, 0),
                'peak_previous_month_w'    => array('Pointe du mois précédent', 'numeric', 'W', '', 0, 1),
                'peak_max_12m_w'           => array('Pointe maximale sur 12 mois', 'numeric', 'W', '', 0, 1),
                'capacity_avg_12m_w'       => array('Moyenne des pointes 12 mois (tarif capacitaire)', 'numeric', 'W', '', 1, 1),
                'peak_history'             => array('Historique des pointes mensuelles', 'string', '', '', 0, 0),
                'voltage_v'                => array('Tension', 'numeric', 'V', 'VOLTAGE', 0, 1),
                'current_a'                => array('Courant', 'numeric', 'A', '', 0, 1),
                'frequency_hz'             => array('Fréquence', 'numeric', 'Hz', '', 0, 1),
                'apparent_power_va'        => array('Puissance apparente', 'numeric', 'VA', '', 0, 1),
                'reactive_power_var'       => array('Puissance réactive', 'numeric', 'var', '', 0, 1),
                'power_factor'             => array('Facteur de puissance', 'numeric', '%', '', 0, 1),
                'apparent_current_a'       => array('Courant apparent', 'numeric', 'A', '', 0, 1),
                'reactive_current_a'       => array('Courant réactif', 'numeric', 'A', '', 0, 1),
                'any_power_fail_count'     => array('Coupures de courant détectées', 'numeric', '', '', 0, 0),
                'long_power_fail_count'    => array('Coupures de courant prolongées détectées', 'numeric', '', '', 0, 0),
                'active_liter_lpm'         => array('Consommation d’eau', 'numeric', 'L/min', '', 1, 1),
                'total_liter_m3'           => array('Consommation d’eau totale', 'numeric', 'm³', '', 1, 1),
                'water_today'              => array('Eau du jour', 'numeric', 'L', '', 1, 1),
                'water_yesterday'          => array('Eau de la veille', 'numeric', 'L', '', 0, 1),
                'state_of_charge_pct'      => array('État de charge', 'numeric', '%', 'BATTERY', 1, 1),
                'cycles'                   => array('Cycles de la batterie', 'numeric', '', '', 0, 1),
                'battery_mode'             => array('Stratégie de charge des batteries', 'string', '', '', 0, 0),
                'battery_count'            => array('Nombre de batteries', 'numeric', '', '', 0, 0),
                'battery_group_power_w'    => array('Puissance du groupe de batteries', 'numeric', 'W', 'POWER', 0, 1),
                'battery_group_target_power_w' => array('Puissance cible du groupe de batteries', 'numeric', 'W', '', 0, 1),
                'battery_max_consumption_w' => array('Puissance de charge maximale', 'numeric', 'W', '', 0, 0),
                'battery_max_production_w' => array('Puissance de décharge maximale', 'numeric', 'W', '', 0, 0),
                'power_on'                 => array('État de la prise', 'binary', '', 'ENERGY_STATE', 1, 1),
                'switch_lock'              => array('Verrouillage de l’interrupteur', 'binary', '', '', 0, 0),
                'cloud_enabled'            => array('Connexion au cloud', 'binary', '', '', 0, 0),
                'status_led_brightness_pct' => array('Luminosité du voyant d’état', 'numeric', '%', '', 0, 0),
                'protocol_version'         => array('Version DSMR', 'numeric', '', '', 0, 0),
                'meter_model'              => array('Modèle de compteur intelligent', 'string', '', '', 0, 0),
                'unique_id'                => array('Identifiant du compteur intelligent', 'string', '', '', 0, 0),
                'total_gas_m3'             => array('Gaz (ancien champ)', 'numeric', 'm³', '', 0, 1),
                'gas_timestamp'            => array('Horodatage du gaz (ancien champ)', 'string', '', '', 0, 0),
                'unique_gas_id'            => array('Identifiant du compteur de gaz', 'string', '', '', 0, 0),
                'wifi_ssid'                => array('Réseau Wi-Fi', 'string', '', '', 0, 0),
                'wifi_strength'            => array('Puissance du Wi-Fi', 'numeric', '%', '', 0, 1),
                'wifi_rssi_db'             => array('RSSI Wi-Fi', 'numeric', 'dBm', '', 0, 0),
                'uptime_h'                 => array('Temps de fonctionnement', 'numeric', 'h', '', 0, 0),
                'online'                   => array('En ligne', 'binary', '', '', 0, 0),
            );
        }
        if (isset($fixed[$_key])) {
            return $fixed[$_key];
        }

        /* Grandeurs par phase et par tarif, dérivées de leur forme générale. */
        if (preg_match('/^(power|voltage|current|apparent_power|reactive_power|apparent_current|reactive_current)_l([1-3])_(w|v|a|va|var)$/', $_key, $m)) {
            if (!isset($fixed[$m[1] . '_' . $m[3]])) {
                return null;
            }
            $base = $fixed[$m[1] . '_' . $m[3]];
            $base[0] .= ' phase ' . $m[2];
            $base[3] = $m[1] === 'power' ? 'POWER' : ($m[1] === 'voltage' ? 'VOLTAGE' : '');
            /* Masquée par défaut : sur un monophasé, elle répète la puissance
             * totale. Un triphasé la réaffiche d'un clic. */
            $base[4] = 0;
            return $base;
        }
        if (preg_match('/^power_factor_l([1-3])$/', $_key, $m)) {
            return array('Facteur de puissance phase ' . $m[1], 'numeric', '%', '', 0, 1);
        }
        if (preg_match('/^energy_(import|export)_t([1-4])_kwh$/', $_key, $m)) {
            return array(($m[1] === 'import' ? 'Énergie importée' : 'Énergie exportée') . ' tarif ' . $m[2],
                'numeric', 'kWh', '', 1, 1);
        }
        if (preg_match('/^voltage_(sag|swell)_l([1-3])_count$/', $_key, $m)) {
            return array(($m[1] === 'sag' ? 'Creux de tension détectés' : 'Surtensions détectées') . ' phase ' . $m[2],
                'numeric', '', '', 0, 0);
        }
        if (preg_match('/^ext_([a-z_]+?)(?:_(\d+))?(_timestamp)?$/', $_key, $m) && isset(self::EXTERNAL_TYPES[$m[1]])) {
            $name = __(self::EXTERNAL_TYPES[$m[1]], __FILE__) . (!empty($m[2]) ? ' ' . $m[2] : '');
            if (!empty($m[3])) {
                return array($name . ' ' . __('(relevé)', __FILE__), 'string', '', '', 0, 0);
            }
            return array($name, 'numeric', '', '', 1, 1);
        }
        return null;
    }

    /* Rang d'affichage : les grandeurs qu'on vient lire d'abord, le
     * diagnostic à la fin. */
    public static function orderOf($_key) {
        $prefixes = array('power_w', 'power_import', 'power_export', 'power_l', 'state_of_charge', 'power_on',
            'active_liter', 'water_', 'total_liter', 'tariff', 'import_', 'export_', 'energy_import', 'energy_export',
            'average_power', 'monthly_power', 'capacity', 'peak', 'ext_', 'voltage_v', 'voltage_l', 'current',
            'frequency', 'apparent', 'reactive', 'power_factor', 'battery', 'cycles', 'switch_lock',
            'status_led', 'cloud', 'voltage_s', 'any_power', 'long_power', 'protocol', 'meter_model',
            'unique', 'total_gas', 'gas_', 'wifi', 'uptime', 'online');
        foreach ($prefixes as $rank => $prefix) {
            if (strpos($_key, $prefix) === 0) {
                return $rank * 10;
            }
        }
        return 900;
    }

    /*
     * Crée les commandes info que le relevé fait apparaître pour la première
     * fois, puis les actions qui vont avec. La liste des identifiants connus
     * est gardée en cache pour ne pas interroger la base à chaque seconde.
     */
    private function ensureCommands($_values) {
        $known = $this->getCache('known', array());
        $missing = array();
        foreach ($_values as $key => $value) {
            if ($key[0] !== '_' && !isset($known[$key])) {
                $missing[$key] = $value;
            }
        }
        if (empty($missing)) {
            return;
        }
        $this->createCommands($_values);
        /* Les actions sont revues avec, pour se rattacher aux infos neuves. */
        foreach ($missing as $key => $value) {
            $known[$key] = 1;
        }
        $this->setCache('known', $known);
    }

    /*
     * Création idempotente. Une commande existante n'est jamais récrite : son
     * nom, sa visibilité et son historisation appartiennent à l'utilisateur.
     */
    public function createCommands($_values) {
        if ($this->getId() == '') {
            return;
        }
        foreach ($_values as $key => $value) {
            if ($key[0] === '_') {
                continue;
            }
            $def = self::definition($key);
            if ($def === null) {
                continue;
            }
            $unit = isset($_values['_unit_' . $key]) ? $_values['_unit_' . $key] : $def[2];
            $this->addCmdIfMissing($key, $def[0], 'info', $def[1], array(
                'order' => self::orderOf($key), 'unite' => $unit, 'generic' => $def[3],
                'isVisible' => $def[4], 'isHistorized' => $def[5],
            ));
        }

        $this->addCmdIfMissing('refresh', 'Rafraîchir', 'action', 'other', array('order' => 1000, 'isVisible' => 1));
        $this->createActions();
    }

    /* Les actions dépendent de ce que l'appareil sait faire, lu dans son
     * modèle et dans l'API utilisée, pas dans la réponse. */
    private function createActions() {
        $family = $this->family();
        $v2 = $this->usesV2();
        if ($family === '') {
            return;
        }

        if ($family === 'socket') {
            $state = $this->getCmd('info', 'power_on');
            $this->addCmdIfMissing('power_on_set', 'Allumer', 'action', 'other', array(
                'order' => 1001, 'isVisible' => 1, 'generic' => 'ENERGY_ON', 'value' => $state));
            $this->addCmdIfMissing('power_off_set', 'Éteindre', 'action', 'other', array(
                'order' => 1002, 'isVisible' => 1, 'generic' => 'ENERGY_OFF', 'value' => $state));
            $lock = $this->getCmd('info', 'switch_lock');
            $this->addCmdIfMissing('lock_on', 'Verrouiller', 'action', 'other', array('order' => 1003, 'value' => $lock));
            $this->addCmdIfMissing('lock_off', 'Déverrouiller', 'action', 'other', array('order' => 1004, 'value' => $lock));
        }

        if (!in_array($family, array('kwh'), true)) {
            $this->addCmdIfMissing('identify', 'Identifier', 'action', 'other', array('order' => 1010));
        }
        if ($family !== 'battery') {
            $cloud = $this->getCmd('info', 'cloud_enabled');
            $this->addCmdIfMissing('cloud_on', 'Activer le cloud', 'action', 'other', array('order' => 1011, 'value' => $cloud));
            $this->addCmdIfMissing('cloud_off', 'Désactiver le cloud', 'action', 'other', array('order' => 1012, 'value' => $cloud));
        }
        /* La LED se règle sur la prise en v1, sur tout le reste en v2. */
        if ($family === 'socket' || $v2) {
            $led = $this->getCmd('info', 'status_led_brightness_pct');
            $this->addCmdIfMissing('led_set', 'Régler la luminosité du voyant', 'action', 'slider', array(
                'order' => 1013, 'value' => $led, 'min' => 0, 'max' => 100));
        }
        if ($v2 && $family !== 'battery') {
            $this->addCmdIfMissing('reboot', 'Redémarrer', 'action', 'other', array('order' => 1014));
        }
        if ($v2 && in_array($family, array('p1', 'kwh'), true)) {
            $list = array();
            foreach (self::BATTERY_MODES as $mode => $label) {
                $list[] = $mode . '|' . __($label, __FILE__);
            }
            $this->addCmdIfMissing('battery_mode_set', 'Choisir la stratégie de charge', 'action', 'select', array(
                'order' => 1015, 'value' => $this->getCmd('info', 'battery_mode'), 'listValue' => implode(';', $list)));
        }
    }

    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd($_type, $_logicalId);
        if (is_object($cmd)) {
            /* Une action créée avant sa commande info (l'état n'arrive qu'au
             * premier relevé) est rattachée dès que l'info existe. */
            if (isset($_options['value']) && is_object($_options['value']) && $cmd->getValue() == '') {
                $cmd->setValue($_options['value']->getId());
                $cmd->save();
            }
            return $cmd;
        }
        $cmd = new homewizardbeCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        /* Unicité (eqLogic_id, name) en base : un nom déjà pris ferait échouer
         * tout l'enregistrement de l'équipement. */
        /* Nettoyé comme le fera setName() : sans cela, un nom contenant un
         * caractère que le coeur retire ne serait jamais trouvé ici. */
        $name = cleanComponanteName(__($_name, __FILE__));
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_logicalId . ')';
        }
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 0);
        $cmd->setIsHistorized(isset($_options['isHistorized']) ? $_options['isHistorized'] : 0);
        if (isset($_options['order']))      { $cmd->setOrder($_options['order']); }
        if (!empty($_options['unite']))     { $cmd->setUnite($_options['unite']); }
        if (!empty($_options['generic']))   { $cmd->setGeneric_type($_options['generic']); }
        if (isset($_options['min']))        { $cmd->setConfiguration('minValue', $_options['min']); }
        if (isset($_options['max']))        { $cmd->setConfiguration('maxValue', $_options['max']); }
        if (!empty($_options['listValue'])) { $cmd->setConfiguration('listValue', $_options['listValue']); }
        if (isset($_options['value']) && is_object($_options['value'])) {
            $cmd->setValue($_options['value']->getId());
        }
        $cmd->save();
        return $cmd;
    }

    public static function rebuildCommands() {
        foreach (self::byType(__CLASS__) as $eqLogic) {
            try {
                $eqLogic->setCache('known', array());
                $eqLogic->createCommands(array());
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* Écriture d'une valeur. Ce nom, et surtout pas setCmd() : utils::a2o()
     * appelle « set » + chaque clé du formulaire, et la page envoie « cmd ». */
    private function publishCmd($_logicalId, $_value) {
        if ($_value === null || $_logicalId[0] === '_') {
            return;
        }
        $cmd = $this->getCmd('info', $_logicalId);
        if (!is_object($cmd)) {
            return;
        }
        $this->checkAndUpdateCmd($cmd, $_value);
    }

    /* ========================================================= ACTIONS */

    public function runAction($_logicalId, $_options) {
        $v2 = $this->usesV2();
        switch ($_logicalId) {
            case 'refresh':
                return $this->pollNow(true);
            case 'power_on_set':
            case 'power_off_set':
                $on = $_logicalId === 'power_on_set';
                $this->call('PUT', '/api/v1/state', array('power_on' => $on));
                $this->publishCmd('power_on', $on ? 1 : 0);
                break;
            case 'lock_on':
            case 'lock_off':
                $lock = $_logicalId === 'lock_on';
                $this->call('PUT', '/api/v1/state', array('switch_lock' => $lock));
                $this->publishCmd('switch_lock', $lock ? 1 : 0);
                break;
            case 'identify':
                $this->call('PUT', $v2 ? '/api/system/identify' : '/api/v1/identify');
                break;
            case 'cloud_on':
            case 'cloud_off':
                $on = $_logicalId === 'cloud_on';
                $this->call('PUT', $v2 ? '/api/system' : '/api/v1/system', array('cloud_enabled' => $on));
                $this->publishCmd('cloud_enabled', $on ? 1 : 0);
                break;
            case 'led_set':
                $pct = max(0, min(100, (int) (isset($_options['slider']) ? $_options['slider'] : 100)));
                if ($v2) {
                    $this->call('PUT', '/api/system', array('status_led_brightness_pct' => $pct));
                } else {
                    $this->call('PUT', '/api/v1/state', array('brightness' => (int) round($pct * 2.55)));
                }
                $this->publishCmd('status_led_brightness_pct', $pct);
                break;
            case 'reboot':
                $this->call('PUT', '/api/system/reboot');
                break;
            case 'battery_mode_set':
                $mode = isset($_options['select']) ? (string) $_options['select'] : '';
                $this->call('PUT', '/api/batteries', self::batteryModeBody($mode));
                $this->publishCmd('battery_mode', $mode);
                break;
        }
        return true;
    }

    public static function batteryModeBody($_mode) {
        switch ($_mode) {
            case 'zero':
                return array('mode' => 'zero', 'permissions' => array('charge_allowed', 'discharge_allowed'));
            case 'zero_charge_only':
                return array('mode' => 'zero', 'permissions' => array('charge_allowed'));
            case 'zero_discharge_only':
                return array('mode' => 'zero', 'permissions' => array('discharge_allowed'));
            case 'standby':
                return array('mode' => 'standby', 'permissions' => array());
            case 'to_full':
            case 'predictive':
                return array('mode' => $_mode);
        }
        throw new Exception(__('Stratégie de charge inconnue :', __FILE__) . ' ' . $_mode);
    }

    /* ================================================= ÉCHECS ET ÉTAT */

    private function noteFailure($_message) {
        $failures = (int) $this->getCache('failures', 0) + 1;
        $this->setCache('failures', $failures);
        $this->setCache('problem', $_message);
        $this->publishCmd('online', 0);
        /* Un seul message au niveau « info » par panne : un journal qui répète
         * la même ligne toutes les dix secondes ne se lit plus. */
        if ($failures === 3) {
            log::add(__CLASS__, 'warning', $this->getHumanName() . ' : ' . $_message);
        } else {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $_message);
        }
    }

    private function clearFailure() {
        if ((int) $this->getCache('failures', 0) > 0) {
            if ((int) $this->getCache('failures', 0) >= 3) {
                log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('de nouveau joignable.', __FILE__));
            }
            $this->setCache('failures', 0);
            $this->setCache('problem', '');
        }
    }

    public function toAjax() {
        return array(
            'id'        => $this->getId(),
            'online'    => (int) $this->getCache('failures', 0) === 0 && $this->getCache('raw_at', '') !== '',
            'failures'  => (int) $this->getCache('failures', 0),
            'problem'   => (string) $this->getCache('problem', ''),
            'fetchedAt' => (string) $this->getCache('raw_at', ''),
            'api'       => $this->usesV2() ? 'v2' : 'v1',
            'daemon'    => self::deamon_info()['state'],
            'raw'       => $this->getCache('raw', array()),
        );
    }
}

/* Obligatoire même réduite : sans elle, le coeur refuse de créer ou d'ouvrir
 * un équipement du plugin. */
class homewizardbeCmd extends cmd {

    public function execute($_options = array()) {
        if ($this->getType() !== 'action') {
            return;
        }
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            return;
        }
        $eqLogic->runAction($this->getLogicalId(), $_options);
    }
}
