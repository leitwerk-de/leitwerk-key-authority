#!/usr/bin/php
<?php
##
## Copyright 2021 Leitwerk AG
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

chdir(__DIR__);
require('../core.php');
require('ssh.php');
$active_user = User::get_keys_sync_user();

$keys_in_db = ExternalKey::list_external_keys();

// Associative array that maps key contents (base64 strings) to a list of ExternalKey objects.
$keys_in_db_assoc = [];
foreach ($keys_in_db as $key) {
	$keys_in_db_assoc[$key->keydata] = $key;
}

$servers = $server_dir->list_servers([], ['key_management' => ['keys']]);
$keys = [];
foreach ($servers as $server) {
	$ssh = null;
	$error_string = "";
	$start_time = date('c');
	try {
		echo date('c')." Reading external ssh keys from {$server->hostname}\n";

		$ssh = $server->connect_ssh();
		$server_identifier = $ssh->getServerIdentifier();
		$keys[$server->id] = read_server_keys($server, $error_string, $ssh, $keys_in_db_assoc, $server_identifier);
		if ($error_string == "") {
			// Empty error set is stored as null in database
			$error_string = null;
		}
	} catch (Throwable $e) {
		$error_string .= "Exception while reading keys of {$server->hostname}:\n  " . $e->getMessage() . "\n";
	}
	if ($error_string !== $server->key_supervision_error) {
		$server->key_supervision_error = $error_string;
		$server->update();
	}
	if ($ssh != null) {
		try {
			$server->update_status_file($ssh);
		} catch (Throwable $e) {
			// ignore - Monitoring will complain if status file is not updated
		}
	}
}

// Associative array that maps key contents (base64 strings) to a list of ExternalKey objects.
$found_keys = [];

foreach ($keys as $server_id => $entries) {
	foreach ($entries as $entry) {
		$key_content = $entry['key'];
		if (!isset($found_keys[$key_content])) {
			$found_keys[$key_content] = new ExternalKey(null, [
				'type' => $entry['type'],
				'keydata' => $key_content,
			]);
			$found_keys[$key_content]->occurrence = [];
		}
		$found_keys[$key_content]->occurrence[] = new ExternalKeyOccurrence(null, [
			'server' => $server_id,
			'account_name' => $entry['user'],
			'comment' => $entry['comment'],
		]);
	}
}

foreach ($found_keys as $keydata => $key) {
	// Look for keys that have been found but are not in the database
	if (!isset($keys_in_db_assoc[$keydata])) {
		$key->insert();
		sendmail_appeared_key($key);
	}
}

foreach ($keys_in_db_assoc as $keydata => $key) {
	if (isset($found_keys[$key->keydata])) {
		// Keys are already known and also still in use - update occurrences
		$key->update_occurrences($found_keys[$key->keydata]->occurrence);
	} elseif ($key->status == 'new') {
		// Keys in state 'new' that are no longer on any system will be deleted
		$key->delete();
	} else {
		// For other keys not in state 'new' that are no longer on any system, occurrences will be deleted
		$key->update_occurrences([]);
	}
}

/**
 * Parse a line contained in /etc/passwd
 * This function returns an associative array that contains the following fields:
 * - user:   (string) username
 * - home:   (string) path of the user's home directory
 * - active: (bool) If the user account is enabled (has a valid shell)
 * @param string $line The line to parse
 * @return array Information about the given user entry or null if the entry is invalid
 */
function parse_user_entry(string $line) {
	// remove trailing line feed, if there is one
	if (substr($line, -1, 1) == "\n") {
		$line = substr($line, 0, -1);
	}

	$fields = explode(':', $line);
	if (count($fields) != 7) {
		return null;
	}

	$inactive_shells = [
		'/bin/false',
		'/bin/nologin',
		'/sbin/nologin',
		'/usr/bin/false',
		'/usr/bin/nologin',
		'/usr/sbin/nologin',
	];
	return [
		'user' => $fields[0],
		'home' => $fields[5],
		'active' => !in_array($fields[6], $inactive_shells),
	];
}

/**
 * Read all public-keys of active accounts from the given server that are contained
 * either in ~/.ssh/authorized_keys or in ~/.ssh/authorized_keys2
 * Returns an array of associative arrays that contain the following fields:
 * - user:    (string) username of this key
 * - type:    (string) type part of a key entry, for example "ssh-rsa"
 * - key:     (string) base64 encoded key information
 * - comment: (string) comment field at the end of a key entry
 *
 * @param Server $server The server to read keys from
 * @param string &$error_string Reference to a string variable where error messages are appended
 * @param SSH $connection SSH connection instance to the server
 * @param array $keys_in_db_assoc Known keys, used to check if some keys need to be removed
 * @return array of ssh keys that are active on this server
 */
function read_server_keys(Server $server, string &$error_string, SSH $connection, $keys_in_db_assoc, $server_identifier) {
	global $server_dir;

	if ($server->key_scan == 'off' || !external_keys_active($connection, $server_identifier)) {
		return [];
	}

	if (stripos($server_identifier, "win") !== false) {
		// check for "enabled" users - instead of checking local or AD enabled status
		$user_profiles = $connection->exec('Get-ChildItem /Users -Directory | Where-Object {Test-Path -Path (Join-Path $_.FullName ".ssh\authorized_keys")} | ForEach-Object {$_.Name}');

		$users = preg_split('/\r\n|\r|\n/', trim($user_profiles));
		$user_entries = array_map(function($user) {
			return [
				'user'   => $user,
				'home'   => '/Users/' . $user,
				'active' => true
			];
		}, $users);
	}
	else{
		$user_entries = $connection->file_get_lines("/etc/passwd");
		$user_entries = array_map('parse_user_entry', $user_entries);
		$user_entries = array_filter($user_entries, function($entry) {
			return $entry['active'];
		});
	}
	$keys = [];
	try {
		foreach ($user_entries as $user) {
			if ($server->key_scan == 'full' || $user['user'] == 'root') {
				$path = "{$user['home']}/.ssh/authorized_keys";
				add_entries($keys, $user['user'], $connection, $path, $error_string, $keys_in_db_assoc, $server_identifier);
				$path .= '2';
				add_entries($keys, $user['user'], $connection, $path, $error_string, $keys_in_db_assoc, $server_identifier);
			}
		}
	} catch (Exception $e) {
		throw new Exception("Could not parse external keys in $path:\n  " . $e->getMessage());
	}

	return $keys;
}

/**
 * Check if external keys (~/.ssh/authorized_keys) are activated in the sshd-config
 * of a target server.
 *
 * @param SSH $connection SSH connection instance to the server
 * @return bool true if they are active (users can login using keys in authorized_keys), false if they are ignored
 */
function external_keys_active($connection, $server_identifier) {
	if (stripos($server_identifier, "win") !== false) {
		$sshd_config_file = '/ProgramData/ssh/sshd_config';
	}
	else{
		$sshd_config_file = "/etc/ssh/sshd_config";
	}
	$config_lines = $connection->file_get_lines($sshd_config_file);
	foreach ($config_lines as $line) {
		if (strpos(strtolower($line), "authorizedkeysfile") === 0) {
			return strpos($line, ".ssh/authorized_keys") !== false;
		}
	}
	// configuration not found, by default external keys are activated
	return true;
}

/**
 * Scan for keys that are contained in an authorized_keys file and add them to
 * the array of key entries.
 *
 * @param array $entries Reference to the array of entries to fill
 * @param string $user Username to add to the entries
 * @param SSH $ssh The ssh connection instance to the target server
 * @param string $filename Name of the authorized_keys file to scan (If it does not exist, it is ignored)
 * @param string &$error_string Reference to a string variable where error messages are appended
 * @param array $keys_in_db_assoc Known keys, used to check if some keys need to be removed
 */
function add_entries(array &$entries, string $user, SSH $ssh, string $filename, string &$error_string, $keys_in_db_assoc, $server_identifier) {
	try {
		$lines = $ssh->file_get_lines($filename);
	} catch (SSHException $e) {
		check_missing_file($ssh, $filename, $error_string, $server_identifier);
		return;
	}
	// Use the 'test' shell command to check for writability.
	// The php function is_writable() produces wrong results when using facl.
	$shell_escaped_filename = escapeshellarg($filename);

	if (stripos($server_identifier, "win") !== false) {
		$output = $ssh->exec("try {[System.IO.File]::OpenWrite($shell_escaped_filename).Close(); Write-Output '0\n'} catch { Write-Output 'File is not writable.'};");
	}
	else{
		$output = $ssh->exec("test -w {$shell_escaped_filename}; echo $?");
	}
	if ($output !== "0\n") {
		$error_string .= "The file {$filename} is not writable for the keys-sync user. This will prevent key authority from removing old keys.\n";
	}

	$file_modified = false;
	$new_filecontent = '';
	$line_num = 1;
	$removal_log = '';
	foreach ($lines as $line) {
		$keep_line = true; // Set to false, if line needs to be deleted
		// ignore empty lines and comments
		if (trim($line) !== '' && substr($line, 0, 1) !== '#') {
			if (preg_match('%^(([^ "]|"([^"\\\\]|\\\\+[^\\\\])*")+ )?((ssh|ecdsa)-[^ ]+) ([a-zA-Z0-9+/=]+)( (.*))?$%', $line, $matches)) {
				$entry = [
					'user' => $user,
					'type' => $matches[4],
					'key' => $matches[6],
					'comment' => $matches[8] ?? "",
				];
				if (isset($keys_in_db_assoc[$entry['key']]) && $keys_in_db_assoc[$entry['key']]->status == 'denied') {
					$keep_line = false;
					$removal_log .= date("c"). " $filename: Removed key with comment {$entry['comment']}\n";
				}
				$entries[] = $entry;
			} else {
				$error_string .= "Line $line_num in $filename has an invalid format.\n";
			}
		}
		if ($keep_line) {
			$new_filecontent .= "$line\n";
			$line_num++;
		} else {
			$file_modified = true;
		}
	}
	if ($file_modified) {
		try {
			$ssh->file_put_contents($filename, $new_filecontent);
			echo $removal_log;
		} catch (SSHException $e) {
			$error_string .= "Removing 'denied' keys from $filename failed: {$e->getMessage()}\n";
		}
	}
}

/**
 * Check if the specified file is actually non-existent (in which case nothing happens)
 * If the file is instead not accessible because of permissions, an error message will be appended to $error_string
 *
 * @param SSH $ssh The ssh connection instance to the target server
 * @param string $filename Name of the authorized_keys file to scan
 * @param string &$error_string Reference to a string variable where error messages are appended
 */
function check_missing_file(SSH $ssh, string $filename, string &$error_string, $server_identifier) {
	try {
		$escaped_filename = escapeshellarg($filename);
		if (stripos($server_identifier, "win") !== false) {
			$stderr_output = $ssh->exec("try {[System.IO.File]::Open($escaped_filename, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read).Close();} catch {Write-Output 'File cannot be read or does not exist.'}");
		}
		else{
			$stderr_output = $ssh->exec("LANG=en_US.UTF-8 head -c 0 $escaped_filename 2>&1");
		}

		if (preg_match("%: ([^:]+)\n\$%", $stderr_output, $matches)) {
			$err = $matches[1];
			if ($err !== "No such file or directory") {
				$error_string .= "Could not read $filename: $err\n";
			}
		}
		else if ($stderr_output === "") {
			$error_string .= "Failed to check if $filename exists: Got no error message\n";
		}
		else {
			$error_string .= "Failed to check if $filename exists: $stderr_output";
		}
	} catch (SSHException $e) {
		$error_string .= "Failed to check if $filename exists: {$e->getMessage()}\n";
	}
}

/**
 * Send an email to the serveraccount leaders and server leaders, informing about one new key.
 * @param ExternalKey $appeared_key
 */
function sendmail_appeared_key(ExternalKey $appeared_key)  {
	global $config, $server_dir;

	// Two-dimensional array of affected accounts:
	// First index: server id; Second index: account name; Value: the key comment
	$server_accounts = [];

	foreach ($appeared_key->occurrence as $occurrence) {
		if (!isset($server_accounts[$occurrence->server])) {
			$server_accounts[$occurrence->server] = [];
		}
		if (!isset($server_accounts[$occurrence->server][$occurrence->account_name])) {
			$server_accounts[$occurrence->server][$occurrence->account_name] = $occurrence->comment;
		}
	}

	$to = []; // associative arrays, mapping mail address to user name
	$cc = []; // Users may be added to both (to + cc), in this case they will only be mentioned in To, not in Cc.

	foreach ($server_accounts as $server_id => $account_names) {
		$server = $server_dir->get_server_by_id($server_id);
		$accounts = $server->list_accounts();
		$serveradmins_as_recipients = false;
		foreach ($accounts as $account) {
			$account_admins_informed = false;
			if (isset($account_names[$account->name])) {
				$account_admins = $account->list_admins();
				foreach ($account_admins as $account_admin) {
					$to[$account_admin->email] = $account_admin->name;
					$account_admins_informed = true;
				}
			}
			if (!$account_admins_informed) {
				$serveradmins_as_recipients = true;
			}
		}
		foreach ($server->list_effective_admins() as $server_admin) {
			if ($serveradmins_as_recipients) {
				$to[$server_admin->email] = $server_admin->name;
			} else {
				// If every affected account has own leaders, server leaders will only be mentioned in cc
				$cc[$server_admin->email] = $server_admin->name;
			}
		}
	}

	$email = new Email;
	foreach ($to as $rcpt_mail => $rcpt_name) {
		$email->add_recipient($rcpt_mail, $rcpt_name);
	}
	foreach ($cc as $cc_rcpt_mail => $cc_rcpt_name) {
		if (!isset($to[$cc_rcpt_mail])) {
			$email->add_cc($cc_rcpt_mail, $cc_rcpt_name);
		}
	}
	$email->add_cc($config['email']['report_address'], $config['email']['report_name']);

	// Create different mail layouts, depending on the number of servers / accounts
	$singleserver = count($server_accounts) == 1;
	$singleaccount = $singleserver && count(reset($server_accounts)) == 1;
	if ($singleserver) {
		$server_id = array_keys($server_accounts)[0];
		$server = $server_dir->get_server_by_id($server_id);
		$hostname = $server->hostname;
		if ($singleaccount) {
			$account_name = array_keys(reset($server_accounts))[0];
			$accounts_comments = reset($server_accounts);
			$comment = reset($accounts_comments);

			$email->subject = "New ssh key appeared on {$account_name}@{$hostname}";
			$email->body = "The following key was found on {$account_name}@{$hostname} during a server scan:\n";
			$email->body .= "{$appeared_key->type} {$appeared_key->keydata} {$comment}\n";
		} else {
			$email->subject = "New ssh key appeared on {$hostname}";
			$email->body = "The following key was found on {$hostname} during a server scan:\n";
			$email->body .= "{$appeared_key->type} {$appeared_key->keydata}\n\n";
			$email->body .= "The following accounts are affected:\n";
			$email->body .= accounts_and_comments_table(reset($server_accounts));
		}
	} else {
		$email->subject = "New ssh key appeared on multiple servers";
		$email->body = "The following key was found during a server scan:\n";
		$email->body .= "{$appeared_key->type} {$appeared_key->keydata}\n\n";
		$email->body .= "The following servers are affected:\n";
		foreach ($server_accounts as $server_id => $accounts) {
			$server = $server_dir->get_server_by_id($server_id);
			$hostname = $server->hostname;
			$email->body .= "\n{$hostname}:\n";
			$email->body .= accounts_and_comments_table($accounts);
		}
	}

	$email->send();
}

/**
 * Create a text-based table with server account names and ssh key comments.
 *
 * @param array $accounts Associative array mapping account names to key comments
 * @return string The resulting table as multi-line string
 */
function accounts_and_comments_table(array $accounts) {
	$account_header = "Account";
	$comment_header = "Key comment";
	$account_column_width = max(iconv_strlen($account_header), ...array_map('iconv_strlen', array_keys($accounts)));
	$comment_column_width = max(iconv_strlen($comment_header), ...array_map('iconv_strlen', array_values($accounts)));

	$row_format = " %-{$account_column_width}s|%-{$comment_column_width}s\n";
	// heading
	$output = sprintf($row_format, $account_header, $comment_header);
	// horizontal line
	$output .= sprintf(" %-'-{$account_column_width}s+%-'-{$comment_column_width}s\n", "", "");
	// data rows
	foreach ($accounts as $account => $comment) {
		$output .= sprintf($row_format, $account, $comment);
	}
	return $output;
}
