<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-clock"></i> {{Exécution}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Rattrapage}}</label>
			<div class="col-md-2">
				<input type="number" min="1" max="720" class="configKey form-control" data-l1key="grace_minutes" placeholder="15">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Minutes pendant lesquelles un moment manqué est encore joué — box éteinte, redémarrage, cron en retard. Passé ce délai il est abandonné : allumer les lampes du soir à trois heures du matin ne rend service à personne. 15 minutes conviennent dans la quasi-totalité des cas.}}</span>
			</div>
		</div>
	</fieldset>
	<fieldset>
		<legend><i class="fas fa-map-marker-alt"></i> {{Position}}</legend>
		<div class="form-group">
			<div class="col-md-11 col-md-offset-1">
				<span class="help-block" style="margin:0;">
					<?php
					if (lampesoirmatinbe::hasPosition()) {
						$position = lampesoirmatinbe::position();
						$sun = lampesoirmatinbeSun::sun(time(), $position['latitude'], $position['longitude']);
						echo '{{Position de l\'installation}} : ' . $position['latitude'] . ', ' . $position['longitude'] . '. ';
						echo '{{Aujourd\'hui, lever}} ' . (($sun['sunrise'] === null) ? '--:--' : date('H:i', $sun['sunrise'])) . ', ';
						echo '{{coucher}} ' . (($sun['sunset'] === null) ? '--:--' : date('H:i', $sun['sunset'])) . '.';
					} else {
						echo '<span class="text-danger">{{La position de l\'installation n\'est pas renseignée : les heures de soleil seraient fausses. Réglages → Système → Configuration → Général.}}</span>';
					}
					?>
				</span>
			</div>
		</div>
	</fieldset>
</form>
