<?php

require_once __DIR__.'/ringotel_api.php';

if (!function_exists('ringotel_clip')) {
	function ringotel_clip($value, $start, $length) {
		return function_exists('mb_substr') ? mb_substr($value, $start, $length) : substr($value, $start, $length);
	}
}

/**
 * Sync engine. FusionPBX owns extension data, Ringotel owns activation.
 *
 * Rules:
 *  - Extensions are matched by extension_uuid, so renumbering updates the same Ringotel user.
 *  - New extensions are created in Ringotel as unactivated (directory only, no license).
 *  - Updates never change activation status.
 *  - Deleted extensions are deleted from Ringotel, activated or not.
 *  - On first sync, existing Ringotel users with the same extension number are adopted.
 *  - Ringotel users the app never created or adopted are never touched.
 *  - Activated users with no app activity for X days are deactivated (or logged in notify-only mode).
 */
if (!class_exists('ringotel_sync')) {
class ringotel_sync {

	private $db;
	private $api;
	private $settings = [];
	private $lock_handles = [];
	private $retired_ids = []; //Ringotel IDs deleted or replaced during the current run
	public $source = 'service';
	public $output = null; //optional callable for CLI progress output

	public function __construct($database = null) {
		$this->db = $database ?: new database;
		$this->load_settings();
		$this->api = new ringotel_api($this->setting('api_url'), $this->setting('api_key'));
	}

	//settings

	private function load_settings() {
		$sql = "select default_setting_subcategory as name, default_setting_value as value ";
		$sql .= "from v_default_settings ";
		$sql .= "where default_setting_category = 'ringotel' ";
		$sql .= "and cast(default_setting_enabled as text) = 'true' ";
		$rows = $this->db->select($sql, null, 'all') ?: [];
		foreach ($rows as $row) {
			$this->settings[$row['name']] = $row['value'];
		}
	}

	public function setting($name, $default = null) {
		$value = $this->settings[$name] ?? null;
		if ($value === null || $value === '') { return $default; }
		return $value;
	}

	public function bool_setting($name, $default = false) {
		$value = $this->setting($name, $default ? 'true' : 'false');
		return in_array(strtolower((string)$value), ['true', '1', 'yes', 'on'], true);
	}

	public function api() {
		return $this->api;
	}

	//database helpers

	private function query($sql, $params = null) {
		if (method_exists($this->db, 'execute')) {
			return $this->db->execute($sql, $params);
		}
		return $this->db->select($sql, $params, 'all');
	}

	private function rows($sql, $params = null) {
		$rows = $this->db->select($sql, $params, 'all');
		return is_array($rows) ? $rows : [];
	}

	private function row($sql, $params = null) {
		$row = $this->db->select($sql, $params, 'row');
		return is_array($row) ? $row : null;
	}

	private function user_uuid() {
		return $_SESSION['user_uuid'] ?? null;
	}

	//logging

	public function log($domain_uuid, $extension, $action, $result, $message = '') {
		$sql = "insert into v_ringotel_logs (ringotel_log_uuid, domain_uuid, extension, action, result, message, source, insert_date, insert_user) ";
		$sql .= "values (:uuid, :domain_uuid, :extension, :action, :result, :message, :source, now(), :insert_user) ";
		$this->query($sql, [
			'uuid' => uuid(),
			'domain_uuid' => $domain_uuid,
			'extension' => $extension,
			'action' => $action,
			'result' => $result,
			'message' => ringotel_clip((string)$message, 0, 2000),
			'source' => $this->source,
			'insert_user' => $this->user_uuid(),
		]);
		$this->out("[$action/$result] ".($extension !== null ? "$extension: " : '').$message);
	}

	private function out($line) {
		if (is_callable($this->output)) { call_user_func($this->output, $line); }
	}

	public function prune_logs($days = 90) {
		$this->query("delete from v_ringotel_logs where insert_date < now() - make_interval(days => cast(:days as int))", ['days' => (int)$days]);
	}

	//locking so the service and the web page never sync the same domain at once

	private function lock($domain_uuid, $wait_seconds = 0) {
		$path = sys_get_temp_dir().'/ringotel_sync_'.preg_replace('/[^a-f0-9\-]/i', '', $domain_uuid).'.lock';
		$handle = fopen($path, 'c');
		if (!$handle) { return false; }
		$deadline = time() + $wait_seconds;
		do {
			if (flock($handle, LOCK_EX | LOCK_NB)) {
				$this->lock_handles[$domain_uuid] = $handle;
				return true;
			}
			if (time() >= $deadline) { break; }
			usleep(250000);
		} while (true);
		fclose($handle);
		return false;
	}

	private function unlock($domain_uuid) {
		if (isset($this->lock_handles[$domain_uuid])) {
			flock($this->lock_handles[$domain_uuid], LOCK_UN);
			fclose($this->lock_handles[$domain_uuid]);
			unset($this->lock_handles[$domain_uuid]);
		}
	}

	//domain config

	public function get_domain_config($domain_uuid) {
		$sql = "select r.*, d.domain_name, ";
		$sql .= "cast(r.sync_enabled as text) as sync_enabled_text, cast(r.auto_deactivate as text) as auto_deactivate_text ";
		$sql .= "from v_ringotel_domains r join v_domains d on d.domain_uuid = r.domain_uuid ";
		$sql .= "where r.domain_uuid = :domain_uuid ";
		return $this->row($sql, ['domain_uuid' => $domain_uuid]);
	}

	public function enabled_domains() {
		$sql = "select r.domain_uuid from v_ringotel_domains r ";
		$sql .= "join v_domains d on d.domain_uuid = r.domain_uuid ";
		$sql .= "where cast(r.sync_enabled as text) = 'true' ";
		$sql .= "and cast(d.domain_enabled as text) = 'true' ";
		$sql .= "and coalesce(r.ringotel_org_id, '') <> '' and coalesce(r.ringotel_branch_id, '') <> '' ";
		return array_column($this->rows($sql), 'domain_uuid');
	}

	private function finish_domain($domain_uuid, $status, $message) {
		$sql = "update v_ringotel_domains set last_sync = now(), last_sync_status = :status, last_sync_message = :message ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$this->query($sql, ['status' => $status, 'message' => ringotel_clip($message, 0, 2000), 'domain_uuid' => $domain_uuid]);
	}

	//FusionPBX side

	private function fusion_extensions($domain_uuid) {
		$sql = "select e.extension_uuid, e.extension, e.effective_caller_id_name, ";
		$sql .= "e.directory_first_name, e.directory_last_name, e.password, ";
		$sql .= "cast(e.enabled as text) as enabled, v.voicemail_mail_to ";
		$sql .= "from v_extensions e ";
		$sql .= "left join v_voicemails v on v.domain_uuid = e.domain_uuid and v.voicemail_id = e.extension ";
		$sql .= "where e.domain_uuid = :domain_uuid ";
		$rows = $this->rows($sql, ['domain_uuid' => $domain_uuid]);
		$extensions = [];
		foreach ($rows as $row) {
			$name = trim((string)$row['effective_caller_id_name']);
			if ($name === '') { $name = trim($row['directory_first_name'].' '.$row['directory_last_name']); }
			if ($name === '') { $name = $row['extension']; }
			$email = trim((string)$row['voicemail_mail_to']);
			//voicemail can hold several addresses, Ringotel takes one
			if (strpos($email, ',') !== false) { $email = trim(explode(',', $email)[0]); }
			$extensions[$row['extension_uuid']] = [
				'extension_uuid' => $row['extension_uuid'],
				'extension' => $row['extension'],
				'name' => $name,
				'password' => $row['password'],
				'email' => $this->bool_setting('sync_email', true) ? $email : '',
				'enabled' => $row['enabled'] === 'true',
			];
		}
		return $extensions;
	}

	private function hash_extension(array $ext) {
		return md5(json_encode([$ext['extension'], $ext['name'], $ext['password'], $ext['email']]));
	}

	private function ringotel_fields(array $ext) {
		$fields = [
			'name' => $ext['name'],
			'extension' => $ext['extension'],
			'username' => $ext['extension'],
			'authname' => $ext['extension'],
			'password' => $ext['password'],
		];
		if ($ext['email'] !== '') { $fields['email'] = $ext['email']; }
		return $fields;
	}

	private function mappings($domain_uuid) {
		$sql = "select *, cast(exempt as text) as exempt_text, ";
		$sql .= "extract(epoch from last_seen) as last_seen_ts, extract(epoch from activated_at) as activated_at_ts, ";
		$sql .= "extract(epoch from inactivity_notified_at) as notified_ts ";
		$sql .= "from v_ringotel_users where domain_uuid = :domain_uuid ";
		$rows = $this->rows($sql, ['domain_uuid' => $domain_uuid]);
		$out = [];
		foreach ($rows as $row) { $out[$row['extension_uuid']] = $row; }
		return $out;
	}

	private function save_mapping($domain_uuid, array $ext, array $rt_user, $hash, $existing_uuid = null) {
		$status = isset($rt_user['status']) ? (int)$rt_user['status'] : null;
		$state = isset($rt_user['state']) ? (int)$rt_user['state'] : null;
		$last_seen = ringotel_api::last_seen($rt_user);
		if ($existing_uuid) {
			$sql = "update v_ringotel_users set extension = :extension, name = :name, ringotel_user_id = :rt_id, ";
			$sql .= "ringotel_status = coalesce(:status, ringotel_status), ringotel_state = :state, ";
			$sql .= "sync_hash = coalesce(:hash, sync_hash), ";
			$sql .= "last_seen = greatest(last_seen, to_timestamp(:last_seen)), ";
			$sql .= "activated_at = case when :status_int = 1 then coalesce(activated_at, now()) else null end, ";
			$sql .= "last_synced = case when cast(:hash2 as text) is not null then now() else last_synced end, ";
			$sql .= "update_date = now() ";
			$sql .= "where ringotel_user_uuid = :uuid ";
			$this->query($sql, [
				'extension' => $ext['extension'], 'name' => $ext['name'], 'rt_id' => $rt_user['id'],
				'status' => $status, 'status_int' => $status, 'state' => $state, 'hash' => $hash, 'hash2' => $hash,
				'last_seen' => $last_seen, 'uuid' => $existing_uuid,
			]);
			return $existing_uuid;
		}
		$uuid = uuid();
		$sql = "insert into v_ringotel_users (ringotel_user_uuid, domain_uuid, extension_uuid, extension, name, ";
		$sql .= "ringotel_user_id, ringotel_status, ringotel_state, sync_hash, last_seen, activated_at, exempt, last_synced, insert_date, insert_user) ";
		$sql .= "values (:uuid, :domain_uuid, :extension_uuid, :extension, :name, :rt_id, :status, :state, :hash, ";
		$sql .= "to_timestamp(:last_seen), case when :status_int = 1 then now() else null end, false, now(), now(), :insert_user) ";
		$this->query($sql, [
			'uuid' => $uuid, 'domain_uuid' => $domain_uuid, 'extension_uuid' => $ext['extension_uuid'],
			'extension' => $ext['extension'], 'name' => $ext['name'], 'rt_id' => $rt_user['id'],
			'status' => $status, 'status_int' => $status, 'state' => $state, 'hash' => $hash,
			'last_seen' => $last_seen, 'insert_user' => $this->user_uuid(),
		]);
		return $uuid;
	}

	//main sync

	public function sync_all(array $options = []) {
		$results = [];
		foreach ($this->enabled_domains() as $domain_uuid) {
			$results[$domain_uuid] = $this->sync_domain($domain_uuid, $options);
		}
		return $results;
	}

	/**
	 * Sync one domain. Options: force (ignore hashes and push every extension).
	 * Returns an array of counts plus 'status' and 'message'.
	 */
	public function sync_domain($domain_uuid, array $options = []) {
		$force = !empty($options['force']);
		$dry = $this->bool_setting('dry_run', false);
		$this->retired_ids = [];
		$counts = ['created' => 0, 'updated' => 0, 'adopted' => 0, 'deleted' => 0, 'deactivated' => 0, 'errors' => 0];

		$config = $this->get_domain_config($domain_uuid);
		if (!$config || empty($config['ringotel_org_id']) || empty($config['ringotel_branch_id'])) {
			return ['status' => 'error', 'message' => 'Domain is not linked to a Ringotel organization'] + $counts;
		}
		if (!$this->lock($domain_uuid, 0)) {
			return ['status' => 'busy', 'message' => 'A sync is already running for this domain'] + $counts;
		}

		try {
			$orgid = $config['ringotel_org_id'];
			$branchid = $config['ringotel_branch_id'];
			$this->out("Syncing {$config['domain_name']}".($dry ? ' (dry run)' : ''));

			//load both sides
			$extensions = $this->fusion_extensions($domain_uuid);
			$mappings = $this->mappings($domain_uuid);
			$rt_users = $this->api->get_users($orgid, $branchid);

			$rt_by_id = [];
			$rt_by_ext = [];
			foreach ($rt_users as $u) {
				if (!isset($u['id'])) { continue; }
				if (isset($u['status']) && (int)$u['status'] == ringotel_api::STATUS_PARKING) { continue; }
				$rt_by_id[$u['id']] = $u;
				$key = (string)($u['extension'] ?? '');
				if ($key === '') { continue; }
				//prefer the activated user if an extension appears more than once
				if (!isset($rt_by_ext[$key]) || (int)($u['status'] ?? -1) == ringotel_api::STATUS_ACTIVATED) {
					$rt_by_ext[$key] = $u;
				}
			}
			$claimed = []; //ringotel ids already linked to an extension in this run
			foreach ($mappings as $m) {
				if (isset($extensions[$m['extension_uuid']]) && !empty($m['ringotel_user_id'])) {
					$claimed[$m['ringotel_user_id']] = true;
				}
			}

			$remove_disabled = $this->bool_setting('remove_disabled', false);

			//creates and updates
			foreach ($extensions as $ext_uuid => $ext) {
				if (!$ext['enabled'] && $remove_disabled) { continue; } //handled as a delete below
				$hash = $this->hash_extension($ext);
				$map = $mappings[$ext_uuid] ?? null;
				$force_this = false;

				try {
					//find the linked Ringotel user
					$rt_user = null;
					if ($map && !empty($map['ringotel_user_id']) && isset($rt_by_id[$map['ringotel_user_id']])) {
						$rt_user = $rt_by_id[$map['ringotel_user_id']];
					}

					//mapped user is gone in Ringotel (deleted or deactivated in the portal): relink by number
					if ($map && !$rt_user) {
						$candidate = $rt_by_ext[$ext['extension']] ?? $rt_by_ext[$map['extension']] ?? null;
						if ($candidate && !isset($claimed[$candidate['id']])) {
							$rt_user = $candidate;
							$claimed[$candidate['id']] = true;
							$force_this = true;
							$this->log($domain_uuid, $ext['extension'], 'relink', 'success', "Linked to Ringotel user {$candidate['id']}");
						}
					}

					//no mapping yet: adopt an existing Ringotel user with the same number
					if (!$map) {
						$candidate = $rt_by_ext[$ext['extension']] ?? null;
						if ($candidate && !isset($claimed[$candidate['id']])) {
							$rt_user = $candidate;
							$claimed[$candidate['id']] = true;
							if (!$dry) {
								$map_uuid = $this->save_mapping($domain_uuid, $ext, $rt_user, null);
								$map = ['ringotel_user_uuid' => $map_uuid, 'sync_hash' => null, 'extension' => $ext['extension']];
							}
							$counts['adopted']++;
							$this->log($domain_uuid, $ext['extension'], 'adopt', $dry ? 'dry_run' : 'success', "Adopted existing Ringotel user {$candidate['id']}");
						}
					}

					//still nothing: create as unactivated
					if (!$rt_user) {
						$fields = $this->ringotel_fields($ext) + [
							'orgid' => $orgid,
							'branchid' => $branchid,
							'status' => 0,
						];
						if (!empty($config['ringotel_org_domain'])) { $fields['domain'] = $config['ringotel_org_domain']; }
						if ($dry) {
							$this->log($domain_uuid, $ext['extension'], 'create', 'dry_run', "Would create {$ext['name']}");
						}
						else {
							$created = $this->api->create_user($fields);
							if (empty($created['id'])) { throw new Exception('createUser returned no user ID'); }
							$created['status'] = $created['status'] ?? ringotel_api::STATUS_UNACTIVATED;
							$this->save_mapping($domain_uuid, $ext, $created, $hash, $map['ringotel_user_uuid'] ?? null);
							$claimed[$created['id']] = true;
							$this->log($domain_uuid, $ext['extension'], 'create', 'success', "Created {$ext['name']} (directory only)");
						}
						$counts['created']++;
						continue;
					}

					//update when something changed
					$changed = $force || $force_this || !$map || ($map['sync_hash'] ?? null) !== $hash;
					if ($changed) {
						if ($dry) {
							$this->log($domain_uuid, $ext['extension'], 'update', 'dry_run', "Would update {$ext['name']}");
						}
						else {
							$this->api->update_user($orgid, $rt_user['id'], $this->ringotel_fields($ext));
							$rt_user['name'] = $ext['name'];
							$this->save_mapping($domain_uuid, $ext, $rt_user, $hash, $map['ringotel_user_uuid'] ?? null);
							$detail = ($map && isset($map['extension']) && $map['extension'] !== $ext['extension'])
								? "Updated {$ext['name']} (renumbered from {$map['extension']})"
								: "Updated {$ext['name']}";
							$this->log($domain_uuid, $ext['extension'], 'update', 'success', $detail);
						}
						$counts['updated']++;
					}
					elseif (!$dry && $map) {
						//nothing to push, refresh status and last seen
						$this->save_mapping($domain_uuid, $ext, $rt_user, null, $map['ringotel_user_uuid']);
					}
				}
				catch (Exception $e) {
					$counts['errors']++;
					$this->log($domain_uuid, $ext['extension'], 'error', 'error', $e->getMessage());
				}
			}

			//deletes: mapped extensions that no longer exist (or are disabled when remove_disabled is on)
			$to_delete = [];
			foreach ($mappings as $ext_uuid => $m) {
				$gone = !isset($extensions[$ext_uuid]);
				$disabled = !$gone && $remove_disabled && !$extensions[$ext_uuid]['enabled'];
				if ($gone || $disabled) { $to_delete[] = $m + ['reason' => $gone ? 'deleted' : 'disabled']; }
			}
			$max_deletes = (int)$this->setting('max_deletes_per_run', 10);
			if (count($to_delete) > 0 && count($extensions) == 0) {
				$this->log($domain_uuid, null, 'delete', 'skipped', 'No extensions were found in FusionPBX for this domain, so no Ringotel users were deleted. Check the domain before the next run.');
			}
			elseif ($max_deletes > 0 && count($to_delete) > $max_deletes) {
				$this->log($domain_uuid, null, 'delete', 'skipped', count($to_delete)." deletes exceed the limit of $max_deletes per run. Nothing was deleted. Raise ringotel > max_deletes_per_run if this is expected.");
			}
			else {
				foreach ($to_delete as $m) {
					try {
						$was = ((int)$m['ringotel_status'] == ringotel_api::STATUS_ACTIVATED) ? 'activated' : 'directory only';
						if ($dry) {
							$this->log($domain_uuid, $m['extension'], 'delete', 'dry_run', "Would delete {$m['name']} ($was), extension {$m['reason']}");
							$counts['deleted']++;
							continue;
						}
						if (!empty($m['ringotel_user_id']) && isset($rt_by_id[$m['ringotel_user_id']])) {
							$this->api->delete_user($orgid, $m['ringotel_user_id']);
							$this->retired_ids[$m['ringotel_user_id']] = true;
						}
						$this->query("delete from v_ringotel_users where ringotel_user_uuid = :uuid", ['uuid' => $m['ringotel_user_uuid']]);
						$this->log($domain_uuid, $m['extension'], 'delete', 'success', "Deleted {$m['name']} ($was), extension {$m['reason']}");
						$counts['deleted']++;
					}
					catch (Exception $e) {
						$counts['errors']++;
						$this->log($domain_uuid, $m['extension'], 'error', 'error', 'Delete failed: '.$e->getMessage());
					}
				}
			}

			//inactivity check
			$counts['deactivated'] = $this->check_inactivity($domain_uuid, $config, $dry);

			//Ringotel users in this connection that are not linked to any extension
			$unlinked = 0;
			if (!$dry) {
				$linked_ids = array_flip(array_filter(array_column($this->mappings($domain_uuid), 'ringotel_user_id')));
				foreach ($rt_by_id as $id => $u) {
					if (!isset($linked_ids[$id]) && !isset($this->retired_ids[$id])) { $unlinked++; }
				}
				$this->set_unlinked_count($domain_uuid, $unlinked);
			}

			$message = "{$counts['created']} created, {$counts['updated']} updated, {$counts['adopted']} adopted, {$counts['deleted']} deleted, {$counts['deactivated']} deactivated, {$counts['errors']} errors";
			if ($dry) { $message .= ' (dry run)'; }
			$status = $counts['errors'] > 0 ? 'error' : 'ok';
			$this->finish_domain($domain_uuid, $status, $message);
			return ['status' => $status, 'message' => $message] + $counts;
		}
		catch (Throwable $e) {
			$this->log($domain_uuid, null, 'error', 'error', $e->getMessage());
			$this->finish_domain($domain_uuid, 'error', $e->getMessage());
			return ['status' => 'error', 'message' => $e->getMessage()] + $counts;
		}
		finally {
			$this->unlock($domain_uuid);
		}
	}

	//inactivity

	private function check_inactivity($domain_uuid, array $config, $dry) {
		if (($config['auto_deactivate_text'] ?? 'false') !== 'true') { return 0; }
		$days = (int)($config['inactivity_days'] ?: $this->setting('inactivity_days', 30));
		if ($days < 1) { return 0; }
		$notify_only = $this->bool_setting('inactivity_notify_only', true);
		$cutoff = time() - ($days * 86400);
		$count = 0;

		foreach ($this->mappings($domain_uuid) as $m) {
			if ((int)$m['ringotel_status'] !== ringotel_api::STATUS_ACTIVATED) { continue; }
			if ($m['exempt_text'] === 'true') { continue; }
			//the clock starts at activation, so users who never signed in are covered too
			$reference = max((int)$m['last_seen_ts'], (int)$m['activated_at_ts']);
			if ($reference == 0 || $reference > $cutoff) { continue; }
			$idle_days = (int)floor((time() - $reference) / 86400);

			if ($notify_only || $dry) {
				//log at most once a day per user
				if ((int)$m['notified_ts'] > time() - 86400) { continue; }
				$this->log($domain_uuid, $m['extension'], 'inactive', $dry ? 'dry_run' : 'skipped', "{$m['name']} has had no app activity for $idle_days days and would be deactivated (notify-only mode)");
				$this->query("update v_ringotel_users set inactivity_notified_at = now() where ringotel_user_uuid = :uuid", ['uuid' => $m['ringotel_user_uuid']]);
				continue;
			}

			try {
				$this->deactivate_mapping($domain_uuid, $config, $m, "inactive $idle_days days");
				$count++;
			}
			catch (Exception $e) {
				$this->log($domain_uuid, $m['extension'], 'error', 'error', 'Auto deactivate failed: '.$e->getMessage());
			}
		}
		return $count;
	}

	//activation actions used by the activations page

	private function deactivate_mapping($domain_uuid, array $config, array $m, $reason) {
		$new_id = $this->api->deactivate_user($config['ringotel_org_id'], $m['ringotel_user_id']);
		$this->retired_ids[$m['ringotel_user_id']] = true;
		//Ringotel replaces the user with a new unactivated user, so store the new ID
		$sql = "update v_ringotel_users set ringotel_user_id = coalesce(:new_id, ringotel_user_id), ringotel_status = -1, ";
		$sql .= "activated_at = null, deactivated_reason = :reason, inactivity_notified_at = null, update_date = now() ";
		$sql .= "where ringotel_user_uuid = :uuid ";
		$this->query($sql, ['new_id' => $new_id, 'reason' => $reason, 'uuid' => $m['ringotel_user_uuid']]);
		$this->log($domain_uuid, $m['extension'], 'deactivate', 'success', "Deactivated {$m['name']} ($reason)");
	}

	private function selected_mappings($domain_uuid, array $uuids) {
		$uuids = array_values(array_filter($uuids, 'is_uuid'));
		if (empty($uuids)) { return []; }
		$params = ['domain_uuid' => $domain_uuid];
		$in = [];
		foreach ($uuids as $i => $u) { $in[] = ":u$i"; $params["u$i"] = $u; }
		$sql = "select * from v_ringotel_users where domain_uuid = :domain_uuid and ringotel_user_uuid in (".implode(',', $in).") ";
		return $this->rows($sql, $params);
	}

	/**
	 * Activate or deactivate selected users. Returns [ok count, error messages].
	 */
	public function set_activation($domain_uuid, array $ringotel_user_uuids, $activate) {
		$config = $this->get_domain_config($domain_uuid);
		if (!$config || empty($config['ringotel_org_id'])) { return [0, ['Domain is not linked to Ringotel']]; }
		if ($this->bool_setting('dry_run', false)) {
			foreach ($this->selected_mappings($domain_uuid, $ringotel_user_uuids) as $m) {
				$this->log($domain_uuid, $m['extension'], $activate ? 'activate' : 'deactivate', 'dry_run', ($activate ? 'Would activate ' : 'Would deactivate ').$m['name']);
			}
			return [0, ['Dry run is on, so nothing was changed in Ringotel. See Logs.']];
		}
		if (!$this->lock($domain_uuid, 30)) { return [0, ['A sync is running for this domain. Try again in a moment.']]; }
		$ok = 0;
		$errors = [];
		try {
			foreach ($this->selected_mappings($domain_uuid, $ringotel_user_uuids) as $m) {
				try {
					$is_active = (int)$m['ringotel_status'] === ringotel_api::STATUS_ACTIVATED;
					if ($activate && !$is_active) {
						$this->api->activate_user($config['ringotel_org_id'], $m['ringotel_user_id'], $this->bool_setting('activation_send_email', true));
						$this->query("update v_ringotel_users set ringotel_status = 1, activated_at = now(), deactivated_reason = null, update_date = now() where ringotel_user_uuid = :uuid", ['uuid' => $m['ringotel_user_uuid']]);
						$this->log($domain_uuid, $m['extension'], 'activate', 'success', "Activated {$m['name']}");
						$ok++;
					}
					elseif (!$activate && $is_active) {
						$this->deactivate_mapping($domain_uuid, $config, $m, 'manual');
						$ok++;
					}
				}
				catch (Exception $e) {
					$errors[] = "{$m['extension']}: ".$e->getMessage();
					$this->log($domain_uuid, $m['extension'], 'error', 'error', ($activate ? 'Activate' : 'Deactivate').' failed: '.$e->getMessage());
				}
			}
		}
		finally {
			$this->unlock($domain_uuid);
		}
		return [$ok, $errors];
	}

	//users that exist in Ringotel but are not linked to any extension

	private function set_unlinked_count($domain_uuid, $count) {
		$this->query("update v_ringotel_domains set unlinked_count = :count where domain_uuid = :domain_uuid",
			['count' => (int)$count, 'domain_uuid' => $domain_uuid]);
	}

	/**
	 * Live list of Ringotel users in this domain's connection that the app does not manage.
	 * Parking slots are left out. Also refreshes the stored count.
	 */
	public function get_unlinked_users($domain_uuid) {
		$config = $this->get_domain_config($domain_uuid);
		if (!$config || empty($config['ringotel_org_id']) || empty($config['ringotel_branch_id'])) { return []; }
		$users = $this->api->get_users($config['ringotel_org_id'], $config['ringotel_branch_id']);
		$linked_ids = array_flip(array_filter(array_column($this->mappings($domain_uuid), 'ringotel_user_id')));
		$unlinked = [];
		foreach ($users as $u) {
			if (empty($u['id']) || isset($linked_ids[$u['id']])) { continue; }
			if (isset($u['status']) && (int)$u['status'] == ringotel_api::STATUS_PARKING) { continue; }
			$u['last_seen_ts'] = ringotel_api::last_seen($u);
			$unlinked[] = $u;
		}
		usort($unlinked, function ($a, $b) {
			return [strlen($a['extension'] ?? ''), $a['extension'] ?? ''] <=> [strlen($b['extension'] ?? ''), $b['extension'] ?? ''];
		});
		$this->set_unlinked_count($domain_uuid, count($unlinked));
		return $unlinked;
	}

	/**
	 * Deactivate or delete unlinked Ringotel users chosen on the activations page.
	 * IDs are checked against the live unlinked list, so a managed user or a user
	 * from another organization can never be changed this way.
	 */
	public function act_on_unlinked($domain_uuid, array $ringotel_ids, $action) {
		if (!in_array($action, ['deactivate', 'delete'], true)) { return [0, ['Unknown action']]; }
		$config = $this->get_domain_config($domain_uuid);
		if (!$config || empty($config['ringotel_org_id'])) { return [0, ['Domain is not linked to Ringotel']]; }
		$dry = $this->bool_setting('dry_run', false);
		if (!$dry && !$this->lock($domain_uuid, 30)) { return [0, ['A sync is running for this domain. Try again in a moment.']]; }
		$ok = 0;
		$errors = [];
		try {
			$unlinked = [];
			foreach ($this->get_unlinked_users($domain_uuid) as $u) { $unlinked[$u['id']] = $u; }
			foreach ($ringotel_ids as $id) {
				$id = (string)$id;
				if (!isset($unlinked[$id])) { continue; }
				$u = $unlinked[$id];
				$ext = $u['extension'] ?? '';
				$name = $u['name'] ?? $ext;
				try {
					if ($action == 'deactivate') {
						if ((int)($u['status'] ?? -1) !== ringotel_api::STATUS_ACTIVATED) { continue; }
						if ($dry) {
							$this->log($domain_uuid, $ext, 'deactivate', 'dry_run', "Would deactivate Ringotel-only user $name");
							continue;
						}
						$this->api->deactivate_user($config['ringotel_org_id'], $id);
						$this->log($domain_uuid, $ext, 'deactivate', 'success', "Deactivated Ringotel-only user $name");
					}
					else {
						if ($dry) {
							$this->log($domain_uuid, $ext, 'delete', 'dry_run', "Would delete Ringotel-only user $name");
							continue;
						}
						$this->api->delete_user($config['ringotel_org_id'], $id);
						$this->log($domain_uuid, $ext, 'delete', 'success', "Deleted Ringotel-only user $name");
					}
					$ok++;
				}
				catch (Exception $e) {
					$errors[] = "$ext: ".$e->getMessage();
					$this->log($domain_uuid, $ext, 'error', 'error', ucfirst($action)." of Ringotel-only user failed: ".$e->getMessage());
				}
			}
			if ($dry) { $errors[] = 'Dry run is on, so nothing was changed in Ringotel. See Logs.'; }
			if ($ok > 0) { $this->get_unlinked_users($domain_uuid); } //refresh the count
		}
		finally {
			if (!$dry) { $this->unlock($domain_uuid); }
		}
		return [$ok, $errors];
	}

	public function set_exempt($domain_uuid, array $ringotel_user_uuids, $exempt) {
		$count = 0;
		foreach ($this->selected_mappings($domain_uuid, $ringotel_user_uuids) as $m) {
			$this->query("update v_ringotel_users set exempt = :exempt, update_date = now() where ringotel_user_uuid = :uuid",
				['exempt' => $exempt ? 'true' : 'false', 'uuid' => $m['ringotel_user_uuid']]);
			$this->log($domain_uuid, $m['extension'], $exempt ? 'exempt' : 'unexempt', 'success', ($exempt ? 'Exempted ' : 'Removed exemption for ').$m['name']);
			$count++;
		}
		return $count;
	}

}
}

?>
