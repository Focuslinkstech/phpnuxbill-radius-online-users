<?php

use Radius;

register_menu("Radius Online Users", true, "radon_users", 'RADIUS', '');

function radon_users()
{
	global $ui;
	_admin();
	$ui->assign('_title', 'Radius Online Users');
	$ui->assign('_system_menu', 'radius');
	$admin = Admin::_info();
	$ui->assign('_admin', $admin);

	$error = [];
	$success = [];

	// Handle AJAX disconnection request
	if (isset($_POST['ajax_disconnect'])) {
		header('Content-Type: application/json');
		$username = _post('username');
		$response = ['success' => false, 'message' => ''];

		if (empty($username)) {
			$response['message'] = Lang::T("Username is required.");
			echo json_encode($response);
			exit;
		}

		// Use raw SQL UPDATE to avoid ORM primary key issues
		$db = ORM::get_db();
		$stmt = $db->prepare("UPDATE radacct SET acctstoptime = ? WHERE username = ? AND acctstoptime IS NULL");
		$stmt->execute([date('Y-m-d H:i:s'), $username]);
		$affectedRows = $stmt->rowCount();

		if ($affectedRows > 0) {
			$response['success'] = true;
			$response['message'] = Lang::T("User $username disconnected successfully.");
			_log(Lang::T("User $username disconnected successfully."));
		} else {
			$response['message'] = Lang::T("Username: $username has no active session.");
			_log(Lang::T("Username: $username has no active session."));
		}

		echo json_encode($response);
		exit;
	}

	// Handle AJAX mass disconnection request
	if (isset($_POST['ajax_mass_disconnect'])) {
		header('Content-Type: application/json');
		$selectedUsers = isset($_POST['selected_users']) ? $_POST['selected_users'] : [];
		$response = ['success' => false, 'disconnected' => 0, 'failed' => 0, 'message' => ''];

		if (empty($selectedUsers) || !is_array($selectedUsers)) {
			$response['message'] = Lang::T("No users selected.");
			echo json_encode($response);
			exit;
		}

		$db = ORM::get_db();
		$disconnectedCount = 0;
		$failedCount = 0;
		$stopTime = date('Y-m-d H:i:s');

		foreach ($selectedUsers as $username) {
			$stmt = $db->prepare("UPDATE radacct SET acctstoptime = ? WHERE username = ? AND acctstoptime IS NULL");
			$stmt->execute([$stopTime, $username]);
			if ($stmt->rowCount() > 0) {
				$disconnectedCount++;
			} else {
				$failedCount++;
			}
		}

		$response['success'] = true;
		$response['disconnected'] = $disconnectedCount;
		$response['failed'] = $failedCount;
		$response['message'] = Lang::T("Successfully disconnected $disconnectedCount user(s).");
		if ($failedCount > 0) {
			$response['message'] .= " " . Lang::T("Failed to disconnect $failedCount user(s).");
		}
		_log(Lang::T("Mass disconnect: $disconnectedCount user(s) disconnected."));

		echo json_encode($response);
		exit;
	}

	// Handle single user disconnection (legacy form submission)
	if (isset($_POST['kill']) && !isset($_POST['mass_kill'])) {
		$username = _post('username');

		// Use raw SQL UPDATE to avoid ORM primary key issues
		$db = ORM::get_db();
		$stmt = $db->prepare("UPDATE radacct SET acctstoptime = ? WHERE username = ? AND acctstoptime IS NULL");
		$stmt->execute([date('Y-m-d H:i:s'), $username]);
		$affectedRows = $stmt->rowCount();

		if ($affectedRows > 0) {
			$success[] = Lang::T("User $username disconnected successfully.");
			_log(Lang::T("User $username disconnected successfully."));
		} else {
			$error[] = Lang::T("Username: $username has no active session.");
			_log(Lang::T("Username: $username has no active session."));
		}
	}

	// Handle mass disconnection (legacy form submission)
	if (isset($_POST['mass_kill']) && isset($_POST['selected_users'])) {
		$selectedUsers = $_POST['selected_users'];
		$disconnectedCount = 0;
		$failedCount = 0;
		$db = ORM::get_db();
		$stopTime = date('Y-m-d H:i:s');

		foreach ($selectedUsers as $username) {
			$stmt = $db->prepare("UPDATE radacct SET acctstoptime = ? WHERE username = ? AND acctstoptime IS NULL");
			$stmt->execute([$stopTime, $username]);
			if ($stmt->rowCount() > 0) {
				$disconnectedCount++;
			} else {
				$failedCount++;
			}
		}

		if ($disconnectedCount > 0) {
			$success[] = Lang::T("Successfully disconnected $disconnectedCount user(s).");
			_log(Lang::T("Mass disconnect: $disconnectedCount user(s) disconnected."));
		}
		if ($failedCount > 0) {
			$error[] = Lang::T("Failed to disconnect $failedCount user(s) - no active session found.");
		}
	}

	// Get online users
	$useron = ORM::for_table('radacct')
		->where_raw("acctstoptime IS NULL")
		->order_by_asc('acctsessiontime')
		->find_many();

	$totalCount = ORM::for_table('radacct')
		->where_raw("acctstoptime IS NULL")
		->count();

	// Calculate total data usage
	$totalUpload = 0;
	$totalDownload = 0;
	$totalUsage = 0;
	$totalUptime = 0;

	foreach ($useron as $user) {
		$totalUpload += (int)$user['acctinputoctets'];
		$totalDownload += (int)$user['acctoutputoctets'];
		$totalUptime += (int)$user['acctsessiontime'];
	}
	$totalUsage = $totalUpload + $totalDownload;

	$ui->assign('error', $error);
	$ui->assign('success', $success);
	$ui->assign('useron', $useron);
	$ui->assign('totalCount', $totalCount);
	$ui->assign('totalUpload', $totalUpload);
	$ui->assign('totalDownload', $totalDownload);
	$ui->assign('totalUsage', $totalUsage);
	$ui->assign('totalUptime', $totalUptime);
	$ui->assign('xheader', '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.3/css/jquery.dataTables.min.css">
		<link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
		<style>
			.stats-card {
				background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
				color: white;
				padding: 20px;
				border-radius: 10px;
				margin-bottom: 20px;
				box-shadow: 0 4px 6px rgba(0,0,0,0.1);
			}
			.stats-card.success {
				background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
			}
			.stats-card.info {
				background: linear-gradient(135deg, #3494E6 0%, #EC6EAD 100%);
			}
			.stats-card.warning {
				background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
			}
			.stats-card.danger {
				background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
			}
			.stats-value {
				font-size: 32px;
				font-weight: bold;
				margin: 10px 0;
			}
			.stats-label {
				font-size: 14px;
				opacity: 0.9;
				text-transform: uppercase;
				letter-spacing: 1px;
			}
			.table-actions {
				margin-bottom: 15px;
				padding: 10px;
				background: #f8f9fa;
				border-radius: 5px;
			}
			#onlineTable_wrapper {
				overflow-x: auto;
			}
			.checkbox-column {
				width: 30px;
			}
		</style>');
	$ui->display('radon.tpl');
}


// Function to format bytes into KB, MB, GB or TB
function radon_formatBytes($bytes, $precision = 2)
{
	$units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
	$bytes = max($bytes, 0);
	$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
	$pow = min($pow, count($units) - 1);
	$bytes /= pow(1024, $pow);
	return round($bytes, $precision) . ' ' . $units[$pow];
}

// Convert seconds into months, days, hours, minutes, and seconds.
function radon_secondsToTimeFull($ss)
{
	$s = $ss % 60;
	$m = floor(($ss % 3600) / 60);
	$h = floor(($ss % 86400) / 3600);
	$d = floor(($ss % 2592000) / 86400);
	$M = floor($ss / 2592000);

	return "$M months, $d days, $h hours, $m minutes, $s seconds";
}

function radon_secondsToTime($inputSeconds)
{
	$secondsInAMinute = 60;
	$secondsInAnHour = 60 * $secondsInAMinute;
	$secondsInADay = 24 * $secondsInAnHour;

	// Extract days
	$days = floor($inputSeconds / $secondsInADay);

	// Extract hours
	$hourSeconds = $inputSeconds % $secondsInADay;
	$hours = floor($hourSeconds / $secondsInAnHour);

	// Extract minutes
	$minuteSeconds = $hourSeconds % $secondsInAnHour;
	$minutes = floor($minuteSeconds / $secondsInAMinute);

	// Extract the remaining seconds
	$remainingSeconds = $minuteSeconds % $secondsInAMinute;
	$seconds = ceil($remainingSeconds);

	// Format and return
	$timeParts = [];
	$sections = [
		'day' => (int) $days,
		'hour' => (int) $hours,
		'minute' => (int) $minutes,
		'second' => (int) $seconds,
	];

	foreach ($sections as $name => $value) {
		if ($value > 0) {
			$timeParts[] = $value . ' ' . $name . ($value == 1 ? '' : 's');
		}
	}

	return implode(', ', $timeParts);
}

function radon_users_cleandb()
{
	global $ui;
	_admin();
	$admin = Admin::_info();

	if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
		r2(getUrl('dashboard'), 'e', Lang::T('You do not have permission to access this page'));
	}

	$action = _get('action', 'all');
	$days = _get('days', 30);

	try {
		if ($action == 'old') {
			// Truncate records older than specified days
			$dateThreshold = date('Y-m-d H:i:s', strtotime("-$days days"));
			$deleted = ORM::for_table('radacct')
				->where_lt('acctstarttime', $dateThreshold)
				->delete_many();
			r2(U . 'plugin/radon_users', 's', Lang::T("Deleted $deleted old record(s) from RADACCT table (older than $days days)."));
		} elseif ($action == 'stopped') {
			// Truncate only stopped sessions
			$deleted = ORM::for_table('radacct')
				->where_not_null('acctstoptime')
				->delete_many();
			r2(U . 'plugin/radon_users', 's', Lang::T("Deleted $deleted stopped session(s) from RADACCT table."));
		} else {
			// Truncate all records
			ORM::get_db()->exec('TRUNCATE TABLE `radacct`');
			r2(U . 'plugin/radon_users', 's', Lang::T("RADACCT table truncated successfully."));
		}
	} catch (Exception $e) {
		r2(U . 'plugin/radon_users', 'e', Lang::T("Failed to truncate RADACCT table: " . $e->getMessage()));
	}
}
