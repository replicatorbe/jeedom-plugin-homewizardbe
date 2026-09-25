<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-network-wired"></i> {{Réseau local}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Délai d'attente des requêtes}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="api_timeout" placeholder="3" min="1" max="15">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Secondes avant d'abandonner un appel à un appareil. Sur le réseau local, un HomeWizard répond en quelques dizaines de millisecondes, ou pas du tout : trois secondes suffisent.}}</span>
			</div>
		</div>
	</fieldset>
</form>
