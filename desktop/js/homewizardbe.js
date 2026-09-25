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

/* ================================================================== OUTILS */

function homewizardbeEl(_id) {
  return document.getElementById(_id)
}

/* Ce qui vient d'un appareil est du texte, jamais du balisage. */
function homewizardbeEscape(_text) {
  var div = document.createElement('div')
  div.textContent = (_text === null || _text === undefined) ? '' : String(_text)
  return div.innerHTML
}

function homewizardbeAjax(_action, _data, _success, _error) {
  var payload = { action: _action }
  for (var key in _data) {
    if (Object.prototype.hasOwnProperty.call(_data, key)) { payload[key] = _data[key] }
  }
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/homewizardbe/core/ajax/homewizardbe.ajax.php',
    data: payload,
    dataType: 'json',
    global: false,
    error: function (error) {
      if (typeof _error === 'function') { _error(error); return }
      domUtils.handleAjaxError(error)
    },
    success: function (result) {
      if (result.state !== 'ok') {
        if (typeof _error === 'function') { _error(result); return }
        jeedomUtils.showAlert({ message: result.result, level: 'danger' })
        return
      }
      _success(result)
    }
  })
}

function homewizardbeStatus(_text, _level) {
  var span = homewizardbeEl('span_homewizardbeStatus')
  if (span === null) { return }
  span.textContent = _text
  span.className = _level ? 'label label-' + _level : ''
}

function homewizardbeCurrentId() {
  var id = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (id === null) ? '' : id.value
}

function homewizardbeReload(_id) {
  jeedomUtils.loadPage('index.php?v=d&m=homewizardbe&p=homewizardbe' + (_id ? '&id=' + _id : ''))
}

/* ============================================================== DÉCOUVERTE */

function homewizardbeScan() {
  var last = ''
  try { last = window.localStorage.getItem('homewizardbe:subnet') || '' } catch (e) { last = '' }

  bootbox.prompt({
    title: '{{Sous-réseau à parcourir (laisser vide pour celui de Jeedom)}}',
    value: last,
    placeholder: '192.168.0.0/24',
    callback: function (_subnet) {
      if (_subnet === null) { return }
      var subnet = String(_subnet).trim()
      try { window.localStorage.setItem('homewizardbe:subnet', subnet) } catch (e) { /* mode privé */ }
      jeedomUtils.showAlert({ message: '{{Recherche des appareils HomeWizard… quelques secondes.}}', level: 'info' })
      homewizardbeAjax('scan', { subnet: subnet }, function (result) {
        jeedomUtils.hideAlert()
        homewizardbeShowFound(result.result)
      })
    }
  })
}

function homewizardbeAddIp() {
  bootbox.prompt({
    title: '{{Adresse IP de l\'appareil HomeWizard}}',
    placeholder: '192.168.0.150',
    callback: function (_ip) {
      if (_ip === null) { return }
      var ip = String(_ip).trim()
      if (ip === '') { return }
      homewizardbeAjax('probe', { ip: ip }, function (result) {
        homewizardbeShowFound([result.result])
      })
    }
  })
}

function homewizardbeShowFound(_devices) {
  if (!isset(_devices) || _devices.length === 0) {
    jeedomUtils.showAlert({ message: '{{Aucun appareil HomeWizard n\'a répondu. Vérifiez que l\'API locale est activée dans l\'application HomeWizard Energy.}}', level: 'warning' })
    return
  }
  var html = '<p>{{Cochez les appareils à créer. Ceux qui existent déjà verront seulement leur adresse mise à jour.}}</p>'
  for (var i = 0; i < _devices.length; i++) {
    var d = _devices[i]
    html += '<div class="checkbox"><label>'
    html += '<input type="checkbox" class="homewizardbeFound" data-index="' + i + '"' + (d.known ? '' : ' checked') + '> '
    html += '<b>' + homewizardbeEscape(d.product_name || d.product_type) + '</b>'
    html += ' — ' + homewizardbeEscape(d.ip)
    if (d.serial) { html += ' <small>(' + homewizardbeEscape(d.serial) + ')</small>' }
    if (d.firmware_version) { html += ' <small>{{micrologiciel}} ' + homewizardbeEscape(d.firmware_version) + '</small>' }
    if (d.known) { html += ' <span class="label label-default">{{déjà créé :}} ' + homewizardbeEscape(d.known) + '</span>' }
    html += '</label></div>'
  }
  bootbox.confirm({
    title: '{{Appareils HomeWizard trouvés}}',
    message: html,
    callback: function (_ok) {
      if (!_ok) { return }
      var chosen = []
      document.querySelectorAll('.homewizardbeFound').forEach(function (_box) {
        if (_box.checked) { chosen.push({ ip: _devices[parseInt(_box.getAttribute('data-index'), 10)].ip }) }
      })
      if (chosen.length === 0) { return }
      homewizardbeAjax('create', { devices: JSON.stringify(chosen) }, function () {
        jeedomUtils.showAlert({ message: '{{Appareil(s) enregistré(s). Les commandes apparaissent au premier relevé.}}', level: 'success' })
        homewizardbeReload()
      })
    }
  })
}

/* ========================================================= ASSOCIATION v2 */

/*
 * L'appareil refuse de créer un jeton tant qu'on n'a pas appuyé sur son
 * bouton. On retente toutes les deux secondes pendant une minute et demie :
 * le temps d'aller jusqu'au compteur.
 */
var homewizardbePairTimer = null

function homewizardbePair() {
  var id = homewizardbeCurrentId()
  if (id === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez l\'appareil avant de l\'associer.}}', level: 'warning' })
    return
  }
  var deadline = Date.now() + 90000
  bootbox.dialog({
    title: '{{Association en API v2}}',
    message: '<p><i class="fas fa-hand-pointer"></i> {{Appuyez maintenant sur le bouton de l\'appareil (P1 : le bouton sur le dessus ; compteur kWh : le bouton Wi-Fi, 1 à 3 secondes).}}</p>'
      + '<p id="p_homewizardbePair" class="text-muted">{{En attente de l\'appui…}}</p>',
    buttons: { cancel: { label: '{{Annuler}}', className: 'btn-default' } },
    onEscape: true
  })
  var stop = function () {
    if (homewizardbePairTimer !== null) { clearTimeout(homewizardbePairTimer) }
    homewizardbePairTimer = null
  }

  var attempt = function () {
    /* Fenêtre fermée : on cesse de solliciter l'appareil. */
    if (homewizardbeEl('p_homewizardbePair') === null) {
      stop()
      return
    }
    if (Date.now() > deadline) {
      stop()
      var p = homewizardbeEl('p_homewizardbePair')
      if (p !== null) { p.textContent = '{{Délai dépassé : aucun appui détecté. Recommencez.}}' }
      return
    }
    homewizardbeAjax('pair', { id: id }, function (result) {
      if (result.result.state === 'paired') {
        stop()
        bootbox.hideAll()
        jeedomUtils.showAlert({ message: '{{Appareil associé en API v2.}}', level: 'success' })
        homewizardbeReload(id)
        return
      }
      var p = homewizardbeEl('p_homewizardbePair')
      if (p !== null) { p.textContent = '{{En attente de l\'appui…}} ' + Math.round((deadline - Date.now()) / 1000) + ' s' }
      homewizardbePairTimer = setTimeout(attempt, 2000)
    }, function (error) {
      stop()
      var p = homewizardbeEl('p_homewizardbePair')
      var message = (error && error.result) ? error.result : '{{Erreur de communication}}'
      if (p !== null) { p.textContent = message }
    })
  }
  attempt()
}

function homewizardbeUnpair() {
  var id = homewizardbeCurrentId()
  bootbox.confirm('{{Revenir en API v1 ? Le jeton sera supprimé de l\'appareil. Les commandes propres à la v2 resteront, mais sans effet.}}', function (_ok) {
    if (!_ok) { return }
    homewizardbeAjax('unpair', { id: id }, function () {
      homewizardbeReload(id)
    })
  })
}

/* ============================================================== DIAGNOSTIC */

function homewizardbeRender(_data) {
  var state = homewizardbeEl('div_homewizardbeState')
  if (state !== null && isset(_data)) {
    var daemon = (_data.daemon === 'ok') ? '{{démon actif}}' : '{{démon arrêté — relevé par le cron, une fois par minute}}'
    if (_data.fetchedAt === '') {
      state.className = 'alert alert-warning'
      state.textContent = '{{Aucun relevé pour le moment.}} · ' + daemon
    } else if (_data.online) {
      state.className = 'alert alert-success'
      state.textContent = '{{Dernier relevé :}} ' + _data.fetchedAt + ' · API ' + _data.api + ' · ' + daemon
    } else {
      state.className = 'alert alert-warning'
      state.textContent = '{{Injoignable :}} ' + _data.failures + ' {{échec(s) consécutif(s)}}'
        + (_data.problem ? ' — ' + _data.problem : '') + ' · {{dernier relevé réussi :}} ' + (_data.fetchedAt || '{{jamais}}')
    }
  }
  var raw = homewizardbeEl('pre_homewizardbeRaw')
  if (raw !== null) {
    raw.textContent = (isset(_data) && _data.raw) ? JSON.stringify(_data.raw, null, 2).replace(/\\n/g, '\n') : ''
  }
}

function homewizardbeRefresh() {
  var id = homewizardbeCurrentId()
  if (id === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez l\'appareil avant de le relever.}}', level: 'warning' })
    return
  }
  homewizardbeStatus('{{Lecture en cours…}}', 'default')
  homewizardbeAjax('refresh', { id: id }, function (result) {
    homewizardbeStatus('{{Relevé effectué.}}', 'success')
    homewizardbeRender(result.result)
  }, function (error) {
    homewizardbeStatus((error && error.result) ? error.result : '{{Échec du relevé}}', 'danger')
  })
}

/* ==================================================== APPELÉES PAR LE COEUR */

function printEqLogic(_eqLogic) {
  homewizardbeStatus('', '')
  homewizardbeRender({ fetchedAt: '', raw: null, daemon: '' })

  var v2 = isset(_eqLogic.configuration) && _eqLogic.configuration.token
  var api = homewizardbeEl('span_homewizardbeApi')
  if (api !== null) {
    api.textContent = v2 ? '{{v2 (associé)}}' : 'v1'
    api.className = v2 ? 'label label-success' : 'label label-default'
  }
  var type = isset(_eqLogic.configuration) ? (_eqLogic.configuration.product_type || '') : ''
  /* La prise et le compteur d'eau n'ont pas d'API v2. */
  var v2Possible = (type !== 'HWE-SKT' && type !== 'HWE-WTR')
  homewizardbeEl('bt_homewizardbePair').style.display = (v2 || !v2Possible) ? 'none' : ''
  homewizardbeEl('bt_homewizardbeUnpair').style.display = v2 ? '' : 'none'

  document.querySelectorAll('.homewizardbe-p1').forEach(function (_el) {
    _el.style.display = (type === 'HWE-P1') ? '' : 'none'
  })

  if (isset(_eqLogic.id) && _eqLogic.id !== '') {
    homewizardbeAjax('data', { id: _eqLogic.id }, function (result) {
      homewizardbeRender(result.result)
    })
  }
}

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  /* Sans ce champ, chaque enregistrement détruit puis recrée les commandes. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  if (init(_cmd.type) === 'info') {
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  }
  tr += '<span class="cmdAttr" data-l1key="unite" style="margin-left:8px;opacity:.7;"></span>'
  tr += '</td>'
  tr += '<td><span class="cmdAttr" data-l1key="htmlstate"></span></td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'

  /* Ligne créée en DOM : insertAdjacentHTML sur une table crée un <tbody> par
     insertion. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant logique}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* =============================================================== ÉCOUTEURS */

/* Pages chargées en ajax : DOMContentLoaded a déjà eu lieu, et ce script est
   réexécuté à chaque visite de la page. Un seul écouteur, posé une fois sur le
   document, qui résout les fonctions au moment du clic : elles sont
   redéfinies à chaque chargement. */
if (!window.homewizardbeListening) {
  window.homewizardbeListening = true
  document.addEventListener('click', function (_event) {
    var target = _event.target
    if (target === null || typeof target.closest !== 'function') { return }
    var actions = {
      bt_homewizardbeScan: homewizardbeScan,
      bt_homewizardbeAddIp: homewizardbeAddIp,
      bt_homewizardbePair: homewizardbePair,
      bt_homewizardbeUnpair: homewizardbeUnpair,
      bt_homewizardbeRefresh: homewizardbeRefresh
    }
    for (var id in actions) {
      if (target.closest('#' + id) !== null) {
        _event.preventDefault()
        actions[id]()
        return
      }
    }
  })
}
