<?php

//includes
	require_once dirname(__DIR__, 2)."/resources/require.php";
	require_once "resources/check_auth.php";
	require_once __DIR__."/resources/functions.php";

//check permissions
	if (!permission_exists('ringotel_log_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

	$database = new database;

//filters
	$show_all = !empty($_GET['all']) && permission_exists('ringotel_domain_view');
	$result_filter = $_GET['result'] ?? '';
	$search = trim($_GET['search'] ?? '');
	$page = max(0, (int)($_GET['page'] ?? 0));
	$per_page = 100;

	$sql = "select l.*, d.domain_name from v_ringotel_logs l ";
	$sql .= "left join v_domains d on d.domain_uuid = l.domain_uuid ";
	$sql .= "where true ";
	$parameters = [];
	if (!$show_all) {
		$sql .= "and l.domain_uuid = :domain_uuid ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	}
	if (in_array($result_filter, ['success', 'error', 'dry_run', 'skipped'])) {
		$sql .= "and l.result = :result ";
		$parameters['result'] = $result_filter;
	}
	if ($search !== '') {
		$sql .= "and (l.extension ilike :search or l.message ilike :search2) ";
		$parameters['search'] = '%'.$search.'%';
		$parameters['search2'] = '%'.$search.'%';
	}
	$sql .= "order by l.insert_date desc ";
	$sql .= "limit ".($per_page + 1)." offset ".($page * $per_page)." ";
	$rows = $database->select($sql, $parameters, 'all') ?: [];
	$has_more = count($rows) > $per_page;
	$rows = array_slice($rows, 0, $per_page);

	$base = 'ringotel_logs.php?'.($show_all ? 'all=1&' : '').'result='.urlencode($result_filter).'&search='.urlencode($search);

//include the header
	$document['title'] = ringotel_text('title-ringotel_logs');
	require_once "resources/header.php";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".ringotel_text('title-ringotel_logs')."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo ringotel_link_button(ringotel_text('button-back'), $show_all ? 'ringotel_domains.php' : 'ringotel_activations.php', 'arrow-left');
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo ringotel_text('description-ringotel_logs')."\n<br /><br />\n";

	echo "<div style='margin-bottom: 10px;'>";
	foreach (['' => 'All', 'success' => 'Success', 'error' => 'Errors', 'skipped' => 'Skipped', 'dry_run' => 'Dry run'] as $key => $label) {
		$style = $result_filter === $key ? "font-weight: bold; text-decoration: underline;" : "";
		echo "<a href='ringotel_logs.php?".($show_all ? 'all=1&' : '')."result=".urlencode($key)."&search=".urlencode($search)."' style='margin-right: 15px; ".$style."'>".escape($label)."</a>";
	}
	echo "<span style='float: right;'>";
	echo "<input type='text' class='txt list-search' value=\"".escape($search)."\" placeholder='Search' ";
	echo "onkeydown=\"if (event.keyCode == 13) { window.location='ringotel_logs.php?".($show_all ? 'all=1&' : '')."result=".escape(urlencode($result_filter))."&search='+encodeURIComponent(this.value); }\">";
	echo "</span>";
	echo "</div>\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".ringotel_text('label-date')."</th>\n";
	if ($show_all) { echo "	<th>".ringotel_text('label-domain')."</th>\n"; }
	echo "	<th>".ringotel_text('label-extension')."</th>\n";
	echo "	<th>".ringotel_text('label-action')."</th>\n";
	echo "	<th>".ringotel_text('label-result')."</th>\n";
	echo "	<th>".ringotel_text('label-message')."</th>\n";
	echo "	<th class='hide-sm-dn'>".ringotel_text('label-source')."</th>\n";
	echo "</tr>\n";
	foreach ($rows as $row) {
		$color = $row['result'] == 'error' ? " style='color: #c00;'" : "";
		echo "<tr class='list-row'>\n";
		echo "	<td class='no-wrap'>".escape(date('Y-m-d H:i:s', strtotime($row['insert_date'])))."</td>\n";
		if ($show_all) { echo "	<td>".escape($row['domain_name'])."</td>\n"; }
		echo "	<td>".escape($row['extension'])."</td>\n";
		echo "	<td>".escape($row['action'])."</td>\n";
		echo "	<td".$color.">".escape($row['result'])."</td>\n";
		echo "	<td".$color.">".escape($row['message'])."</td>\n";
		echo "	<td class='hide-sm-dn'>".escape($row['source'])."</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";

	echo "<div style='margin-top: 10px;'>";
	if ($page > 0) { echo "<a href='".escape($base.'&page='.($page - 1))."'>Newer</a> &nbsp; "; }
	if ($has_more) { echo "<a href='".escape($base.'&page='.($page + 1))."'>Older</a>"; }
	echo "</div>\n";

	require_once "resources/footer.php";

?>
