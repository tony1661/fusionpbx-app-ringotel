<?php

//includes
	require_once dirname(__DIR__, 2)."/resources/require.php";
	require_once "resources/check_auth.php";
	require_once __DIR__."/resources/classes/ringotel_sync.php";
	require_once __DIR__."/resources/functions.php";

//check permissions
	if (!permission_exists('ringotel_domain_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

	$database = new database;

//sync all enabled domains on request
	if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'sync_all' && permission_exists('ringotel_sync')) {
		ringotel_check_token('ringotel_domains.php');
		$sync = new ringotel_sync($database);
		$sync->source = $_SESSION['username'] ?? 'manual';
		$results = $sync->sync_all();
		$errors = count(array_filter($results, function ($r) { return $r['status'] != 'ok'; }));
		message::add(ringotel_text('message-sync_complete').": ".count($results)." domains, $errors with errors", $errors ? 'negative' : 'positive', 5000);
		header('Location: ringotel_domains.php');
		exit;
	}

//get the domains
	$sql = "select d.domain_uuid, d.domain_name, cast(d.domain_enabled as text) as domain_enabled, ";
	$sql .= "r.ringotel_org_domain, r.ringotel_org_id, r.ringotel_branch_id, ";
	$sql .= "cast(r.sync_enabled as text) as sync_enabled, cast(r.auto_deactivate as text) as auto_deactivate, ";
	$sql .= "r.inactivity_days, r.last_sync, r.last_sync_status, r.last_sync_message, r.unlinked_count, ";
	$sql .= "(select count(*) from v_ringotel_users u where u.domain_uuid = d.domain_uuid and u.ringotel_status = 1) as active_count ";
	$sql .= "from v_domains d ";
	$sql .= "left join v_ringotel_domains r on r.domain_uuid = d.domain_uuid ";
	$sql .= "order by d.domain_name ";
	$rows = $database->select($sql, null, 'all') ?: [];

	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

	$sync = new ringotel_sync($database);
	$default_days = (int)$sync->setting('inactivity_days', 30);

//include the header
	$document['title'] = ringotel_text('title-ringotel_domains');
	require_once "resources/header.php";

	echo "<form method='post'>\n";
	echo "<input type='hidden' name='".escape($token['name'])."' value='".escape($token['hash'])."'>\n";
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".ringotel_text('title-ringotel_domains')."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo ringotel_link_button(ringotel_text('button-back'), 'ringotel_activations.php', 'arrow-left');
	if (permission_exists('ringotel_sync')) {
		echo ringotel_button('Sync All', 'sync_all', 'sync-alt');
	}
	if (permission_exists('ringotel_log_view')) {
		echo ringotel_link_button(ringotel_text('button-logs'), 'ringotel_logs.php?all=1', 'list');
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";
	echo "</form>\n";

	echo ringotel_text('description-ringotel_domains')."\n<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".ringotel_text('label-domain')."</th>\n";
	echo "	<th>".ringotel_text('label-organization')."</th>\n";
	echo "	<th class='center'>".ringotel_text('label-sync_enabled')."</th>\n";
	echo "	<th class='center'>".ringotel_text('label-auto_deactivate')."</th>\n";
	echo "	<th class='center'>".ringotel_text('label-active')."</th>\n";
	echo "	<th class='center'>".ringotel_text('label-filter_ringotel_only')."</th>\n";
	echo "	<th>".ringotel_text('label-last_sync')."</th>\n";
	echo "</tr>\n";
	foreach ($rows as $row) {
		$linked = !empty($row['ringotel_org_id']) && !empty($row['ringotel_branch_id']);
		$edit = permission_exists('ringotel_domain_edit') ? "ringotel_domain_edit.php?domain_uuid=".urlencode($row['domain_uuid']) : null;
		echo "<tr class='list-row'".($edit ? " href='".escape($edit)."'" : "").">\n";
		echo "	<td>".($edit ? "<a href='".escape($edit)."'>".escape($row['domain_name'])."</a>" : escape($row['domain_name']));
		if ($row['domain_enabled'] !== 'true') { echo " <span style='opacity: 0.6;'>(disabled)</span>"; }
		echo "</td>\n";
		echo "	<td>".($linked ? escape($row['ringotel_org_domain'] ?: $row['ringotel_org_id']) : "<span style='opacity: 0.6;'>Not linked</span>")."</td>\n";
		echo "	<td class='center'>".($row['sync_enabled'] === 'true' ? "Yes" : "No")."</td>\n";
		echo "	<td class='center'>".($row['auto_deactivate'] === 'true' ? "After ".escape($row['inactivity_days'] ?: $default_days)." days" : "No")."</td>\n";
		echo "	<td class='center'>".escape($row['active_count'])."</td>\n";
		echo "	<td class='center'".((int)$row['unlinked_count'] > 0 ? " style='color: #c00;'" : "").">".($linked ? escape((int)$row['unlinked_count']) : "")."</td>\n";
		echo "	<td>";
		if ($row['last_sync']) {
			$color = $row['last_sync_status'] == 'error' ? '#c00' : 'inherit';
			echo escape(date('Y-m-d H:i', strtotime($row['last_sync'])));
			echo " <span style='color: ".$color.";'>".escape($row['last_sync_message'])."</span>";
		}
		echo "</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";

	require_once "resources/footer.php";

?>
