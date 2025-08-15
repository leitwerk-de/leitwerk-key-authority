<?php
##
## Copyright 2013-2017 Opera Software AS
## Modifications Copyright 2021 Leitwerk AG
##
## Licensed under the Apache License, Version 2.0 (the "License");
## you may not use this file except in compliance with the License.
## You may obtain a copy of the License at
##
## http://www.apache.org/licenses/LICENSE-2.0
##
## Unless required by applicable law or agreed to in writing, software
## distributed under the License is distributed on an "AS IS" BASIS,
## WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
## See the License for the specific language governing permissions and
## limitations under the License.
##

/**
 * Import the given hosts of the csv document string. The import must be
 * entirely successful or it must fail entirely. In case of a failure,
 * error strings are stored in the variable referenced by $error_ref.
 * On success, an empty string is stored in that reference.
 *
 * @param string $csv_document The content of the csv document to import
 * @param string $error_ref Reference to a variable where error messages are stored
 * @return array|NULL Prepared information about the hosts, needed for the bulk import or null in case of an error
 */
function prepare_import(string $csv_document, &$error_ref): ?array {
	$errors = "";
	$lines = explode("\n", $csv_document);
	$line_num = 0;
	$entries = [];
	foreach ($lines as $line) {
		$line_num++;
		if ($line === "") {
			continue;
		}
		$cells = str_getcsv($line, ",", "\"", "");
		$count = count($cells);
		if ($count != 4 or $count != 5) {
			$errors .= "- Line $line_num contains $count columns, but expected 4 or 5\n";
			continue;
		}
		$hostname = $cells[0];
		if (!Server::hostname_valid($hostname)) {
			$errors .= "- Line $line_num contains an invalid hostname: $hostname\n";
			continue;
		}
		$port_str = $cells[1];
		if ($port_str == "") {
			$port = 22;
		} else {
			if (!preg_match('/^[0-9]+$/', $port_str)) {
				$errors .= "- Line $line_num contains an invalid port number: $port_str\n";
				continue;
			}
			$port = (int)$port_str;
			if ($port > 65535) {
				$errors .= "- Port number in line $line_num is too large: Got $port, maximum is 65535\n";
				continue;
			}
		}
		$jumphosts = $cells[2];
		if (!Server::jumphosts_valid($jumphosts)) {
			$errors .= "- The jumphost list in line $line_num is invalid.";
			continue;
		}
		if ($cells[3] === "") {
			$errors .= "- Line $line_num contains an empty leader field. Each server needs at least one leader or leader group.\n";
			continue;
		}
		$admin_names = explode(";", $cells[3]);
		$admins = [];
		foreach ($admin_names as $name) {
			$entity = user_or_group_by_name($name);
			if ($entity !== null) {
				$admins[] = $entity;
			} else {
				$errors .= "- Leader in line $line_num: \"$name\" could not be found, neither as user nor as group.\n";
			}
		}
		if ($cells[4] === "") {
			$keys_sync_user = "keys-sync";
		}
		else {
			$keys_sync_user = $cells[4];
		}
		$entries[] = [
			"hostname" => $hostname,
			"port" => $port,
			"jumphosts" => $jumphosts,
			"admins" => $admins,
			"keys_sync_user" => $keys_sync_user,
		];
	}
	$error_ref = $errors;
	return $errors == "" ? $entries : null;
}

/**
 * Search for a user with the given login name. If no such user exists, search for
 * a group with the given name. If also no matching group exists, null is returned.
 *
 * @param string $name The name of the user/group
 * @return Entity|NULL The user or group, or null if nothing was found
 */
function user_or_group_by_name(string $name): ?Entity {
	global $user_dir, $group_dir;

	try {
		return $user_dir->get_user_by_uid($name);
	} catch(UserNotFoundException $e) {
		try {
			return $group_dir->get_group_by_name($name);
		} catch(GroupNotFoundException $e) {
			return null;
		}
	}
}

/**
 * Read the setting default_key_supervision from the config, but map invalid
 * values to the default value "full".
 *
 * @return string "full", "rootonly" or "off"
 */
function default_key_scan_setting(): string {
	global $config;

	$setting = $config["general"]["default_key_supervision"];
	if ($setting == "") {
		// "off" is mapped to an empty string by the ini parser
		return "off";
	} else if ($setting === "rootonly") {
		return "rootonly";
	} else {
		return "full";
	}
}

/**
 * Import multiple servers based on the data, that has been prepared.
 *
 * @param array $entries Prepared data: array of server entries
 * @return array Statistics array about the number of added servers and number of already existing servers
 */
function run_import(array $entries): array {
	global $server_dir;

	$imported = 0;
	$existed = 0;
	foreach ($entries as $entry) {
		$server = new Server;
		$server->hostname = $entry['hostname'];
		$server->port = $entry['port'];
		$server->key_scan = default_key_scan_setting();
		$server->jumphosts = $entry['jumphosts'];
		$server->keys_sync_user = $entry['keys_sync_user'];
		try {
			$server_dir->add_server($server);
			foreach($entry['admins'] as $admin) {
				$server->add_admin($admin);
			}
			$imported++;
		} catch(ServerAlreadyExistsException $e) {
			$existed++;
		}
	}
	return [
		"imported" => $imported,
		"existed" => $existed,
	];
}

if(isset($_POST['add_server'])) {
	$hostname = trim($_POST['hostname']);
	if(!Server::hostname_valid($hostname)) {
		$content = new PageSection('invalid_hostname');
		$content->set('hostname', $hostname);
	} else {
		$admin_names = preg_split('/[\s,]+/', $_POST['admins'], -1, PREG_SPLIT_NO_EMPTY);
		$admins = array();
		foreach($admin_names as $admin_name) {
			$new_admin = user_or_group_by_name($admin_name);
			if ($new_admin !== null) {
				$admins[] = $new_admin;
			} else {
				$content = new PageSection('user_not_found');
			}
		}
		if(count($admins) == count($admin_names)) {
			$server = new Server;
			$server->hostname = $hostname;
			$server->port = $_POST['port'];
			$server->key_scan = default_key_scan_setting();
			$server->jumphosts = $_POST['jumphosts'];
			$server->keys_sync_user = $_POST['keys_sync_user'];
			try {
				$server_dir->add_server($server);
				foreach($admins as $admin) {
					$server->add_admin($admin);
				}
				$alert = new UserAlert;
				$alert->content = 'Server \'<a href="'.rrurl('/servers/'.urlencode($hostname)).'" class="alert-link">'.hesc($hostname).'</a>\' successfully created.';
				$alert->escaping = ESC_NONE;
				$active_user->add_alert($alert);
			} catch(ServerAlreadyExistsException $e) {
				$alert = new UserAlert;
				$alert->content = 'Server \'<a href="'.rrurl('/servers/'.urlencode($hostname)).'" class="alert-link">'.hesc($hostname).'</a>\' is already known by Leitwerk Key Authority.';
				$alert->escaping = ESC_NONE;
				$alert->class = 'danger';
				$active_user->add_alert($alert);
			} catch (InvalidJumphostsException $e) {
				$alert = new UserAlert;
				$alert->content = 'The list of jumphosts has an invalid format.';
				$alert->escaping = ESC_NONE;
				$alert->class = 'danger';
				$active_user->add_alert($alert);
			}
			redirect('#add');
		}
	}
} else if (isset($_POST['add_bulk'])) {
	$csv_document = $_POST['import'];
	$entries = prepare_import($csv_document, $errors);
	$alert = new UserAlert;
	if ($entries !== null) {
		$result = run_import($entries);
		if ($result['imported'] == 1) {
			$msg = "1 server has been imported";
		} else {
			$msg = "{$result['imported']} servers have been imported";
		}
		if ($result['existed'] > 0) {
			if ($result['existed'] == 1) {
				$msg .= ", 1 server is already known by Leitwerk Key Authority";
			} else {
				$msg .= ", {$result['existed']} servers are already known by Leitwerk Key Authority";
			}
		}
		$alert->content = $msg;
	} else {
		$error_msg = hesc("Failed to import server list:\n$errors");
		$alert->content = str_replace("\n", "<br>", $error_msg);
		$alert->escaping = ESC_NONE;
		$alert->class = 'danger';
	}
	$active_user->add_alert($alert);
	redirect("#add_bulk");
} else {
	$defaults = array();
	$defaults['key_management'] = array('keys');
	$defaults['sync_status'] = array('sync success', 'sync warning', 'sync failure', 'not synced yet');
	$defaults['hostname'] = null;
	$defaults['ip_address'] = null;
	$filter = simplify_search($defaults, $_GET);
	try {
		$servers = $server_dir->list_servers(array('pending_requests', 'admins'), $filter);
	} catch(ServerSearchInvalidRegexpException $e) {
		$servers = array();
		$alert = new UserAlert;
		$alert->content = "The hostname search pattern '".$filter['hostname']."' is invalid.";
		$alert->class = 'danger';
		$active_user->add_alert($alert);
	}
	if(isset($router->vars['format']) && $router->vars['format'] == 'json') {
		$page = new PageSection('servers_json');
		$page->set('servers', $servers);
		header('Content-type: application/json; charset=utf-8');
		echo $page->generate();
		exit;
	} else {
		$content = new PageSection('servers');
		$content->set('filter', $filter);
		$content->set('admin', $active_user->admin);
		$content->set('servers', $servers);
		$content->set('all_users', $user_dir->list_users());
		$content->set('all_groups', $group_dir->list_groups());
		if(file_exists('config/keys-sync.pub')) {
			$content->set('keys-sync-pubkey', file_get_contents('config/keys-sync.pub'));
		} else {
			$content->set('keys-sync-pubkey', 'Error: keyfile missing');
		}
	}
}

$page = new PageSection('base');
$page->set('title', 'Servers');
$page->set('content', $content);
$page->set('alerts', $active_user->pop_alerts());
echo $page->generate();
