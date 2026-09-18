<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('lampesoirmatinbe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());

/* Les jours, dans l'ordre européen. La clé est celle de date('N'). */
$lampesoirmatinbeDays = array(1 => '{{Lun}}', 2 => '{{Mar}}', 3 => '{{Mer}}', 4 => '{{Jeu}}',
                              5 => '{{Ven}}', 6 => '{{Sam}}', 7 => '{{Dim}}');

/*
 * Le bloc d'un moment, écrit une fois et posé deux fois : le soir et le matin
 * se règlent exactement de la même façon, seuls leur nom et leur action par
 * défaut diffèrent. Deux formulaires jumeaux écrits à la main divergeraient au
 * premier ajout de champ.
 */
function lampesoirmatinbeSlot($_key, $_title, $_icon, $_help) {
	global $lampesoirmatinbeDays;
	?>
	<fieldset class="lsmSlot" data-slot="<?php echo $_key; ?>">
		<legend><i class="<?php echo $_icon; ?>"></i> <?php echo $_title; ?></legend>

		<div class="form-group">
			<label class="col-sm-3 control-label">{{Activer ce moment}}</label>
			<div class="col-sm-2">
				<input type="checkbox" class="eqLogicAttr lsmPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="enable">
			</div>
			<label class="col-sm-2 control-label">{{Faire}}</label>
			<div class="col-sm-4">
				<select class="eqLogicAttr form-control lsmPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="action">
					<option value="on">{{Allumer les lampes}}</option>
					<option value="off">{{Éteindre les lampes}}</option>
				</select>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-3 control-label">{{Quand}}</label>
			<div class="col-sm-4">
				<select class="eqLogicAttr form-control lsmMode lsmPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="mode">
					<option value="fixed">{{À une heure fixe}}</option>
					<option value="sunset">{{Par rapport au coucher du soleil}}</option>
					<option value="sunrise">{{Par rapport au lever du soleil}}</option>
				</select>
			</div>
			<div class="col-sm-5 lsmFixed">
				<div class="input-group">
					<span class="input-group-addon roundedLeft">{{à}}</span>
					<input type="time" class="eqLogicAttr form-control roundedRight lsmPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="time" placeholder="20:00">
				</div>
			</div>
			<div class="col-sm-5 lsmSun">
				<div class="input-group">
					<input type="number" min="0" max="720" step="5" class="form-control roundedLeft lsmOffsetValue lsmPreviewTrigger" placeholder="30">
					<span class="input-group-addon">{{min}}</span>
					<select class="form-control lsmOffsetWay lsmPreviewTrigger">
						<option value="before">{{avant}}</option>
						<option value="after">{{après}}</option>
					</select>
					<span class="input-group-addon roundedRight lsmEventName"></span>
				</div>
				<!-- Le décalage signé, reconstitué par le JS : « 30 minutes avant »
				     se lit mieux que « -30 », et se tape sans erreur de signe. -->
				<input type="text" class="eqLogicAttr" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="offset" style="display:none;">
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-3 control-label">{{Jours}}</label>
			<div class="col-sm-9">
				<?php foreach ($lampesoirmatinbeDays as $number => $label) { ?>
					<label class="checkbox-inline" style="padding-left:20px;">
						<input type="checkbox" class="lsmDay lsmPreviewTrigger" data-slot="<?php echo $_key; ?>" data-day="<?php echo $number; ?>"> <?php echo $label; ?>
					</label>
				<?php } ?>
				<a class="btn btn-default btn-xs lsmAllDays" data-slot="<?php echo $_key; ?>" style="margin-left:10px;">{{Tous}}</a>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-3 control-label">
				{{Garde-fous}}
				<sup><i class="fas fa-question-circle" title="{{Le coucher du soleil varie de 16 h 30 en décembre à 22 h 00 en juin. Un garde-fou ramène le moment dans la fenêtre : « pas avant 17:30 » déclenche à 17:30 les soirs où le soleil se couche plus tôt. Laisser vide pour suivre le soleil toute l'année.}}"></i></sup>
			</label>
			<div class="col-sm-4">
				<div class="input-group">
					<span class="input-group-addon roundedLeft">{{Pas avant}}</span>
					<input type="time" class="eqLogicAttr form-control roundedRight lsmPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="not_before">
				</div>
			</div>
			<div class="col-sm-4">
				<div class="input-group">
					<span class="input-group-addon roundedLeft">{{Pas après}}</span>
					<input type="time" class="eqLogicAttr form-control roundedRight lsmPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="not_after">
				</div>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-3 control-label">
				{{Décalage aléatoire}}
				<sup><i class="fas fa-question-circle" title="{{Simulation de présence : le moment est avancé ou retardé au hasard, dans cette limite. Le tirage est fait une fois par jour, l'heure annoncée est donc celle qui sera réellement jouée.}}"></i></sup>
			</label>
			<div class="col-sm-4">
				<div class="input-group">
					<span class="input-group-addon roundedLeft">±</span>
					<input type="number" min="0" max="120" step="5" class="eqLogicAttr form-control lsmPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="random" placeholder="0">
					<span class="input-group-addon roundedRight">{{min}}</span>
				</div>
			</div>
			<div class="col-sm-5">
				<span class="help-block" style="margin:0;"><?php echo $_help; ?></span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-3 control-label">{{Prochaines fois}}</label>
			<div class="col-sm-9">
				<div class="lsmPreview well well-sm" style="margin:0;padding:8px 12px;">—</div>
			</div>
		</div>
	</fieldset>
	<?php
}
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter un groupe}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<legend><i class="fas fa-lightbulb"></i> {{Mes groupes de lampes}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucun groupe pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Cliquez sur « Ajouter un groupe » et donnez-lui un nom, par exemple « Salon » ou « Façade ».}}</li>';
			echo '<li>{{Cliquez sur « Choisir les lampes » : le plugin va les chercher dans votre installation, vous n\'avez qu\'à cocher. Le bouton d\'essai de chaque ligne allume la lampe pour la reconnaître.}}</li>';
			echo '<li>{{Onglet « Programmation » : réglez le soir et le matin, à heure fixe ou par rapport au soleil.}}</li>';
			echo '<li>{{Enregistrez. Rien d\'autre à faire, aucun scénario à écrire.}}</li>';
			echo '</ol>';
			echo '</div>';
		}
		if (!lampesoirmatinbe::hasPosition()) {
			echo '<div class="alert alert-warning" style="margin:5px;">';
			echo '<i class="fas fa-exclamation-triangle"></i> ';
			echo '{{La position de votre installation n\'est pas renseignée : les heures de lever et de coucher du soleil seraient fausses. Renseignez-la dans Réglages → Système → Configuration → Général.}}';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			/* Ce que ce groupe fera ce soir, sur la carte : c'est la seule page
			   d'où l'on voit toute la maison d'un coup d'oeil, et un nombre de
			   lampes n'y apprend rien qu'on ne sache déjà. */
			$summary = $eqLogic->cardSummary();
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="fas ' . ($summary['paused'] ? 'fa-pause-circle' : 'fa-lightbulb') . '" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<br><span style="font-size:0.85em;opacity:0.7;">' . $summary['lamps'] . ' {{lampe(s)}}</span>';
			echo '<br><span style="font-size:0.85em;' . ($summary['paused'] ? 'color:#f0ad4e;' : 'opacity:0.7;') . '">'
			   . $summary['text'] . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-lightbulb"></i><span class="hidden-xs"> {{Lampes}}</span></a></li>
			<li role="presentation"><a href="#scheduletab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-clock"></i><span class="hidden-xs"> {{Programmation}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ============================================ LAMPES ============================================ -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-5">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom du groupe}}</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Salon}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Objet parent}}</label>
								<div class="col-sm-7">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach (jeeObject::buildTree(null, false) as $object) {
											echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Activer}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
								<label class="col-sm-2 control-label">{{Visible}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-power-off"></i> {{Programmation}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{État}}</label>
								<div class="col-sm-8">
									<span id="span_lampesoirmatinbePaused" class="label label-success">{{Active}}</span>
									<a class="btn btn-sm btn-default" id="bt_lampesoirmatinbePause" style="margin-left:8px;"><i class="fas fa-pause"></i> {{Suspendre}}</a>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">&nbsp;</label>
								<div class="col-sm-8">
									<span class="help-block" style="margin:0;">{{Suspendre arrête les deux moments sans désactiver le groupe : les boutons continuent de fonctionner, le groupe reste sur le tableau de bord, et les commandes « Suspendre » et « Reprendre » se pilotent en scénario — un mode vacances ou une absence.}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-vial"></i> {{Essai}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Tout le groupe}}</label>
								<div class="col-sm-8">
									<a class="btn btn-warning" id="bt_lampesoirmatinbeTestOn"><i class="fas fa-lightbulb"></i> {{Allumer}}</a>
									<a class="btn btn-default" id="bt_lampesoirmatinbeTestOff"><i class="far fa-lightbulb"></i> {{Éteindre}}</a>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">&nbsp;</label>
								<div class="col-sm-8">
									<span class="help-block" style="margin:0;">{{L'essai commande réellement les lampes enregistrées : sauvegardez d'abord si vous venez de modifier la sélection.}}</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-7">
					<legend>
						<i class="fas fa-list-ul"></i> {{Lampes de ce groupe}}
						<a class="btn btn-sm btn-success pull-right" id="bt_lampesoirmatinbePick"><i class="fas fa-search"></i> {{Choisir les lampes}}</a>
					</legend>
					<div id="div_lampesoirmatinbeLamps"></div>
				</div>
			</div>

			<!-- ========================================= PROGRAMMATION ========================================= -->
			<div role="tabpanel" class="tab-pane" id="scheduletab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<?php
						lampesoirmatinbeSlot('evening', '{{Le soir}}', 'fas fa-moon',
							'{{Le moment où l\'on veut de la lumière en rentrant : le plus souvent un quart d\'heure avant le coucher du soleil.}}');
						?>
					</form>
				</div>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<?php
						lampesoirmatinbeSlot('morning', '{{Le matin}}', 'fas fa-sun',
							'{{Le moment où la lumière ne sert plus : au lever du soleil, ou à heure fixe pour couper après le coucher.}}');
						?>
					</form>
				</div>
			</div>

			<!-- ============================================ COMMANDES ========================================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="alert alert-info" style="margin:5px;">
					{{Ces commandes sont créées et tenues à jour par le plugin. « Prochain changement » se pose sur un tableau de bord, « Allumer » et « Éteindre » agissent sur tout le groupe, et l'« État » retient le dernier ordre envoyé.}}
				</div>
				<table id="table_cmd" class="table table-bordered table-condensed">
					<thead>
						<tr>
							<th>{{Nom}}</th>
							<th>{{Type}}</th>
							<th>{{Options}}</th>
							<th>{{Action}}</th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</div>
	</div>
</div>

<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'lampesoirmatinbe', 'js', 'lampesoirmatinbe'); ?>
