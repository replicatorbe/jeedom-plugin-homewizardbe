<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('homewizardbe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor logoPrimary" id="bt_homewizardbeScan">
				<i class="fas fa-search"></i>
				<br>
				<span>{{Rechercher sur le réseau}}</span>
			</div>
			<div class="cursor logoSecondary" id="bt_homewizardbeAddIp">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter par adresse IP}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>

		<legend><i class="fas fa-list"></i> {{Mes appareils HomeWizard}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucun appareil pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Dans l\'application HomeWizard Energy, activez l\'API locale de chaque appareil : Réglages → Compteurs → l\'appareil → API locale.}}</li>';
			echo '<li>{{Cliquez sur « Rechercher sur le réseau » : le plugin interroge toutes les adresses de votre réseau local et liste les appareils HomeWizard qui répondent. Vous pouvez aussi saisir une adresse IP à la main.}}</li>';
			echo '<li>{{Créez les appareils trouvés. Les commandes apparaissent au premier relevé, selon ce que l\'appareil publie réellement.}}</li>';
			echo '</ol>';
			echo '<span class="help-block" style="margin:8px 0 0 0;">{{Tout se passe sur votre réseau local, sans cloud ni compte. Ce plugin n\'est pas affilié à HomeWizard.}}</span>';
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
			$icon = homewizardbe::iconOf($eqLogic->getConfiguration('product_type', ''));
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="' . $icon . '" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo '<span class="label label-info">' . htmlspecialchars((string) $eqLogic->getConfiguration('product_type', '')) . '</span> ';
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
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#diagtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-stethoscope"></i><span class="hidden-xs"> {{Diagnostic}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================= ÉQUIPEMENT ========================= -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Compteur P1}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											echo '<option value="' . $object->getId() . '">' . $object->getHumanName(true, true) . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
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
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Visible}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-plug"></i> {{Appareil}}</legend>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Adresse IP}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="192.168.0.150">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Réservez-la dans votre routeur : si elle change, relancez la recherche, l'équipement sera mis à jour.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Relever toutes les}}</label>
								<div class="col-sm-5">
									<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="interval">
										<option value="1">{{1 seconde}}</option>
										<option value="2">{{2 secondes}}</option>
										<option value="5">{{5 secondes}}</option>
										<option value="10">{{10 secondes}}</option>
										<option value="30">{{30 secondes}}</option>
										<option value="60">{{1 minute}}</option>
										<option value="300">{{5 minutes}}</option>
									</select>
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Sous la minute, c'est le démon du plugin qui relève. Jeedom n'enregistre une valeur que si elle change, mais une puissance change presque toujours : dix secondes est un bon compromis.}}</span>
								</div>
							</div>

							<div class="form-group homewizardbe-p1">
								<label class="col-sm-3 control-label">{{Tarif capacitaire}}</label>
								<div class="col-sm-9">
									<label class="checkbox-inline">
										<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="capacity" checked>{{lire l'historique des pointes mensuelles}}
									</label>
									<span class="help-block" style="margin:4px 0 0 0;">{{Le compteur belge garde les pointes quart-horaires des treize derniers mois. Le plugin les lit dans le télégramme toutes les quinze minutes et en tire la moyenne sur douze mois qui sert de base au tarif capacitaire (chaque mois comptant pour 2,5 kW au moins).}}</span>
								</div>
							</div>

							<legend><i class="fas fa-key"></i> {{API}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{API utilisée}}</label>
								<div class="col-sm-9">
									<span id="span_homewizardbeApi" class="label label-default">v1</span>
									<a class="btn btn-primary btn-xs" id="bt_homewizardbePair" style="margin-left:8px;"><i class="fas fa-link"></i> {{Associer en v2}}</a>
									<a class="btn btn-default btn-xs" id="bt_homewizardbeUnpair" style="margin-left:4px;display:none;"><i class="fas fa-unlink"></i> {{Revenir en v1}}</a>
									<input type="hidden" class="eqLogicAttr" data-l1key="configuration" data-l2key="token">
									<span class="help-block" style="margin:6px 0 0 0;">{{La v1 suffit pour tout relever et ne demande rien. La v2 ajoute le réglage de la LED, le redémarrage et la stratégie de charge des batteries ; elle est obligatoire pour la batterie HomeWizard. L'association demande d'appuyer sur le bouton de l'appareil. Les commandes restent les mêmes dans les deux cas.}}</span>
								</div>
							</div>

							<legend><i class="fas fa-info-circle"></i> {{Identité}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Modèle}}</label>
								<div class="col-sm-9">
									<span class="eqLogicAttr label label-info" data-l1key="configuration" data-l2key="product_type"></span>
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="product_name" style="margin-left:6px;"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Numéro de série}}</label>
								<div class="col-sm-9">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="serial"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Micrologiciel}}</label>
								<div class="col-sm-9">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="firmware"></span>
									<span class="eqLogicAttr label label-default" data-l1key="configuration" data-l2key="api_version" style="margin-left:6px;"></span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label"></label>
								<div class="col-sm-9">
									<a class="btn btn-default btn-sm" id="bt_homewizardbeRefresh"><i class="fas fa-sync"></i> {{Relever maintenant}}</a>
									<span id="span_homewizardbeStatus" style="margin-left:10px;"></span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================== DIAGNOSTIC ========================= -->
			<div role="tabpanel" class="tab-pane" id="diagtab">
				<br>
				<div class="col-xs-12">
					<div class="alert alert-info" id="div_homewizardbeState">{{Chargement…}}</div>
					<legend><i class="fas fa-code"></i> {{Dernières réponses de l'appareil}}</legend>
					<span class="help-block">{{Le JSON tel que l'appareil l'a renvoyé, avant toute conversion. C'est la pièce à joindre en cas de valeur douteuse.}}</span>
					<pre id="pre_homewizardbeRaw" style="max-height:520px;overflow:auto;"></pre>
				</div>
			</div>

			<!-- ========================== COMMANDES ========================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="col-xs-12">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:300px;">{{Nom}}</th>
								<th style="width:130px;">{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th style="width:160px;">{{Valeur}}</th>
								<th style="width:120px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('desktop', 'homewizardbe', 'js', 'homewizardbe'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
