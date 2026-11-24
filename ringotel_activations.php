<?php

//includes
	require_once dirname(__DIR__, 2)."/resources/require.php";
	require_once "resources/check_auth.php";
	require_once __DIR__."/resources/classes/ringotel_sync.php";
	require_once __DIR__."/resources/functions.php";

//check permissions
	if (!permission_exists('ringotel_activation_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set the variables
	$database = new database;
	$domain_uuid = $_SESSION['domain_uuid'];
	$sync = new ringotel_sync($database);
	$sync->source = $_SESSION['username'] ?? 'manual';
	$filter = $_GET['filter'] ?? 'all';
	$search = trim($_GET['search'] ?? '');
	$self = 'ringotel_activations.php?filter='.urlencode($filter).'&search='.urlencode($search);

//process actions
	if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['action'])) {
		ringotel_check_token($self);
		$action = $_POST['action'];
		$selected = array_keys(array_filter($_POST['selected'] ?? []));
		$selected_rt = array_keys(array_filter($_POST['selected_rt'] ?? []));

		switch ($action) {
			case 'sync':
				//a manual sync pushes every extension, so anything edited in the Ringotel portal is corrected too
				if (permission_exists('ringotel_sync')) {
					$result = $sync->sync_domain($domain_uuid, ['force' => true]);
					message::add($result['message'], $result['status'] == 'ok' ? 'positive' : 'negative', 5000);
				}
				break;
			case 'rt_deactivate':
			case 'rt_delete':
				if (permission_exists('ringotel_activation_edit') && !empty($selected_rt)) {
					[$ok, $errors] = $sync->act_on_unlinked($domain_uuid, $selected_rt, $action == 'rt_delete' ? 'delete' : 'deactivate');
					if ($ok > 0) {
						message::add(ringotel_text($action == 'rt_delete' ? 'message-deleted' : 'message-deactivated').": $ok");
					}
					if (!empty($errors)) {
						message::add(implode('; ', $errors), 'negative', 8000);
					}
				}
				break;
			case 'activate':
			case 'deactivate':
				if (permission_exists('ringotel_activation_edit') && !empty($selected)) {
					[$ok, $errors] = $sync->set_activation($domain_uuid, $selected, $action == 'activate');
					if ($ok > 0) {
						message::add(ringotel_text($action == 'activate' ? 'message-activated' : 'message-deactivated').": $ok");
					}
					if (!empty($errors)) {
						message::add(implode('; ', $errors), 'negative', 8000);
					}
				}
				break;
			case 'exempt':
			case 'unexempt':
				if (permission_exists('ringotel_exempt_edit') && !empty($selected)) {
					$count = $sync->set_exempt($domain_uuid, $selected, $action == 'exempt');
					message::add(ringotel_text('message-saved').": $count");
				}
				break;
		}
		header('Location: '.$self);
		exit;
	}

//domain config
	$config = $sync->get_domain_config($domain_uuid);
	$configured = $config && !empty($config['ringotel_org_id']) && !empty($config['ringotel_branch_id']);
	$inactivity_days = (int)(($config['inactivity_days'] ?? null) ?: $sync->setting('inactivity_days', 30));

//the In Ringotel only view reads live from Ringotel
	$unlinked = [];
	$unlinked_error = null;
	if ($filter == 'ringotel_only' && $configured) {
		try {
			$unlinked = $sync->get_unlinked_users($domain_uuid);
			if ($search !== '') {
				$unlinked = array_values(array_filter($unlinked, function ($u) use ($search) {
					return stripos($u['extension'] ?? '', $search) !== false || stripos($u['name'] ?? '', $search) !== false;
				}));
			}
			$config = $sync->get_domain_config($domain_uuid); //picks up the refreshed count
		}
		catch (Exception $e) {
			$unlinked_error = $e->getMessage();
		}
	}
	$unlinked_count = (int)($config['unlinked_count'] ?? 0);

//get the extensions with their Ringotel state
	$sql = "select e.extension_uuid, e.extension, cast(e.enabled as text) as enabled, ";
	$sql .= "coalesce(nullif(e.effective_caller_id_name, ''), nullif(trim(concat(e.directory_first_name, ' ', e.directory_last_name)), ''), e.extension) as fusion_name, ";
	$sql .= "r.ringotel_user_uuid, r.ringotel_user_id, r.ringotel_status, r.ringotel_state, r.deactivated_reason, ";
	$sql .= "cast(r.exempt as text) as exempt, ";
	$sql .= "extract(epoch from r.last_seen) as last_seen_ts, extract(epoch from r.activated_at) as activated_at_ts, ";
	$sql .= "extract(epoch from r.last_synced) as last_synced_ts ";
	$sql .= "from v_extensions e ";
	$sql .= "left join v_ringotel_users r on r.extension_uuid = e.extension_uuid ";
	$sql .= "where e.domain_uuid = :domain_uuid ";
	$parameters = ['domain_uuid' => $domain_uuid];
	if ($search !== '') {
		$sql .= "and (e.extension ilike :search or e.effective_caller_id_name ilike :search2) ";
		$parameters['search'] = '%'.$search.'%';
		$parameters['search2'] = '%'.$search.'%';
	}
	switch ($filter) {
		case 'active':
			$sql .= "and r.ringotel_status = 1 ";
			break;
		case 'inactive':
			$sql .= "and (r.ringotel_status is null or r.ringotel_status <> 1) ";
			break;
		case 'stale':
			$sql .= "and r.ringotel_status = 1 ";
			$sql .= "and greatest(coalesce(r.last_seen, to_timestamp(0)), coalesce(r.activated_at, to_timestamp(0))) < now() - make_interval(days => cast(:days as int)) ";
			$parameters['days'] = $inactivity_days;
			break;
	}
	$sql .= "order by length(e.extension), e.extension ";
	$rows = $filter == 'ringotel_only' ? [] : ($database->select($sql, $parameters, 'all') ?: []);

//activated count for the domain
	$sql = "select count(*) from v_ringotel_users where domain_uuid = :domain_uuid and ringotel_status = 1 ";
	$active_count = (int)$database->select($sql, ['domain_uuid' => $domain_uuid], 'column');

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = ringotel_text('title-ringotel_activations');
	require_once "resources/header.php";

//show the content
	echo "<form id='form_list' method='post' action='".escape($self)."'>\n";
	echo "<input type='hidden' name='".escape($token['name'])."' value='".escape($token['hash'])."'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".ringotel_text('title-ringotel_activations')."</b><div class='count'>".escape($active_count)."</div></div>\n";
	echo "	<div class='actions'>\n";
	if ($configured && permission_exists('ringotel_sync')) {
		echo ringotel_button(ringotel_text('button-sync_now'), 'sync', 'sync-alt');
	}
	if ($configured && permission_exists('ringotel_activation_edit') && $filter == 'ringotel_only') {
		echo ringotel_button(ringotel_text('button-deactivate'), 'rt_deactivate', 'ban', ringotel_text('message-confirm_deactivate'));
		echo ringotel_button(ringotel_text('button-delete'), 'rt_delete', 'trash', ringotel_text('message-confirm_delete'));
	}
	elseif ($configured && permission_exists('ringotel_activation_edit')) {
		echo ringotel_button(ringotel_text('button-activate'), 'activate', 'check', ringotel_text('message-confirm_activate'));
		echo ringotel_button(ringotel_text('button-deactivate'), 'deactivate', 'ban', ringotel_text('message-confirm_deactivate'));
	}
	if ($configured && permission_exists('ringotel_exempt_edit') && $filter != 'ringotel_only') {
		echo ringotel_button(ringotel_text('button-exempt'), 'exempt', 'shield-alt');
		echo ringotel_button(ringotel_text('button-unexempt'), 'unexempt', 'times');
	}
	if (permission_exists('ringotel_log_view')) {
		echo ringotel_link_button(ringotel_text('button-logs'), 'ringotel_logs.php', 'list');
	}
	if (permission_exists('ringotel_domain_view')) {
		echo ringotel_link_button(ringotel_text('button-domains'), 'ringotel_domains.php', 'cog');
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo ringotel_text('description-ringotel_activations')."\n";
	echo "<br /><br />\n";

	if (empty($sync->setting('api_key'))) {
		echo "<div class='alert alert-warning'>".escape(ringotel_text('message-no_api_key'))."</div><br />\n";
	}
	if (!$configured) {
		echo "<div class='alert alert-warning'>".escape(ringotel_text('message-not_configured'));
		if (permission_exists('ringotel_domain_edit')) {
			echo " <a href='ringotel_domain_edit.php?domain_uuid=".escape($domain_uuid)."'>Link this domain</a>";
		}
		echo "</div><br />\n";
	}
	else {
		//sync status line
		echo "<div style='margin-bottom: 15px;'>";
		echo ringotel_text('label-licenses_used').": <strong>".escape($active_count)."</strong>";
		echo " &nbsp;|&nbsp; ".ringotel_text('label-last_sync').": ".escape($config['last_sync'] ? date('Y-m-d H:i', strtotime($config['last_sync'])) : ringotel_text('label-never'));
		if (!empty($config['last_sync_message'])) {
			$color = ($config['last_sync_status'] ?? '') == 'error' ? '#c00' : 'inherit';
			echo " <span style='color: ".$color.";'>(".escape($config['last_sync_message']).")</span>";
		}
		if ($sync->bool_setting('dry_run')) {
			echo " &nbsp;|&nbsp; <strong>Dry run is on. Nothing is being changed in Ringotel.</strong>";
		}
		if ($unlinked_count > 0 && $filter != 'ringotel_only') {
			echo "<br /><a href='ringotel_activations.php?filter=ringotel_only' style='color: #c00;'>".escape(sprintf(ringotel_text('message-unlinked_warning'), $unlinked_count))."</a>";
		}
		echo "<br />Auto deactivate: ";
		if (($config['auto_deactivate_text'] ?? '') == 'true') {
			echo "after ".escape($inactivity_days)." days without app activity";
			echo $sync->bool_setting('inactivity_notify_only', true) ? " (notify only, see Logs)" : "";
		}
		else {
			echo "off";
		}
		echo "</div>\n";
	}

	//filters
	$filters = [
		'all' => ringotel_text('label-filter_all'),
		'active' => ringotel_text('label-filter_active'),
		'inactive' => ringotel_text('label-filter_inactive'),
		'stale' => sprintf(ringotel_text('label-filter_stale'), $inactivity_days),
		'ringotel_only' => ringotel_text('label-filter_ringotel_only').($unlinked_count > 0 ? " ($unlinked_count)" : ""),
	];
	echo "<div style='margin-bottom: 10px;'>";
	foreach ($filters as $key => $label) {
		$style = $filter == $key ? "font-weight: bold; text-decoration: underline;" : "";
		echo "<a href='ringotel_activations.php?filter=".urlencode($key)."&search=".urlencode($search)."' style='margin-right: 15px; ".$style."'>".escape($label)."</a>";
	}
	echo "<span style='float: right;'>";
	echo "<input type='text' class='txt list-search' id='search' value=\"".escape($search)."\" placeholder='Search' ";
	echo "onkeydown=\"if (event.keyCode == 13) { event.preventDefault(); window.location='ringotel_activations.php?filter=".escape(urlencode($filter))."&search='+encodeURIComponent(this.value); }\">";
	echo "</span>";
	echo "</div>\n";

	if ($filter == 'ringotel_only') {
		echo "<p>".escape(ringotel_text('description-ringotel_only'))."</p>\n";
		if ($unlinked_error) {
			echo "<div class='alert alert-warning'>Could not load users from Ringotel: ".escape($unlinked_error)."</div><br />\n";
		}
		echo "<div class='card'>\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th class='checkbox'><input type='checkbox' id='checkbox_all' onclick=\"document.querySelectorAll('.rt_select').forEach(function(c){ c.checked = document.getElementById('checkbox_all').checked; });\"></th>\n";
		echo "	<th>".ringotel_text('label-extension')."</th>\n";
		echo "	<th>".ringotel_text('label-name')."</th>\n";
		echo "	<th>".ringotel_text('label-status')."</th>\n";
		echo "	<th class='hide-sm-dn'>".ringotel_text('label-state')."</th>\n";
		echo "	<th>".ringotel_text('label-last_seen')."</th>\n";
		echo "</tr>\n";
		if (empty($unlinked) && !$unlinked_error) {
			echo "<tr><td colspan='6' style='padding: 15px;'>".escape(ringotel_text('message-no_unlinked'))."</td></tr>\n";
		}
		foreach ($unlinked as $u) {
			$is_active = (int)($u['status'] ?? -1) === 1;
			echo "<tr class='list-row'>\n";
			echo "	<td class='checkbox'><input type='checkbox' class='rt_select' name='selected_rt[".escape($u['id'])."]' value='1'></td>\n";
			echo "	<td>".escape($u['extension'] ?? '')."</td>\n";
			echo "	<td>".escape($u['name'] ?? '')."</td>\n";
			echo "	<td>".($is_active ? "<strong>" : "").escape(ringotel_status_label($u['status'] ?? -1)).($is_active ? "</strong>" : "")."</td>\n";
			echo "	<td class='hide-sm-dn'>".escape(ringotel_state_label($u['state'] ?? null))."</td>\n";
			echo "	<td>".escape(ringotel_ago($u['last_seen_ts']))."</td>\n";
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "</div>\n";
		echo "</form>\n";
		require_once "resources/footer.php";
		exit;
	}

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th class='checkbox'><input type='checkbox' id='checkbox_all' onclick=\"document.querySelectorAll('.rt_select').forEach(function(c){ c.checked = document.getElementById('checkbox_all').checked; });\"></th>\n";
	echo "	<th>".ringotel_text('label-extension')."</th>\n";
	echo "	<th>".ringotel_text('label-name')."</th>\n";
	echo "	<th>".ringotel_text('label-status')."</th>\n";
	echo "	<th class='hide-sm-dn'>".ringotel_text('label-state')."</th>\n";
	echo "	<th>".ringotel_text('label-last_seen')."</th>\n";
	echo "	<th class='hide-sm-dn'>".ringotel_text('label-activated_at')."</th>\n";
	echo "	<th class='center'>".ringotel_text('label-exempt')."</th>\n";
	echo "	<th class='hide-md-dn'>".ringotel_text('label-last_synced')."</th>\n";
	echo "</tr>\n";

	if (empty($rows)) {
		echo "<tr><td colspan='9' style='padding: 15px;'>".escape(ringotel_text('message-no_users'))."</td></tr>\n";
	}
	foreach ($rows as $row) {
		$synced = !empty($row['ringotel_user_uuid']);
		$is_active = $synced && $row['ringotel_status'] !== null && (int)$row['ringotel_status'] === 1;
		$last_seen = $row['last_seen_ts'] ? (int)$row['last_seen_ts'] : null;
		$reference = max((int)$last_seen, (int)$row['activated_at_ts']);
		$stale = $is_active && $reference > 0 && $reference < time() - ($inactivity_days * 86400);

		echo "<tr class='list-row'>\n";
		echo "	<td class='checkbox'>";
		if ($synced) {
			echo "<input type='checkbox' class='rt_select' name='selected[".escape($row['ringotel_user_uuid'])."]' value='1'>";
		}
		echo "</td>\n";
		echo "	<td>".escape($row['extension']).($row['enabled'] !== 'true' ? " <span style='opacity: 0.6;'>(disabled)</span>" : "")."</td>\n";
		echo "	<td>".escape($row['fusion_name'])."</td>\n";
		echo "	<td>".($is_active ? "<strong>" : "").escape(ringotel_status_label($synced ? $row['ringotel_status'] : null)).($is_active ? "</strong>" : "");
		if (!$is_active && !empty($row['deactivated_reason'])) {
			echo " <span style='opacity: 0.6;' title='Last deactivation reason'>(".escape($row['deactivated_reason']).")</span>";
		}
		echo "</td>\n";
		echo "	<td class='hide-sm-dn'>".escape(ringotel_state_label($row['ringotel_state']))."</td>\n";
		echo "	<td".($stale ? " style='color: #c00;' title='Past the inactivity limit'" : "").">";
		echo $synced ? escape(ringotel_ago($last_seen)) : "";
		echo "</td>\n";
		echo "	<td class='hide-sm-dn'>".($row['activated_at_ts'] ? escape(date('Y-m-d', (int)$row['activated_at_ts'])) : "")."</td>\n";
		echo "	<td class='center'>".($row['exempt'] === 'true' ? "Yes" : "")."</td>\n";
		echo "	<td class='hide-md-dn'>".($row['last_synced_ts'] ? escape(date('Y-m-d H:i', (int)$row['last_synced_ts'])) : "")."</td>\n";
		echo "</tr>\n";
	}

	echo "</table>\n";
	echo "</div>\n";
	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
