<?php

//small helpers shared by the Ringotel pages

if (!function_exists('ringotel_text')) {
	function ringotel_text($key) {
		global $text;
		return $text[$key] ?? $key;
	}
}

if (!function_exists('ringotel_button')) {
	//submit button that posts an action, matching the FusionPBX button look
	function ringotel_button($label, $action, $icon, $confirm = null, $id = null) {
		$onclick = $confirm ? " onclick=\"return confirm('".addslashes($confirm)."');\"" : '';
		$id_attr = $id ? " id='".escape($id)."'" : '';
		return "<button type='submit' name='action' value='".escape($action)."' class='btn btn-default'".$id_attr.$onclick.">"
			."<span class='fas fa-".escape($icon)." fa-fw'></span><span class='button-label pad'>".escape($label)."</span></button> ";
	}
}

if (!function_exists('ringotel_link_button')) {
	function ringotel_link_button($label, $href, $icon) {
		return "<a href='".escape($href)."' class='btn btn-default'><span class='fas fa-".escape($icon)." fa-fw'></span><span class='button-label pad'>".escape($label)."</span></a> ";
	}
}

if (!function_exists('ringotel_ago')) {
	//"3 days ago" style text for a unix timestamp
	function ringotel_ago($ts) {
		if (empty($ts)) { return ringotel_text('label-never'); }
		$diff = time() - (int)$ts;
		if ($diff < 120) { return 'just now'; }
		if ($diff < 7200) { return floor($diff / 60).' min ago'; }
		if ($diff < 172800) { return floor($diff / 3600).' hours ago'; }
		return floor($diff / 86400).' days ago';
	}
}

if (!function_exists('ringotel_status_label')) {
	function ringotel_status_label($status) {
		if ($status === null || $status === '') { return ringotel_text('label-not_synced'); }
		switch ((int)$status) {
			case 1: return ringotel_text('label-active');
			case 0: return ringotel_text('label-blocked');
			case 2: return ringotel_text('label-attached');
			default: return ringotel_text('label-inactive');
		}
	}
}

if (!function_exists('ringotel_state_label')) {
	//Ringotel presence state
	function ringotel_state_label($state) {
		if ($state === null || $state === '') { return ''; }
		$states = [-1 => '', 0 => 'Offline', 1 => 'Online', 2 => 'Away', 3 => 'Do not disturb', 4 => 'At the desk', 5 => 'On PBX only', 6 => 'Calling', 7 => 'Calling', 8 => 'On a call'];
		return $states[(int)$state] ?? '';
	}
}

if (!function_exists('ringotel_check_token')) {
	function ringotel_check_token($redirect) {
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add(ringotel_text('message-invalid_token'), 'negative');
			header('Location: '.$redirect);
			exit;
		}
	}
}

?>
