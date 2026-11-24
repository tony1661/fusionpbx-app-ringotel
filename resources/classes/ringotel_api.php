<?php

/**
 * Thin client for the Ringotel Admin API.
 *
 * Every call is a POST to one endpoint with {"method": ..., "params": {...}}.
 * Success returns {"result": ...}. Failure returns {"error": {...}}, often with HTTP 200.
 *
 * Status values on a user (from the Ringotel Admin API docs):
 *   -2 parking slot, -1 unactivated, 0 blocked, 1 activated, 2 attached extension
 */
if (!class_exists('ringotel_api')) {
class ringotel_api {

	const STATUS_PARKING = -2;
	const STATUS_UNACTIVATED = -1;
	const STATUS_BLOCKED = 0;
	const STATUS_ACTIVATED = 1;
	const STATUS_ATTACHED = 2;

	private $url;
	private $key;
	private $timeout;

	public function __construct($url, $key, $timeout = 30) {
		$this->url = $url ?: 'https://shell.ringotel.co/api';
		$this->key = $key;
		$this->timeout = $timeout;
	}

	/**
	 * Make one API call. Returns the "result" value or throws an exception.
	 */
	public function call($method, array $params = []) {
		if (empty($this->key)) {
			throw new Exception('Ringotel API key is not set');
		}
		$body = json_encode(['method' => $method, 'params' => (object)$params]);

		$attempts = 0;
		while (true) {
			$attempts++;
			$ch = curl_init($this->url);
			curl_setopt_array($ch, [
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $body,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => $this->timeout,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_HTTPHEADER => [
					'Content-Type: application/json',
					'Authorization: Bearer '.$this->key,
				],
			]);
			$response = curl_exec($ch);
			$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curl_error = curl_error($ch);
			curl_close($ch);

			//retry on network errors, rate limits and server errors
			$retry = ($response === false || $http_code == 429 || $http_code >= 500);
			if ($retry && $attempts < 3) {
				sleep($attempts * 2);
				continue;
			}
			break;
		}

		if ($response === false) {
			throw new Exception("Ringotel $method failed: $curl_error");
		}
		if ($http_code == 401 || $http_code == 403) {
			throw new Exception("Ringotel $method: not authorized (HTTP $http_code). Check the API key.");
		}
		//some methods return an empty body on success
		if (trim($response) === '') {
			if ($http_code >= 200 && $http_code < 300) { return null; }
			throw new Exception("Ringotel $method: HTTP $http_code with empty response");
		}
		$data = json_decode($response, true);
		if (!is_array($data)) {
			throw new Exception("Ringotel $method: invalid response (HTTP $http_code)");
		}
		if (isset($data['error'])) {
			$err = $data['error'];
			$message = is_array($err) ? ($err['message'] ?? json_encode($err)) : (string)$err;
			throw new Exception("Ringotel $method: $message");
		}
		return $data['result'] ?? null;
	}

	//organizations and connections

	public function get_organizations() {
		return $this->call('getOrganizations') ?: [];
	}

	public function get_branches($orgid) {
		return $this->call('getBranches', ['orgid' => $orgid]) ?: [];
	}

	//users

	public function get_users($orgid, $branchid = null) {
		$params = ['orgid' => $orgid];
		if (!empty($branchid)) { $params['branchid'] = $branchid; }
		$result = $this->call('getUsers', $params);
		return is_array($result) ? $result : [];
	}

	/**
	 * Create a user. Pass status -1 or 0 for a directory-only user (no license),
	 * 1 to activate. The docs list 0 as "unactivated" for createUser.
	 */
	public function create_user(array $fields) {
		$result = $this->call('createUser', $fields);
		//result is the user object, sometimes wrapped in an array
		if (isset($result[0]) && is_array($result[0])) { return $result[0]; }
		return $result;
	}

	public function update_user($orgid, $userid, array $fields) {
		$params = array_merge($fields, ['orgid' => $orgid, 'id' => $userid]);
		return $this->call('updateUser', $params);
	}

	/**
	 * Activate an existing unactivated user. updateUser only accepts status 1.
	 */
	public function activate_user($orgid, $userid, $send_email = true) {
		$params = ['status' => 1];
		if (!$send_email) { $params['noemail'] = true; }
		return $this->update_user($orgid, $userid, $params);
	}

	/**
	 * Deactivate an activated user. Ringotel replaces the user with a NEW
	 * unactivated user and returns the new user ID(s). Callers must store the new ID.
	 * Returns the first new ID or null.
	 */
	public function deactivate_user($orgid, $userid) {
		$result = $this->call('deactivateUser', ['orgid' => $orgid, 'id' => $userid]);
		if (is_array($result) && !empty($result)) {
			$first = reset($result);
			return is_array($first) ? ($first['id'] ?? null) : (string)$first;
		}
		return null;
	}

	public function delete_user($orgid, $userid) {
		return $this->call('deleteUser', ['orgid' => $orgid, 'id' => $userid]);
	}

	/**
	 * Most recent device activity for a user, in unix seconds, or null.
	 * Uses devs[].ts (last login on each device, in ms).
	 */
	public static function last_seen(array $user) {
		$latest = 0;
		if (!empty($user['devs']) && is_array($user['devs'])) {
			foreach ($user['devs'] as $dev) {
				if (!empty($dev['ts']) && $dev['ts'] > $latest) { $latest = $dev['ts']; }
			}
		}
		//an online user counts as seen now
		$state = isset($user['state']) ? (int)$user['state'] : 0;
		if ($state != 0 && $state != 5 && $state != -1) {
			$latest = max($latest, time() * 1000);
		}
		return $latest > 0 ? (int)floor($latest / 1000) : null;
	}

}
}

?>
