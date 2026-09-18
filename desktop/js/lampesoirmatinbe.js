/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* ================================================================== OUTILS */

/* Les lampes du groupe ouvert. C'est la source de vérité de l'onglet Lampes :
   l'affichage en découle, et saveEqLogic la recopie dans la configuration. */
var lampesoirmatinbeSelection = []

/* Ce que le sélecteur a trouvé dans l'installation, gardé le temps de la
   fenêtre pour que filtrer et chercher ne relancent pas la découverte. */
var lampesoirmatinbePicker = {
  groups: [],
  checked: {},
  /* Les commandes choisies à la main, par équipement : { eq: {on: id, off: id} }.
     Elles vivent ici et non dans le DOM, qui est reconstruit à chaque frappe
     dans le champ de recherche — un choix posé dans une liste déroulante
     disparaîtrait à la lettre suivante. */
  choice: {},
  search: '',
  filters: { light: true, plug: true, guess: true, unknown: false },
  /* Le sélecteur complet coûte un parcours de toute l'installation : il n'est
     demandé au serveur que si l'utilisateur l'ouvre, et une seule fois. */
  loadedAll: false
}

/* Vrai pendant que printEqLogic repose les valeurs à l'écran. Reposer une
   valeur dans un champ émet « change » exactement comme une saisie : sans ce
   drapeau, ouvrir un groupe suffirait à le déclarer modifié, et l'avertissement
   « quitter sans enregistrer ? » tomberait sans que rien n'ait été touché. */
var lampesoirmatinbeRendering = false

/* Requête AJAX vers le contrôleur du plugin.
   _options : { button: <élément à désactiver pendant l'appel>,
                failure: <fonction recevant le message d'erreur>,
                silent: true pour ne rien afficher } */
function lampesoirmatinbeAjax(_action, _data, _success, _options) {
  var options = _options || {}
  var button = isset(options.button) ? options.button : null
  var released = false
  var release = function () {
    if (button === null || released) { return }
    released = true
    button.removeAttribute('disabled')
    button.classList.remove('disabled')
  }
  if (button !== null) {
    button.setAttribute('disabled', 'disabled')
    button.classList.add('disabled')
    /* Filet de sécurité : jamais de bouton bloqué si la réponse n'arrive pas. */
    setTimeout(release, 60000)
  }

  var payload = Object.assign({ action: _action }, _data || {})
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/lampesoirmatinbe/core/ajax/lampesoirmatinbe.ajax.php',
    data: payload,
    dataType: 'json',
    noDisplayError: true,
    error: function (request, status, error) {
      release()
      if (isset(options.failure)) {
        options.failure('{{Jeedom n\'a pas répondu.}}')
        return
      }
      if (options.silent === true) { return }
      domUtils.handleAjaxError(request, status, error)
    },
    success: function (data) {
      release()
      if (data.state != 'ok') {
        if (isset(options.failure)) {
          options.failure(data.result)
          return
        }
        if (options.silent !== true) {
          jeedomUtils.showAlert({ message: data.result, level: 'danger' })
        }
        return
      }
      _success(data.result)
    }
  })
}

/* Identifiant du groupe ouvert, ou null s'il n'est pas encore enregistré. */
function lampesoirmatinbeCurrentId(_quiet) {
  var input = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  if (input === null || input.value === '') {
    if (_quiet !== true) {
      jeedomUtils.showAlert({ message: '{{Enregistrez d\'abord le groupe.}}', level: 'warning' })
    }
    return null
  }
  return input.value
}

/* Le coeur teste DEUX drapeaux avant d'avertir qu'on quitte une page modifiée :
   n'en poser qu'un laisse passer la perte de données une fois sur deux. */
function lampesoirmatinbeMarkModified() {
  if (lampesoirmatinbeRendering) { return }
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
  window.modifyWithoutSave = true
}

/* Une ligne de texte posée sans balisage : les noms affichés viennent des
   équipements de l'utilisateur, et rien ne garantit ce qu'ils contiennent. */
function lampesoirmatinbeText(_tag, _className, _text) {
  var element = document.createElement(_tag)
  if (_className !== '') { element.className = _className }
  element.textContent = String(isset(_text) ? _text : '')
  return element
}

/* ========================================================= LAMPES DU GROUPE */

/* La pastille d'état d'une lampe : allumée, éteinte, ou inconnue. C'est elle
   qui permet de reconnaître une lampe du premier coup d'oeil après avoir appuyé
   sur le bouton d'essai. */
function lampesoirmatinbeStateDot(_value) {
  var icon = document.createElement('i')
  if (_value === null || _value === undefined || _value === '') {
    icon.className = 'fas fa-circle'
    icon.style.opacity = '0.25'
    icon.title = '{{État inconnu}}'
  } else if (_value == 1) {
    icon.className = 'fas fa-circle'
    icon.style.color = '#f0ad4e'
    icon.title = '{{Allumée}}'
  } else {
    icon.className = 'far fa-circle'
    icon.style.opacity = '0.5'
    icon.title = '{{Éteinte}}'
  }
  icon.style.marginRight = '6px'
  return icon
}

/* Dessine les lampes retenues. Un groupe vide le dit : une liste vide sans un
   mot ressemble à un chargement qui n'a pas abouti. */
function lampesoirmatinbeRenderLamps() {
  var container = document.getElementById('div_lampesoirmatinbeLamps')
  if (container === null) { return }
  container.innerHTML = ''

  if (lampesoirmatinbeSelection.length === 0) {
    var empty = document.createElement('div')
    empty.className = 'alert alert-warning'
    empty.style.margin = '5px'
    empty.textContent = '{{Aucune lampe dans ce groupe : la programmation n\'aura rien à commander. Cliquez sur « Choisir les lampes ».}}'
    container.appendChild(empty)
    return
  }

  var table = document.createElement('table')
  table.className = 'table table-condensed table-bordered'
  var tbody = document.createElement('tbody')
  table.appendChild(tbody)

  for (var i = 0; i < lampesoirmatinbeSelection.length; i++) {
    var lamp = lampesoirmatinbeSelection[i]
    var row = document.createElement('tr')

    var nameCell = document.createElement('td')
    nameCell.appendChild(lampesoirmatinbeStateDot(isset(lamp.value) ? lamp.value : null))
    nameCell.appendChild(lampesoirmatinbeText('span', '', lamp.name))
    if (isset(lamp.object) && lamp.object !== '') {
      var room = lampesoirmatinbeText('span', 'label label-default', lamp.object)
      room.style.marginLeft = '8px'
      nameCell.appendChild(room)
    }
    /* Une lampe supprimée de Jeedom reste dans la configuration : le dire est la
       seule façon d'expliquer pourquoi le groupe n'allume plus tout. */
    if (lamp.missing == 1) {
      var missing = lampesoirmatinbeText('span', 'label label-danger', '{{Équipement supprimé}}')
      missing.style.marginLeft = '8px'
      nameCell.appendChild(missing)
    } else if (isset(lamp.enabled) && lamp.enabled == 0) {
      var disabled = lampesoirmatinbeText('span', 'label label-warning', '{{Équipement désactivé}}')
      disabled.style.marginLeft = '8px'
      nameCell.appendChild(disabled)
    }
    row.appendChild(nameCell)

    var actionCell = document.createElement('td')
    actionCell.style.width = '150px'
    actionCell.style.textAlign = 'right'
    if (lamp.missing != 1) {
      actionCell.innerHTML = '<a class="btn btn-xs btn-warning lsmLampOn" title="{{Allumer cette lampe}}"><i class="fas fa-lightbulb"></i></a> '
                           + '<a class="btn btn-xs btn-default lsmLampOff" title="{{Éteindre cette lampe}}"><i class="far fa-lightbulb"></i></a> '
    }
    actionCell.innerHTML += '<a class="btn btn-xs btn-danger lsmLampRemove" title="{{Retirer du groupe}}"><i class="fas fa-times"></i></a>'
    actionCell.setAttribute('data-eq', lamp.eq)
    row.appendChild(actionCell)

    tbody.appendChild(row)
  }
  container.appendChild(table)
}

/* L'état de la programmation : l'étiquette, le bouton qui l'inverse, et le
   bandeau au-dessus des lampes. Un groupe suspendu doit se voir sans qu'on ait
   à chercher — c'est la panne la plus discrète du plugin : tout fonctionne, et
   rien ne s'allume. */
function lampesoirmatinbeShowPaused(_paused, _since) {
  var label = document.getElementById('span_lampesoirmatinbePaused')
  var button = document.getElementById('bt_lampesoirmatinbePause')
  if (label === null || button === null) { return }

  if (_paused) {
    label.className = 'label label-warning'
    label.textContent = (isset(_since) && _since !== '')
      ? '{{Suspendue depuis le}} ' + _since
      : '{{Suspendue}}'
    button.innerHTML = '<i class="fas fa-play"></i> {{Reprendre}}'
    button.setAttribute('data-state', '0')
  } else {
    label.className = 'label label-success'
    label.textContent = '{{Active}}'
    button.innerHTML = '<i class="fas fa-pause"></i> {{Suspendre}}'
    button.setAttribute('data-state', '1')
  }
}

/* Ce que le serveur sait du groupe : ses lampes telles qu'elles s'appellent
   aujourd'hui, et les prochaines occurrences de chaque moment. */
function lampesoirmatinbeLoadGroup(_id) {
  lampesoirmatinbeAjax('group', { id: _id }, function (result) {
    /* La réponse peut arriver après que l'utilisateur a ouvert un autre groupe :
       l'écrire alors mélangerait deux équipements. */
    var current = lampesoirmatinbeCurrentId(true)
    if (current === null || String(current) !== String(_id)) { return }

    /* Les noms et les états viennent du serveur, la composition du groupe reste
       celle de l'écran : l'utilisateur a peut-être coché des lampes depuis. */
    for (var i = 0; i < lampesoirmatinbeSelection.length; i++) {
      for (var j = 0; j < result.lamps.length; j++) {
        if (String(result.lamps[j].eq) === String(lampesoirmatinbeSelection[i].eq)) {
          lampesoirmatinbeSelection[i].name = result.lamps[j].name
          lampesoirmatinbeSelection[i].object = result.lamps[j].object
          lampesoirmatinbeSelection[i].missing = result.lamps[j].missing
          lampesoirmatinbeSelection[i].enabled = result.lamps[j].enabled
          lampesoirmatinbeSelection[i].value = result.lamps[j].value
        }
      }
    }
    lampesoirmatinbeRenderLamps()
    lampesoirmatinbeShowPreview('evening', result.evening)
    lampesoirmatinbeShowPreview('morning', result.morning)
    lampesoirmatinbeShowPaused(result.paused == 1, result.pausedSince)
  }, { silent: true })
}

/* ============================================================= SÉLECTEUR */

/* Ferme le sélecteur. jeeDialog n'expose pas de fonction de fermeture : elle
   est accrochée à l'élément de la fenêtre, que le getter du coeur retrouve. */
function lampesoirmatinbeClosePicker() {
  if (typeof jeeDialog === 'undefined') { return }
  var dialog = jeeDialog.get('#jee_modal')
  if (dialog !== null && typeof dialog.close === 'function') { dialog.close() }
}

function lampesoirmatinbeOpenPicker() {
  if (typeof jeeDialog === 'undefined') {
    jeedomUtils.showAlert({ message: '{{Cette version de Jeedom ne sait pas ouvrir le sélecteur.}}', level: 'danger' })
    return
  }
  /* Les lampes déjà retenues arrivent cochées : le sélecteur sert aussi bien à
     ajouter qu'à retirer, et rouvrir sur une liste vierge donnerait l'impression
     d'avoir tout perdu. */
  lampesoirmatinbePicker.checked = {}
  lampesoirmatinbePicker.choice = {}
  for (var i = 0; i < lampesoirmatinbeSelection.length; i++) {
    var known = lampesoirmatinbeSelection[i]
    lampesoirmatinbePicker.checked[known.eq] = true
    /* Ce qui a déjà été retenu pour cette lampe, y compris un choix fait à la
       main la fois précédente : le sélecteur doit rouvrir sur l'existant. */
    lampesoirmatinbePicker.choice[known.eq] = { on: known.on, off: known.off, toggle: known.toggle, state: known.state }
  }
  lampesoirmatinbePicker.search = ''
  lampesoirmatinbePicker.loadedAll = false

  jeeDialog.dialog({
    id: 'jee_modal',
    title: '{{Choisir les lampes de ce groupe}}',
    contentUrl: 'index.php?v=d&plugin=lampesoirmatinbe&modal=lamp.picker',
    callback: function () { lampesoirmatinbePickerStart() }
  })
}

/* La fenêtre existe : on branche ses écouteurs et on lance la découverte.
   Les écouteurs sont posés ici, sur la racine de la fenêtre, et disparaissent
   avec elle — sur le corps du document, ils s'empileraient à chaque ouverture. */
function lampesoirmatinbePickerStart() {
  var root = document.getElementById('div_lampesoirmatinbePicker')
  if (root === null) { return }

  root.addEventListener('input', function (event) {
    if (event.target.closest('#in_lampesoirmatinbeSearch')) {
      lampesoirmatinbePicker.search = event.target.value.trim().toLowerCase()
      lampesoirmatinbePickerRender()
    }
  })

  root.addEventListener('click', function (event) {
    var target = null

    if (target = event.target.closest('.lsmFilter')) {
      var filter = target.getAttribute('data-filter')
      lampesoirmatinbePicker.filters[filter] = !lampesoirmatinbePicker.filters[filter]
      target.classList.toggle('active', lampesoirmatinbePicker.filters[filter])
      /* Les équipements inconnus ne sont pas dans la première réponse : les
         montrer demande de redemander la liste, complète cette fois. */
      if (filter === 'unknown' && lampesoirmatinbePicker.filters.unknown && !lampesoirmatinbePicker.loadedAll) {
        lampesoirmatinbePickerLoad(true, target)
        return
      }
      lampesoirmatinbePickerRender()
      return
    }
    if (target = event.target.closest('.lsmPickTune')) {
      var block = target.closest('.lsmPickRow').querySelector('.lsmPickCmds')
      block.style.display = (block.style.display === 'none') ? '' : 'none'
      return
    }
    if (target = event.target.closest('.lsmPickRoom')) {
      /* Cocher une pièce entière est le geste le plus fréquent : « toutes les
         lampes du salon » est une intention, pas six décisions. */
      var room = target.getAttribute('data-room')
      var checkboxes = document.querySelectorAll('#div_lampesoirmatinbePickerList .lsmPickLamp[data-room="' + CSS.escape(room) + '"]')
      var check = target.getAttribute('data-check') !== '0'
      for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = check
        lampesoirmatinbePicker.checked[checkboxes[i].getAttribute('data-eq')] = check
      }
      target.setAttribute('data-check', check ? '0' : '1')
      lampesoirmatinbePickerCount()
      return
    }
    if ((target = event.target.closest('.lsmPickOn')) || (target = event.target.closest('.lsmPickOff'))) {
      lampesoirmatinbePickerSwitch(target, target.classList.contains('lsmPickOn') ? 'on' : 'off')
      return
    }
    if (event.target.closest('#bt_lampesoirmatinbePickerValidate')) {
      lampesoirmatinbePickerValidate()
      return
    }
    if (event.target.closest('#bt_lampesoirmatinbePickerCancel')) {
      lampesoirmatinbeClosePicker()
      return
    }
  })

  root.addEventListener('change', function (event) {
    var checkbox = event.target.closest('.lsmPickLamp')
    if (checkbox !== null) {
      lampesoirmatinbePicker.checked[checkbox.getAttribute('data-eq')] = checkbox.checked
      lampesoirmatinbePickerCount()
      return
    }
    var select = event.target.closest('.lsmPickCmd')
    if (select === null) { return }
    var eq = select.getAttribute('data-eq')
    if (!isset(lampesoirmatinbePicker.choice[eq])) { lampesoirmatinbePicker.choice[eq] = {} }
    lampesoirmatinbePicker.choice[eq][select.getAttribute('data-role')] = (select.value === '') ? null : parseInt(select.value, 10)
    /* Désigner une commande, c'est vouloir la lampe : cocher soi-même ensuite
       serait un geste de plus pour rien. */
    var row = select.closest('.lsmPickRow').querySelector('.lsmPickLamp')
    if (row !== null && !row.checked && select.value !== '') {
      row.checked = true
      lampesoirmatinbePicker.checked[eq] = true
      lampesoirmatinbePickerCount()
    }
  })

  lampesoirmatinbePickerLoad(false, null)
}

/* Demande la liste au serveur. _all ajoute les équipements dont le plugin ne
   sait rien dire. */
function lampesoirmatinbePickerLoad(_all, _button) {
  var list = document.getElementById('div_lampesoirmatinbePickerList')
  if (list !== null) {
    list.innerHTML = '<div class="text-center" style="padding:30px;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>'
  }
  lampesoirmatinbeAjax('lamps', _all ? { all: 1 } : {}, function (result) {
    lampesoirmatinbePicker.groups = result.groups
    if (_all) { lampesoirmatinbePicker.loadedAll = true }
    lampesoirmatinbePickerRender()
  }, {
    button: _button,
    failure: function (message) {
      var target = document.getElementById('div_lampesoirmatinbePickerList')
      if (target === null) { return }
      target.innerHTML = ''
      target.appendChild(lampesoirmatinbeText('div', 'alert alert-danger', message))
    }
  })
}

/* Allume ou éteint une lampe depuis le sélecteur, pour la reconnaître.
   Seul l'équipement est envoyé : c'est le serveur qui décide quelle commande
   l'allume. */
function lampesoirmatinbePickerSwitch(_button, _order) {
  var eq = _button.getAttribute('data-eq')
  /* La commande désignée à la main l'emporte : pour un équipement inconnu, le
     serveur n'a rien d'autre pour savoir quoi jouer. */
  var choice = isset(lampesoirmatinbePicker.choice[eq]) ? lampesoirmatinbePicker.choice[eq] : {}
  var cmd = (_order === 'on') ? choice.on : choice.off
  lampesoirmatinbeAjax('switchLamp', { eq: eq, order: _order, cmd: isset(cmd) && cmd !== null ? cmd : '' }, function () {
    var dot = document.querySelector('#div_lampesoirmatinbePickerList .lsmPickDot[data-eq="' + eq + '"]')
    if (dot !== null) {
      dot.replaceWith(lampesoirmatinbePickerDot(eq, (_order === 'on') ? 1 : 0))
    }
  }, { button: _button })
}

function lampesoirmatinbePickerDot(_eq, _value) {
  var dot = lampesoirmatinbeStateDot(_value)
  dot.classList.add('lsmPickDot')
  dot.setAttribute('data-eq', _eq)
  return dot
}

/* Dessine la liste, filtres et recherche appliqués. */
function lampesoirmatinbePickerRender() {
  var list = document.getElementById('div_lampesoirmatinbePickerList')
  if (list === null) { return }
  list.innerHTML = ''

  var counts = { light: 0, plug: 0, guess: 0, unknown: 0 }
  var shown = 0

  for (var g = 0; g < lampesoirmatinbePicker.groups.length; g++) {
    var group = lampesoirmatinbePicker.groups[g]
    var visible = []

    for (var l = 0; l < group.lamps.length; l++) {
      var lamp = group.lamps[l]
      counts[lamp.confidence]++
      if (lampesoirmatinbePicker.filters[lamp.confidence] !== true) { continue }
      if (lampesoirmatinbePicker.search !== '') {
        var haystack = (lamp.name + ' ' + group.object + ' ' + lamp.plugin).toLowerCase()
        if (haystack.indexOf(lampesoirmatinbePicker.search) === -1) { continue }
      }
      visible.push(lamp)
    }
    if (visible.length === 0) { continue }
    shown += visible.length

    var header = document.createElement('div')
    header.style.cssText = 'margin:10px 0 4px 0;padding-bottom:3px;border-bottom:1px solid rgba(128,128,128,0.3);'
    header.appendChild(lampesoirmatinbeText('b', '', group.object))
    var all = document.createElement('a')
    all.className = 'btn btn-xs btn-default pull-right lsmPickRoom'
    all.setAttribute('data-room', group.object)
    all.setAttribute('data-check', '1')
    all.textContent = '{{Tout cocher}}'
    header.appendChild(all)
    list.appendChild(header)

    for (var v = 0; v < visible.length; v++) {
      list.appendChild(lampesoirmatinbePickerRow(visible[v], group.object))
    }
  }

  for (var key in counts) {
    var badge = document.querySelector('#div_lampesoirmatinbePicker .badge[data-count="' + key + '"]')
    if (badge !== null) { badge.textContent = counts[key] }
  }

  if (shown === 0) {
    var empty = document.createElement('div')
    empty.className = 'alert alert-warning'
    empty.textContent = (lampesoirmatinbePicker.search !== '')
      ? '{{Aucune lampe ne correspond à cette recherche.}}'
      : '{{Aucune lampe trouvée avec ces filtres. Essayez « Autres » : certains protocoles ne renseignent pas le type des commandes.}}'
    list.appendChild(empty)
  }
  lampesoirmatinbePickerCount()
}

function lampesoirmatinbePickerRow(_lamp, _room) {
  var eq = parseInt(_lamp.eq, 10)
  var isUnknown = (_lamp.confidence === 'unknown')

  var row = document.createElement('div')
  row.className = 'lsmPickRow'
  row.setAttribute('data-eq', eq)
  row.style.cssText = 'padding:3px 0;'

  var line = document.createElement('div')
  line.style.cssText = 'display:flex;align-items:center;'

  var label = document.createElement('label')
  label.style.cssText = 'flex:1;margin:0;font-weight:normal;cursor:pointer;'

  var checkbox = document.createElement('input')
  checkbox.type = 'checkbox'
  checkbox.className = 'lsmPickLamp'
  checkbox.setAttribute('data-eq', eq)
  checkbox.setAttribute('data-room', _room)
  checkbox.checked = (lampesoirmatinbePicker.checked[eq] === true)
  checkbox.style.marginRight = '8px'
  label.appendChild(checkbox)

  label.appendChild(lampesoirmatinbePickerDot(eq, _lamp.value))
  label.appendChild(lampesoirmatinbeText('span', '', _lamp.name))

  /* D'où vient la lampe et à quel point on en est sûr : sans cela, deux
     équipements homonymes venus de deux plugins seraient indiscernables. */
  var origin = lampesoirmatinbeText('span', 'label label-default', _lamp.plugin)
  origin.style.marginLeft = '8px'
  origin.style.opacity = '0.7'
  label.appendChild(origin)

  if (_lamp.confidence !== 'light') {
    var kind = lampesoirmatinbeText('span', isUnknown ? 'label label-warning' : 'label label-info',
      (_lamp.confidence === 'plug') ? '{{prise}}'
        : (isUnknown ? '{{à désigner}}' : '{{reconnue au nom}}'))
    kind.style.marginLeft = '4px'
    label.appendChild(kind)
  }
  line.appendChild(label)

  var buttons = document.createElement('span')
  buttons.style.whiteSpace = 'nowrap'
  buttons.innerHTML = '<a class="btn btn-xs btn-default lsmPickTune" title="{{Voir et changer les commandes retenues}}"><i class="fas fa-sliders-h"></i></a> '
                    + '<a class="btn btn-xs btn-warning lsmPickOn" data-eq="' + eq + '" title="{{Allumer pour la reconnaître}}"><i class="fas fa-lightbulb"></i></a> '
                    + '<a class="btn btn-xs btn-default lsmPickOff" data-eq="' + eq + '" title="{{Éteindre}}"><i class="far fa-lightbulb"></i></a>'
  line.appendChild(buttons)
  row.appendChild(line)

  /*
   * Les deux commandes retenues, modifiables.
   *
   * Dépliées d'office pour un équipement inconnu : c'est la seule chose à y
   * faire, et une ligne cochée sans commande ne commanderait rien. Repliées
   * pour les autres, dont le plugin s'est déjà chargé.
   */
  var cmds = document.createElement('div')
  cmds.className = 'lsmPickCmds'
  cmds.style.cssText = 'padding:4px 0 8px 26px;display:' + (isUnknown ? '' : 'none') + ';'
  cmds.appendChild(lampesoirmatinbePickerCmdSelect(_lamp, 'on', '{{Allumer avec}}'))
  cmds.appendChild(lampesoirmatinbePickerCmdSelect(_lamp, 'off', '{{Éteindre avec}}'))
  row.appendChild(cmds)

  return row
}

/* Une liste déroulante des commandes d'action de l'équipement, positionnée sur
   celle que le plugin a retenue — ou sur celle que l'utilisateur a désignée. */
function lampesoirmatinbePickerCmdSelect(_lamp, _role, _label) {
  var eq = parseInt(_lamp.eq, 10)
  var chosen = isset(lampesoirmatinbePicker.choice[eq]) && isset(lampesoirmatinbePicker.choice[eq][_role])
    ? lampesoirmatinbePicker.choice[eq][_role]
    : _lamp[_role]

  var group = document.createElement('div')
  group.style.cssText = 'display:inline-block;margin-right:12px;'
  var caption = lampesoirmatinbeText('span', '', _label + ' ')
  caption.style.opacity = '0.75'
  group.appendChild(caption)

  var select = document.createElement('select')
  select.className = 'lsmPickCmd'
  select.setAttribute('data-eq', eq)
  select.setAttribute('data-role', _role)
  select.style.cssText = 'max-width:220px;display:inline-block;'
  select.classList.add('form-control', 'input-sm')

  var none = document.createElement('option')
  none.value = ''
  none.textContent = '{{aucune}}'
  select.appendChild(none)

  var cmds = isset(_lamp.cmds) ? _lamp.cmds : []
  for (var i = 0; i < cmds.length; i++) {
    var option = document.createElement('option')
    option.value = cmds[i].id
    option.textContent = cmds[i].name
    if (chosen !== null && chosen !== undefined && String(chosen) === String(cmds[i].id)) {
      option.selected = true
    }
    select.appendChild(option)
  }
  /* Une bascule tient lieu des deux ordres quand l'équipement ne sait faire que
     ça : elle est proposée dans les deux listes, sous son vrai nom. */
  group.appendChild(select)
  return group
}

function lampesoirmatinbePickerCount() {
  var count = 0
  for (var eq in lampesoirmatinbePicker.checked) {
    if (lampesoirmatinbePicker.checked[eq] === true) { count++ }
  }
  var label = document.getElementById('span_lampesoirmatinbePickerCount')
  if (label !== null) { label.textContent = count + ' {{sélectionnée(s)}}' }
}

/* Reporte la sélection dans le groupe. Les commandes on/off/état sont celles
   que le détecteur a retenues, l'utilisateur n'a jamais à les voir. */
function lampesoirmatinbePickerValidate() {
  var selection = []
  var sansCommande = []

  for (var g = 0; g < lampesoirmatinbePicker.groups.length; g++) {
    var group = lampesoirmatinbePicker.groups[g]
    for (var l = 0; l < group.lamps.length; l++) {
      var lamp = group.lamps[l]
      if (lampesoirmatinbePicker.checked[lamp.eq] !== true) { continue }

      /* Ce que l'utilisateur a désigné l'emporte sur ce que le plugin a retenu,
         y compris « aucune » : hasOwnProperty et non une valeur par défaut, sans
         quoi un choix remis à « aucune » retomberait sur la détection. */
      var choice = isset(lampesoirmatinbePicker.choice[lamp.eq]) ? lampesoirmatinbePicker.choice[lamp.eq] : {}
      var on = choice.hasOwnProperty('on') ? choice.on : lamp.on
      var off = choice.hasOwnProperty('off') ? choice.off : lamp.off

      /* Une ligne cochée sans rien pour allumer ni éteindre ne commanderait
         jamais rien : l'écarter en silence serait pire que de le dire. */
      if ((on === null || on === undefined) && (off === null || off === undefined)
          && (lamp.toggle === null || lamp.toggle === undefined)) {
        sansCommande.push(lamp.name)
        continue
      }

      selection.push({
        eq: lamp.eq, name: lamp.name, object: group.object,
        on: isset(on) ? on : null, off: isset(off) ? off : null,
        toggle: lamp.toggle, state: lamp.state,
        value: lamp.value, missing: 0, enabled: 1
      })
    }
  }

  lampesoirmatinbeSelection = selection
  lampesoirmatinbeRenderLamps()
  lampesoirmatinbeMarkModified()
  lampesoirmatinbeClosePicker()

  if (sansCommande.length > 0) {
    jeedomUtils.showAlert({
      message: '{{Écartées, faute de commande désignée :}} ' + sansCommande.join(', '),
      level: 'warning', timeOut: 8000
    })
  }
  jeedomUtils.showAlert({ message: selection.length + ' {{lampe(s) dans ce groupe. Pensez à sauvegarder.}}', level: 'success' })
}

/* =========================================================== PROGRAMMATION */

function lampesoirmatinbeSlotElement(_key) {
  return document.querySelector('.lsmSlot[data-slot="' + _key + '"]')
}

/* Montre les champs qui servent au mode choisi, et nomme l'événement dans le
   groupe de saisie : « 30 min avant [le coucher du soleil] » se relit tout seul,
   « 30 min avant » ne veut rien dire. */
function lampesoirmatinbeSyncSlotUi(_key) {
  var block = lampesoirmatinbeSlotElement(_key)
  if (block === null) { return }
  var mode = block.querySelector('.lsmMode').value
  var isFixed = (mode === 'fixed')

  block.querySelector('.lsmFixed').style.display = isFixed ? '' : 'none'
  block.querySelector('.lsmSun').style.display = isFixed ? 'none' : ''
  block.querySelector('.lsmEventName').textContent = (mode === 'sunrise')
    ? '{{le lever du soleil}}'
    : '{{le coucher du soleil}}'
}

/* Relève un moment tel qu'il est à l'écran. Les jours et le décalage signé ne
   passent pas par la mécanique du coeur : ils sont lus à la main. */
function lampesoirmatinbeReadSlot(_key) {
  var block = lampesoirmatinbeSlotElement(_key)
  if (block === null) { return null }

  var value = parseInt(block.querySelector('.lsmOffsetValue').value, 10)
  if (isNaN(value) || value < 0) { value = 0 }
  var offset = (block.querySelector('.lsmOffsetWay').value === 'before') ? -value : value

  var days = []
  var boxes = block.querySelectorAll('.lsmDay')
  for (var i = 0; i < boxes.length; i++) {
    if (boxes[i].checked) { days.push(parseInt(boxes[i].getAttribute('data-day'), 10)) }
  }

  var enableBox = block.querySelector('.eqLogicAttr[data-l3key="enable"]')
  return {
    enable: (enableBox !== null && enableBox.checked) ? 1 : 0,
    action: block.querySelector('.eqLogicAttr[data-l3key="action"]').value,
    mode: block.querySelector('.lsmMode').value,
    time: block.querySelector('.eqLogicAttr[data-l3key="time"]').value,
    offset: offset,
    random: parseInt(block.querySelector('.eqLogicAttr[data-l3key="random"]').value, 10) || 0,
    not_before: block.querySelector('.eqLogicAttr[data-l3key="not_before"]').value,
    not_after: block.querySelector('.eqLogicAttr[data-l3key="not_after"]').value,
    days: days
  }
}

/* Pose un moment à l'écran. Le coeur a déjà rempli les .eqLogicAttr : restent
   les jours, le décalage qu'on montre en valeur absolue et en sens, et les
   valeurs d'un groupe qui vient d'être créé. */
function lampesoirmatinbeApplySlot(_key, _slot) {
  var block = lampesoirmatinbeSlotElement(_key)
  if (block === null) { return }
  var slot = _slot || {}

  /* Un groupe neuf arrive sans configuration : le formulaire montrerait alors
     une heure vide, « Allumer » pour le matin comme pour le soir, et un aperçu
     qui annonce que rien ne se déclenchera jamais. On pose donc ce que le
     plugin poserait de toute façon à l'enregistrement — soir allumé, matin
     éteint, moments activés — pour que l'écran dise la vérité dès la création.
     Rien n'est exécuté tant que le groupe n'a pas de lampe. */
  if (!isset(slot.mode)) {
    var isEvening = (_key === 'evening')
    block.querySelector('.eqLogicAttr[data-l3key="enable"]').checked = true
    block.querySelector('.eqLogicAttr[data-l3key="action"]').value = isEvening ? 'on' : 'off'
    block.querySelector('.lsmMode').value = isEvening ? 'sunset' : 'sunrise'
    block.querySelector('.eqLogicAttr[data-l3key="time"]').value = isEvening ? '19:00' : '07:00'
    slot = { offset: isEvening ? -15 : 30 }
  }

  var offset = parseInt(slot.offset, 10)
  if (isNaN(offset)) { offset = 0 }
  block.querySelector('.lsmOffsetValue').value = Math.abs(offset)
  block.querySelector('.lsmOffsetWay').value = (offset > 0) ? 'after' : 'before'

  /* Un groupe neuf n'a pas de jours enregistrés : tous cochés, parce qu'une
     programmation qui ne s'applique aucun jour ne sert à rien et que l'erreur
     ne se verrait qu'au bout d'une semaine. */
  var days = (isset(slot.days) && Array.isArray(slot.days)) ? slot.days : [1, 2, 3, 4, 5, 6, 7]
  var boxes = block.querySelectorAll('.lsmDay')
  for (var i = 0; i < boxes.length; i++) {
    boxes[i].checked = (days.indexOf(parseInt(boxes[i].getAttribute('data-day'), 10)) !== -1)
  }

  lampesoirmatinbeSyncSlotUi(_key)
}

/* Affiche l'aperçu rendu par le serveur. */
function lampesoirmatinbeShowPreview(_key, _preview) {
  var block = lampesoirmatinbeSlotElement(_key)
  if (block === null) { return }
  var target = block.querySelector('.lsmPreview')
  target.innerHTML = ''

  if (!isset(_preview) || !isset(_preview.occurrences)) {
    target.textContent = '—'
    return
  }
  var verb = (_preview.action === 'on') ? '{{Allumage}}' : '{{Extinction}}'
  if (_preview.occurrences.length === 0) {
    target.appendChild(lampesoirmatinbeText('span', 'text-warning',
      '{{Ce moment ne se déclenchera jamais : moment désactivé, aucun jour coché, ou position de l\'installation manquante.}}'))
    return
  }
  target.appendChild(lampesoirmatinbeText('b', '', verb + ' : ' + _preview.occurrences.join(', ')))
  target.appendChild(document.createElement('br'))
  target.appendChild(lampesoirmatinbeText('small', 'text-muted', _preview.summary))

  /* Les heures ci-dessus sont plausibles et fausses tant que la position de
     l'installation n'est pas renseignée : le dire ici est le seul moment où
     l'utilisateur regarde. */
  if (_preview.noPosition == 1) {
    target.appendChild(document.createElement('br'))
    target.appendChild(lampesoirmatinbeText('small', 'text-danger',
      '{{Position de l\'installation absente : ces heures de soleil sont fausses. Réglages → Système → Configuration → Général.}}'))
  }
}

/* Demande l'aperçu au serveur, en laissant retomber la saisie.
   Le calcul est fait là-bas : c'est le même code qui décidera de l'ordre au
   moment venu, et deux implémentations divergeraient. */
var lampesoirmatinbePreviewTimer = { evening: null, morning: null }
function lampesoirmatinbeRefreshPreview(_key) {
  if (lampesoirmatinbePreviewTimer[_key] !== null) {
    clearTimeout(lampesoirmatinbePreviewTimer[_key])
  }
  lampesoirmatinbePreviewTimer[_key] = setTimeout(function () {
    var slot = lampesoirmatinbeReadSlot(_key)
    if (slot === null) { return }
    lampesoirmatinbeAjax('preview', {
      key: _key,
      slot: JSON.stringify(slot),
      id: lampesoirmatinbeCurrentId(true) || ''
    }, function (result) {
      lampesoirmatinbeShowPreview(_key, result)
    }, { silent: true })
  }, 300)
}

/* ================================================== CYCLE DE VIE DE LA PAGE */

function printEqLogic(_eqLogic) {
  lampesoirmatinbeRendering = true
  try {
    var configuration = (isset(_eqLogic) && isset(_eqLogic.configuration)) ? _eqLogic.configuration : {}

    lampesoirmatinbeSelection = []
    var lamps = isset(configuration.lamps) ? configuration.lamps : []
    for (var i = 0; i < lamps.length; i++) {
      lampesoirmatinbeSelection.push(Object.assign({}, lamps[i]))
    }
    lampesoirmatinbeRenderLamps()

    lampesoirmatinbeApplySlot('evening', isset(configuration.evening) ? configuration.evening : null)
    lampesoirmatinbeApplySlot('morning', isset(configuration.morning) ? configuration.morning : null)
    lampesoirmatinbeShowPreview('evening', null)
    lampesoirmatinbeShowPreview('morning', null)
    /* Le coeur ne réinitialise que les .eqLogicAttr : sans cela, l'étiquette
       garderait l'état du groupe précédemment ouvert. */
    lampesoirmatinbeShowPaused(false, '')
  } finally {
    lampesoirmatinbeRendering = false
  }

  if (isset(_eqLogic.id) && _eqLogic.id != '') {
    lampesoirmatinbeLoadGroup(_eqLogic.id)
  } else {
    /* Groupe neuf : l'aperçu se calcule quand même, sur ce que montre l'écran. */
    lampesoirmatinbeRefreshPreview('evening')
    lampesoirmatinbeRefreshPreview('morning')
  }
}

/* Appelée par plugin.template.js juste avant l'enregistrement. Les lampes et
   les jours sont des listes imbriquées : data-lXkey ne descend pas jusque-là,
   il faut les poser à la main. */
function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic.configuration)) { _eqLogic.configuration = {} }

  var lamps = []
  for (var i = 0; i < lampesoirmatinbeSelection.length; i++) {
    var lamp = lampesoirmatinbeSelection[i]
    lamps.push({
      eq: lamp.eq, name: lamp.name, object: lamp.object,
      on: lamp.on, off: lamp.off, toggle: lamp.toggle, state: lamp.state
    })
  }
  _eqLogic.configuration.lamps = lamps

  var keys = ['evening', 'morning']
  for (var k = 0; k < keys.length; k++) {
    var slot = lampesoirmatinbeReadSlot(keys[k])
    if (slot !== null) { _eqLogic.configuration[keys[k]] = slot }
  }
  return _eqLogic
}

/* ================================================================ COMMANDES */

/* Ligne du tableau des commandes. */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
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
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '<a class="btn btn-danger btn-xs cmdAction pull-right" data-action="remove"><i class="fas fa-minus-circle"></i></a>'
  tr += '</td>'

  /* Une ligne créée en DOM : insertAdjacentHTML sur la table génère un <tbody>
     par insertion et toutes les commandes se retrouveraient dans la même ligne. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* ================================================================ ÉCOUTEURS */

/* Les pages sont chargées en AJAX : DOMContentLoaded a déjà eu lieu. Les
   écouteurs sont donc posés à la racine du script, sur le conteneur de page —
   qui est remplacé à chaque navigation, ce qui les emporte avec lui. */
var lampesoirmatinbeContainer = document.getElementById('div_pageContainer') || document.body

lampesoirmatinbeContainer.addEventListener('change', function (event) {
  var block = event.target.closest('.lsmSlot')
  if (block === null) { return }
  if (event.target.closest('.lsmMode')) {
    lampesoirmatinbeSyncSlotUi(block.getAttribute('data-slot'))
  }
  /* Les jours et le décalage ne sont pas des .eqLogicAttr : le coeur ne les voit
     pas changer, et sans cela on quitterait la page en perdant un réglage tout
     juste posé, sans le moindre avertissement. */
  if (event.target.closest('.lsmDay') || event.target.closest('.lsmOffsetValue') || event.target.closest('.lsmOffsetWay')) {
    lampesoirmatinbeMarkModified()
  }
  if (event.target.closest('.lsmPreviewTrigger')) {
    lampesoirmatinbeRefreshPreview(block.getAttribute('data-slot'))
  }
})

lampesoirmatinbeContainer.addEventListener('input', function (event) {
  var block = event.target.closest('.lsmSlot')
  if (block === null || !event.target.closest('.lsmPreviewTrigger')) { return }
  lampesoirmatinbeRefreshPreview(block.getAttribute('data-slot'))
})

lampesoirmatinbeContainer.addEventListener('click', function (event) {
  var target = null

  if (event.target.closest('#bt_lampesoirmatinbePick')) {
    lampesoirmatinbeOpenPicker()
    return
  }

  if (target = event.target.closest('.lsmAllDays')) {
    var boxes = lampesoirmatinbeSlotElement(target.getAttribute('data-slot')).querySelectorAll('.lsmDay')
    for (var i = 0; i < boxes.length; i++) { boxes[i].checked = true }
    lampesoirmatinbeMarkModified()
    lampesoirmatinbeRefreshPreview(target.getAttribute('data-slot'))
    return
  }

  if (target = event.target.closest('.lsmLampRemove')) {
    var eq = target.closest('td').getAttribute('data-eq')
    for (var j = lampesoirmatinbeSelection.length - 1; j >= 0; j--) {
      if (String(lampesoirmatinbeSelection[j].eq) === String(eq)) {
        lampesoirmatinbeSelection.splice(j, 1)
      }
    }
    lampesoirmatinbeRenderLamps()
    lampesoirmatinbeMarkModified()
    return
  }

  if ((target = event.target.closest('.lsmLampOn')) || (target = event.target.closest('.lsmLampOff'))) {
    var order = target.classList.contains('lsmLampOn') ? 'on' : 'off'
    var cell = target.closest('td')
    lampesoirmatinbeAjax('switchLamp', { eq: cell.getAttribute('data-eq'), order: order }, function () {
      var openId = lampesoirmatinbeCurrentId(true)
      if (openId !== null) { lampesoirmatinbeLoadGroup(openId) }
    }, { button: target })
    return
  }

  if (target = event.target.closest('#bt_lampesoirmatinbePause')) {
    var pauseId = lampesoirmatinbeCurrentId()
    if (pauseId === null) { return }
    lampesoirmatinbeAjax('pause', { id: pauseId, state: target.getAttribute('data-state') }, function (result) {
      lampesoirmatinbeShowPaused(result.paused == 1, '')
      lampesoirmatinbeLoadGroup(pauseId)
      jeedomUtils.showAlert({ message: result.summary, level: (result.paused == 1) ? 'warning' : 'success' })
    }, { button: target })
    return
  }

  if ((target = event.target.closest('#bt_lampesoirmatinbeTestOn')) || (target = event.target.closest('#bt_lampesoirmatinbeTestOff'))) {
    var groupId = lampesoirmatinbeCurrentId()
    if (groupId === null) { return }
    var groupOrder = (target.id === 'bt_lampesoirmatinbeTestOn') ? 'on' : 'off'
    lampesoirmatinbeAjax('testGroup', { id: groupId, order: groupOrder }, function (result) {
      jeedomUtils.showAlert({ message: result.summary, level: 'success' })
      lampesoirmatinbeLoadGroup(groupId)
    }, { button: target })
    return
  }
})
