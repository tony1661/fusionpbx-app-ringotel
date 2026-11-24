<?php

/**
 * Ringotel background sync service.
 *
 * Runs as a systemd service (see ringotel.service in this folder) and syncs every
 * enabled domain every ringotel > sync_interval seconds.
 *
 * Manual use:
 *   php ringotel.php --once                 sync all enabled domains one time
 *   php ringotel.php --once --domain=UUID   sync one domain
 *   php ringotel.php --once --force         push every extension, ignoring saved hashes
 */

if (php_sapi_name() !== 'cli') {
	exit;
}

//includes
	require_once dirname(__DIR__, 4)."/resources/require.php";
	require_once dirname(__DIR__)."/classes/ringotel_sync.php";

//options
	$options = getopt('', ['once', 'domain:', 'force', 'quiet']);
	$once = isset($options['once']);
	$force = isset($options['force']);
	$quiet = isset($options['quiet']);
	$only_domain = $options['domain'] ?? null;

	function ringotel_out($line) {
		global $quiet;
		if (!$quiet) { echo date('Y-m-d H:i:s')." ".$line."\n"; }
	}

//make sure only one service instance runs
	if (!$once) {
		$pid_file = sys_get_temp_dir().'/ringotel_service.pid';
		$pid_handle = fopen($pid_file, 'c');
		if (!$pid_handle || !flock($pid_handle, LOCK_EX | LOCK_NB)) {
			ringotel_out("Another instance is already running");
			exit(1);
		}
		ftruncate($pid_handle, 0);
		fwrite($pid_handle, (string)getmypid());
	}

	$last_prune = 0;
	$running = true;
	if (function_exists('pcntl_async_signals')) {
		pcntl_async_signals(true);
		pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
		pcntl_signal(SIGINT, function () use (&$running) { $running = false; });
	}

	do {
		$started = time();
		$interval = 120;
		try {
			//fresh connection and settings each run so changes apply without a restart
			$database = new database;
			$sync = new ringotel_sync($database);
			$sync->source = $once ? 'cli' : 'service';
			$sync->output = 'ringotel_out';
			$interval = max(30, (int)$sync->setting('sync_interval', 120));

			if (empty($sync->setting('api_key'))) {
				ringotel_out("No API key set (Default Settings > ringotel > api_key). Waiting.");
			}
			elseif ($only_domain) {
				$result = $sync->sync_domain($only_domain, ['force' => $force]);
				ringotel_out("Done: ".$result['status']." ".$result['message']);
			}
			else {
				$results = $sync->sync_all(['force' => $force]);
				foreach ($results as $domain_uuid => $result) {
					ringotel_out("$domain_uuid: ".$result['status']." ".$result['message']);
				}
				if (empty($results)) { ringotel_out("No domains are enabled for sync"); }
			}

			//prune old logs once a day
			if (time() - $last_prune > 86400) {
				$sync->prune_logs(90);
				$last_prune = time();
			}
			unset($sync, $database);
		}
		catch (Throwable $e) {
			ringotel_out("Error: ".$e->getMessage());
		}

		if ($once) { break; }

		//sleep until the next run, waking early on shutdown
		$wake = $started + $interval;
		while ($running && time() < $wake) { sleep(1); }
	} while ($running);

	exit(0);

?>
