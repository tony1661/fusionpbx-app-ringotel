<?php

//includes
	require_once dirname(__DIR__, 2)."/resources/require.php";
	require_once "resources/check_auth.php";
	require_once __DIR__."/resources/classes/ringotel_sync.php";
	require_once __DIR__."/resources/functions.php";

//check permissions
	if (!permission_exists('ringotel_domain_edit')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

	$database = new database;
	$sync = new ringotel_sync($database);
	$sync->source = $_SESSION['username'] ?? 'manual';

//the domain being edited
	$domain_uuid = $_REQUEST['domain_uuid'] ?? '';
	if (!is_uuid($domain_uuid)) {
		header('Location: ringotel_domains.php');
		exit;
	}
	$domain_name = $database->select("select domain_name from v_domains where domain_uuid = :domain_uuid", ['domain_uuid' => $domain_uuid], 'column');
	if (!$domain_name) {
		header('Location: ringotel_domains.php');
		exit;
	}
	$self = 'ringotel_domain_edit.php?domain_uuid='.urlencode($domain_uuid);

//current values
	$config = $sync->get_domain_config($domain_uuid) ?: [];
	$org_id = $_POST['ringotel_org_id'] ?? ($config['ringotel_org_id'] ?? '');
	$branch_id = $_POST['ringotel_branch_id'] ?? ($config['ringotel_branch_id'] ?? '');
	$sync_enabled = $_POST['sync_enabled'] ?? ($config['sync_enabled_text'] ?? 'false');
	$auto_deactivate = $_POST['auto_deactivate'] ?? ($config['auto_deactivate_text'] ?? 'false');
	$inactivity_days = $_POST['inactivity_days'] ?? ($config['inactivity_days'] ?? '');
	$action = $_POST['action'] ?? '';

//load organizations and connections from Ringotel
	$api_error = null;
	$organizations = [];
	$branches = [];
	try {
		$organizations = $sync->api()->get_organizations();
		usort($organizations, function ($a, $b) { return strcasecmp($a['name'] ?? $a['domain'] ?? '', $b['name'] ?? $b['domain'] ?? ''); });
		if ($org_id !== '') {
			$branches = $sync->api()->get_branches($org_id);
		}
	}
	catch (Exception $e) {
		$api_error = $e->getMessage();
	}
	//when the organization changes, the old connection no longer applies
	if ($branch_id !== '' && !empty($branches) && !in_array($branch_id, array_column($branches, 'id'), true)) {
		$branch_id = '';
	}
	//a single connection is selected automatically
	if ($branch_id === '' && count($branches) == 1) {
		$branch_id = $branches[0]['id'];
	}

//save
	if ($_SERVER['REQUEST_METHOD'] == 'POST' && in_array($action, ['save', 'save_sync'])) {
		ringotel_check_token($self);

		$org_domain = $config['ringotel_org_domain'] ?? '';
		foreach ($organizations as $org) {
			if (($org['id'] ?? '') === $org_id) { $org_domain = $org['domain'] ?? ''; }
		}
		$params = [
			'domain_uuid' => $domain_uuid,
			'org_id' => $org_id,
			'org_domain' => $org_domain,
			'branch_id' => $branch_id,
			'sync_enabled' => $sync_enabled === 'true' ? 'true' : 'false',
			'auto_deactivate' => $auto_deactivate === 'true' ? 'true' : 'false',
			'inactivity_days' => ($inactivity_days !== '' && is_numeric($inactivity_days)) ? (int)$inactivity_days : null,
			'user_uuid' => $_SESSION['user_uuid'] ?? null,
		];
		if (!empty($config['ringotel_domain_uuid'])) {
			$sql = "update v_ringotel_domains set ringotel_org_id = :org_id, ringotel_org_domain = :org_domain, ";
			$sql .= "ringotel_branch_id = :branch_id, sync_enabled = :sync_enabled, auto_deactivate = :auto_deactivate, ";
			$sql .= "inactivity_days = :inactivity_days, update_date = now(), update_user = :user_uuid ";
			$sql .= "where domain_uuid = :domain_uuid ";
		}
		else {
			$params['uuid'] = uuid();
			$sql = "insert into v_ringotel_domains (ringotel_domain_uuid, domain_uuid, ringotel_org_id, ringotel_org_domain, ";
			$sql .= "ringotel_branch_id, sync_enabled, auto_deactivate, inactivity_days, insert_date, insert_user) ";
			$sql .= "values (:uuid, :domain_uuid, :org_id, :org_domain, :branch_id, :sync_enabled, :auto_deactivate, :inactivity_days, now(), :user_uuid) ";
		}
		$database->execute($sql, $params);
		$sync->log($domain_uuid, null, 'config', 'success', "Settings saved: org $org_domain, sync ".$params['sync_enabled'].", auto deactivate ".$params['auto_deactivate']);

		//if the domain moved to a different org or connection, old mappings point at the wrong users
		if (!empty($config['ringotel_org_id']) && ($config['ringotel_org_id'] !== $org_id || $config['ringotel_branch_id'] !== $branch_id)) {
			$database->execute("delete from v_ringotel_users where domain_uuid = :domain_uuid", ['domain_uuid' => $domain_uuid]);
			$sync->log($domain_uuid, null, 'config', 'success', 'Organization or connection changed. Existing links were cleared and will be rebuilt on the next sync.');
		}

		message::add(ringotel_text('message-saved'));
		if ($action == 'save_sync' && $params['sync_enabled'] === 'true' && $org_id !== '' && $branch_id !== '') {
			$result = $sync->sync_domain($domain_uuid);
			message::add($result['message'], $result['status'] == 'ok' ? 'positive' : 'negative', 5000);
		}
		header('Location: ringotel_domains.php');
		exit;
	}

	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = ringotel_text('title-ringotel_domain_edit');
	require_once "resources/header.php";

	echo "<form method='post' action='".escape($self)."'>\n";
	echo "<input type='hidden' name='".escape($token['name'])."' value='".escape($token['hash'])."'>\n";
	echo "<input type='hidden' name='domain_uuid' value='".escape($domain_uuid)."'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".ringotel_text('title-ringotel_domain_edit').": ".escape($domain_name)."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo ringotel_link_button(ringotel_text('button-back'), 'ringotel_domains.php', 'arrow-left');
	echo ringotel_button(ringotel_text('button-save'), 'save', 'check');
	echo ringotel_button('Save and Sync', 'save_sync', 'sync-alt');
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	if ($api_error) {
		echo "<div class='alert alert-warning'>Could not load organizations from Ringotel: ".escape($api_error).". You can enter the IDs by hand.</div><br />\n";
	}

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	//organization
	echo "<tr><td width='30%' class='vncellreq' valign='top'>".ringotel_text('label-organization')."</td>";
	echo "<td width='70%' class='vtable'>";
	if (!empty($organizations)) {
		//changing the organization reloads the page with its connections
		echo "<select class='formfld' name='ringotel_org_id' onchange=\"var f = this.form; var a = document.createElement('input'); a.type = 'hidden'; a.name = 'action'; a.value = 'load'; f.appendChild(a); f.submit();\">\n";
		echo "<option value=''></option>\n";
		foreach ($organizations as $org) {
			$label = ($org['name'] ?? '').(!empty($org['domain']) ? " (".$org['domain'].")" : "");
			$selected = ($org['id'] ?? '') === $org_id ? " selected" : "";
			echo "<option value='".escape($org['id'])."'".$selected.">".escape($label)."</option>\n";
		}
		echo "</select>";
	}
	else {
		echo "<input class='formfld' type='text' name='ringotel_org_id' value='".escape($org_id)."'>";
	}
	echo "<br />The Ringotel organization this domain's extensions belong to.</td></tr>\n";

	//connection
	echo "<tr><td class='vncellreq' valign='top'>".ringotel_text('label-connection')."</td>";
	echo "<td class='vtable'>";
	if (!empty($branches)) {
		echo "<select class='formfld' name='ringotel_branch_id'>\n";
		echo "<option value=''></option>\n";
		foreach ($branches as $branch) {
			$label = ($branch['name'] ?? $branch['id']).(!empty($branch['address']) ? " (".$branch['address'].")" : "");
			$selected = ($branch['id'] ?? '') === $branch_id ? " selected" : "";
			echo "<option value='".escape($branch['id'])."'".$selected.">".escape($label)."</option>\n";
		}
		echo "</select>";
	}
	else {
		echo "<input class='formfld' type='text' name='ringotel_branch_id' value='".escape($branch_id)."'>";
	}
	echo "<br />The Ringotel connection that points at this FusionPBX domain.</td></tr>\n";

	//sync enabled
	echo "<tr><td class='vncell' valign='top'>".ringotel_text('label-sync_enabled')."</td>";
	echo "<td class='vtable'><select class='formfld' name='sync_enabled'>";
	echo "<option value='true'".($sync_enabled === 'true' ? " selected" : "").">True</option>";
	echo "<option value='false'".($sync_enabled !== 'true' ? " selected" : "").">False</option>";
	echo "</select><br />Keep this domain's extensions in sync with Ringotel.</td></tr>\n";

	//auto deactivate
	echo "<tr><td class='vncell' valign='top'>".ringotel_text('label-auto_deactivate')."</td>";
	echo "<td class='vtable'><select class='formfld' name='auto_deactivate'>";
	echo "<option value='true'".($auto_deactivate === 'true' ? " selected" : "").">True</option>";
	echo "<option value='false'".($auto_deactivate !== 'true' ? " selected" : "").">False</option>";
	echo "</select><br />Deactivate users who have not used the Ringotel app for the number of days below.";
	if ($sync->bool_setting('inactivity_notify_only', true)) {
		echo " Notify-only mode is on in Default Settings, so users are logged but not deactivated yet.";
	}
	echo "</td></tr>\n";

	//inactivity days
	echo "<tr><td class='vncell' valign='top'>".ringotel_text('label-inactivity_days')."</td>";
	echo "<td class='vtable'><input class='formfld' type='number' min='1' name='inactivity_days' value='".escape($inactivity_days)."' placeholder='".escape($sync->setting('inactivity_days', 30))."'>";
	echo "<br />Leave empty to use the default from Default Settings.</td></tr>\n";

	echo "</table>\n";
	echo "</div>\n";
	echo "</form>\n";

	require_once "resources/footer.php";

?>
