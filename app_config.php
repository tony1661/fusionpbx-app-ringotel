<?php

	//application details
		$apps[$x]['name'] = "Ringotel";
		$apps[$x]['uuid'] = "60b68e58-1fdc-4cb2-a317-8dfbadb0c2a8";
		$apps[$x]['category'] = "";
		$apps[$x]['subcategory'] = "";
		$apps[$x]['version'] = "1.1.0";
		$apps[$x]['license'] = "Mozilla Public License 2.0";
		$apps[$x]['url'] = "";
		$apps[$x]['description']['en-us'] = "Syncs extensions to Ringotel and manages Ringotel activations.";

	//permissions
		$y = 0;
		$apps[$x]['permissions'][$y]['name'] = "ringotel_activation_view";
		$apps[$x]['permissions'][$y]['menu']['uuid'] = "f0ca5f2e-c49c-4cd6-bda3-9a438da38fab";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$apps[$x]['permissions'][$y]['groups'][] = "admin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "ringotel_activation_edit";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$apps[$x]['permissions'][$y]['groups'][] = "admin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "ringotel_exempt_edit";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "ringotel_sync";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$apps[$x]['permissions'][$y]['groups'][] = "admin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "ringotel_domain_view";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "ringotel_domain_edit";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "ringotel_log_view";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$apps[$x]['permissions'][$y]['groups'][] = "admin";

	//default settings
		$y = 0;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "c084f96e-0635-479a-90a4-90c9eacbfd32";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "ringotel";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "api_url";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "https://shell.ringotel.co/api";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Ringotel Admin API endpoint.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "f3baf5a1-6d7c-46b8-b627-e1e45d93b5dc";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "ringotel";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "api_key";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "API key generated in the Ringotel admin portal (Bearer token).";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "fec18b06-c227-4ebf-ae3b-4911ad537611";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "ringotel";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "sync_interval";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "numeric";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "120";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Seconds between background sync runs.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "9cedb24a-8eec-4fd8-af3f-9668a1006378";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "ringotel";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "inactivity_days";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "numeric";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "30";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Default days without app activity before an activated user is deactivated. Can be overridden per domain.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "64c4eb02-b8a8-4851-b155-d4dad1dcc8b1";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "ringotel";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "inactivity_notify_only";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "boolean";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "When true, inactive users are only logged, not deactivated. Set to false once you trust the results.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "842e8c84-79f8-4695-af9f-81808996ec7a";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "ringotel";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "activation_send_email";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "boolean";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Let Ringotel send its onboarding email when a user with an email address is activated.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "9f541fb8-cf61-4224-917b-66a20f665b78";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "ringotel";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "sync_email";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "boolean";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Send the extension's voicemail email address to Ringotel.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "6c9c102d-2d1f-4e22-96ad-377a844f3ba4";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "ringotel";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "remove_disabled";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "boolean";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "false";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "When true, disabled extensions are deleted from Ringotel like deleted extensions. When false they are left as they are.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "adb1daaf-8935-4290-81ae-821423a34718";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "ringotel";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "max_deletes_per_run";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "numeric";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "10";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Safety limit. If a run would delete more Ringotel users than this in one domain, deletes are skipped and logged.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "f67c2c3a-fdf1-4e37-8c44-31e2cadc5e2c";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "ringotel";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "dry_run";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "boolean";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "false";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "When true, the sync logs what it would do without changing anything in Ringotel.";

	//helper for field definitions
		if (!function_exists('ringotel_field')) {
			function ringotel_field($name, $type, $description = '', $key = null) {
				$types = [
					'uuid' => ['pgsql' => 'uuid', 'sqlite' => 'text', 'mysql' => 'char(36)'],
					'text' => ['pgsql' => 'text', 'sqlite' => 'text', 'mysql' => 'text'],
					'numeric' => ['pgsql' => 'numeric', 'sqlite' => 'numeric', 'mysql' => 'numeric'],
					'boolean' => ['pgsql' => 'boolean', 'sqlite' => 'boolean', 'mysql' => 'boolean'],
					'timestamp' => ['pgsql' => 'timestamptz', 'sqlite' => 'date', 'mysql' => 'timestamp'],
				];
				$field = [];
				$field['name'] = $name;
				$field['type'] = $types[$type];
				if (is_array($key)) { $field['key'] = $key; }
				$field['description']['en-us'] = $description;
				return $field;
			}
		}
		$audit_fields = function () {
			return [
				ringotel_field('insert_date', 'timestamp'),
				ringotel_field('insert_user', 'uuid'),
				ringotel_field('update_date', 'timestamp'),
				ringotel_field('update_user', 'uuid'),
			];
		};
		$domain_fk = ['type' => 'foreign', 'reference' => ['table' => 'v_domains', 'field' => 'domain_uuid']];

	//table: domain to Ringotel organization mapping
		$y = 0;
		$apps[$x]['db'][$y]['table']['name'] = "v_ringotel_domains";
		$apps[$x]['db'][$y]['table']['parent'] = "";
		$apps[$x]['db'][$y]['fields'] = array_merge([
			ringotel_field('ringotel_domain_uuid', 'uuid', '', ['type' => 'primary']),
			ringotel_field('domain_uuid', 'uuid', 'FusionPBX domain.', $domain_fk),
			ringotel_field('ringotel_org_id', 'text', 'Ringotel organization ID.'),
			ringotel_field('ringotel_org_domain', 'text', 'Ringotel organization domain.'),
			ringotel_field('ringotel_branch_id', 'text', 'Ringotel connection (branch) ID.'),
			ringotel_field('sync_enabled', 'boolean', 'Sync this domain.'),
			ringotel_field('auto_deactivate', 'boolean', 'Deactivate users with no app activity.'),
			ringotel_field('inactivity_days', 'numeric', 'Override for the default inactivity days.'),
			ringotel_field('last_sync', 'timestamp', 'Last sync run.'),
			ringotel_field('last_sync_status', 'text', 'ok or error.'),
			ringotel_field('last_sync_message', 'text', 'Summary of the last sync run.'),
			ringotel_field('unlinked_count', 'numeric', 'Ringotel users in the connection not linked to an extension.'),
		], $audit_fields());

	//table: extension to Ringotel user mapping
		$y++;
		$apps[$x]['db'][$y]['table']['name'] = "v_ringotel_users";
		$apps[$x]['db'][$y]['table']['parent'] = "";
		$apps[$x]['db'][$y]['fields'] = array_merge([
			ringotel_field('ringotel_user_uuid', 'uuid', '', ['type' => 'primary']),
			ringotel_field('domain_uuid', 'uuid', 'FusionPBX domain.', $domain_fk),
			ringotel_field('extension_uuid', 'uuid', 'FusionPBX extension. Not a foreign key so deleted extensions can be detected.'),
			ringotel_field('extension', 'text', 'Extension number at last sync.'),
			ringotel_field('name', 'text', 'Name at last sync.'),
			ringotel_field('ringotel_user_id', 'text', 'Ringotel user ID.'),
			ringotel_field('ringotel_status', 'numeric', '-1 unactivated, 0 blocked, 1 activated, 2 attached.'),
			ringotel_field('ringotel_state', 'numeric', 'Ringotel presence state.'),
			ringotel_field('sync_hash', 'text', 'Hash of synced fields.'),
			ringotel_field('last_seen', 'timestamp', 'Most recent app device activity.'),
			ringotel_field('activated_at', 'timestamp', 'When the user was first seen activated.'),
			ringotel_field('exempt', 'boolean', 'Never auto-deactivate.'),
			ringotel_field('deactivated_reason', 'text', 'Why the user was last deactivated.'),
			ringotel_field('inactivity_notified_at', 'timestamp', 'Last notify-only log for inactivity.'),
			ringotel_field('last_synced', 'timestamp', 'Last successful push to Ringotel.'),
		], $audit_fields());

	//table: sync log
		$y++;
		$apps[$x]['db'][$y]['table']['name'] = "v_ringotel_logs";
		$apps[$x]['db'][$y]['table']['parent'] = "";
		$apps[$x]['db'][$y]['fields'] = array_merge([
			ringotel_field('ringotel_log_uuid', 'uuid', '', ['type' => 'primary']),
			ringotel_field('domain_uuid', 'uuid', 'FusionPBX domain.'),
			ringotel_field('extension', 'text', 'Extension number.'),
			ringotel_field('action', 'text', 'create, update, delete, activate, deactivate, adopt, inactive, error.'),
			ringotel_field('result', 'text', 'success, error, dry_run, skipped.'),
			ringotel_field('message', 'text', 'Details.'),
			ringotel_field('source', 'text', 'service, manual, or the username that made the change.'),
		], $audit_fields());

?>
