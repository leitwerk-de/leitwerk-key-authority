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
* Class that represents a server
*/
class Server extends Record {
	/**
	* Defines the database table that this object is stored in
	*/
	protected $table = 'server';

	/**
	* Write event details to syslog and to server_event table.
	* @param array $details event paramaters to be logged
	* @param int $level syslog priority as defined in http://php.net/manual/en/function.syslog.php
	*/
	public function log($details, $level = LOG_INFO) {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before log entries can be added');
		$json = json_encode($details, JSON_UNESCAPED_UNICODE);
		$stmt = $this->database->prepare("INSERT INTO server_event SET server_id = ?, actor_id = ?, date = UTC_TIMESTAMP(), details = ?");
		$stmt->bind_param('dds', $this->id, $this->active_user->entity_id, $json);
		$stmt->execute();
		$stmt->close();

		$text = "KeysScope=\"server:{$this->hostname}\" KeysRequester=\"{$this->active_user->uid}\"";
		foreach($details as $key => $value) {
			$text .= ' Keys'.ucfirst($key).'="'.str_replace('"', '', preg_replace('/[\x00-\x1F\x7F]/', '', $value)).'"';
		}
		openlog('keys', LOG_ODELAY, LOG_AUTH);
		syslog($level, $text);
		closelog();
	}

	/**
	* Write property changes to database and log the changes.
	* Triggers a resync if certain settings are changed.
	*/
	public function update() {
		$changes = parent::update();
		$resync = false;
		foreach($changes as $change) {
			switch($change->field) {
			case 'hostname':
			case 'jumphosts':
			case 'key_management':
			case 'authorization':
			case 'custom_keys':
				$resync = true;
				break;
			case 'host_key':
				if(empty($change->new_value)) $resync = true;
				break;
			}
			$this->log(array('action' => 'Setting update', 'value' => $change->new_value, 'oldvalue' => $change->old_value, 'field' => ucfirst(str_replace('_', ' ', $change->field))));
		}
		if($resync) {
			$this->sync_access();
		}
	}

	/**
	* List all log events for this server.
	* @return array of ServerEvent objects
	*/
	public function get_log() {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before log entries can be listed');
		$stmt = $this->database->prepare("
			SELECT *
			FROM server_event
			WHERE server_id = ?
			ORDER BY id DESC
		");
		$stmt->bind_param('d', $this->id);
		$stmt->execute();
		$result = $stmt->get_result();
		$log = array();
		while($row = $result->fetch_assoc()) {
			$log[] = new ServerEvent($row['id'], $row);
		}
		$stmt->close();
		return $log;
	}

	/**
	* List all log events for this server and any accounts on the server.
	* @return array of ServerEvent/ServerAccountEvent objects
	*/
	public function get_log_including_accounts() {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before log entries can be listed');
		$stmt = $this->database->prepare("
			(SELECT se.id, se.actor_id, se.date, se.details, se.server_id, NULL as entity_id, 'server' as type
			FROM server_event se
			WHERE se.server_id = ?
			ORDER BY id DESC)
			UNION
			(SELECT ee.id, ee.actor_id, ee.date, ee.details, NULL as server_id, ee.entity_id, 'server account' as type
			FROM server_account sa
			INNER JOIN entity_event ee ON ee.entity_id = sa.entity_id
			WHERE sa.server_id = ?
			ORDER BY id DESC)
			ORDER BY date DESC, id DESC
		");
		$stmt->bind_param('dd', $this->id, $this->id);
		$stmt->execute();
		$result = $stmt->get_result();
		$log = array();
		while($row = $result->fetch_assoc()) {
			if($row['type'] == 'server') {
				$log[] = new ServerEvent($row['id'], $row);
			} elseif($row['type'] == 'server account') {
				$log[] = new ServerAccountEvent($row['id'], $row);
			}
		}
		$stmt->close();
		return $log;
	}

	/**
	* Get the more recent log event that recorded a change in sync status.
	* @todo In a future change we may want to move the 'action' parameter into its own database field.
	* @return ServerEvent last sync status change event
	*/
	public function get_last_sync_event() {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before log entries can be listed');
		$stmt = $this->database->prepare("SELECT * FROM server_event WHERE server_id = ? AND details LIKE '{\"action\":\"Sync status change\"%' ORDER BY id DESC LIMIT 1");
		$stmt->bind_param('d', $this->id);
		$stmt->execute();
		$result = $stmt->get_result();
		if($row = $result->fetch_assoc()) {
			$event = new ServerEvent($row['id'], $row);
		} else {
			$event = null;
		}
		$stmt->close();
		return $event;
	}

	/**
	* Add the specified user or group as a leader of the server.
	* This action is logged with a warning level as it is increasing an access level.
	* @param Entity $entity user or group to add as leader
	* @param bool $send_mail Specify if an email for this added leader should be created. True by default.
	* @return bool True if the entity has been added as leader, false if it was already a leader.
	*/
	public function add_admin(Entity $entity, bool $send_mail = true): bool {
		global $config;
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before leaders can be added');
		if(is_null($entity->entity_id)) throw new InvalidArgumentException('User or group must be in directory before it can be made leader');
		$entity_id = $entity->entity_id;
		try {
			$url = $config['web']['baseurl'].'/servers/'.urlencode($this->hostname);
			$email = new Email;
			$email->subject = "Leader for {$this->hostname}";
			$email->add_cc($config['email']['report_address'], $config['email']['report_name']);
			switch(get_class($entity)) {
			case 'User':
				$email->add_recipient($entity->email, $entity->name);
				$email->body = "{$this->active_user->name} ({$this->active_user->uid}) has added you as a server leader for {$this->hostname}.  You can manage access to this server from <$url>";
				$logmsg = array('action' => 'Administrator add', 'value' => "user:{$entity->uid}");
				break;
			case 'Group':
				foreach($entity->list_members() as $member) {
					if(get_class($member) == 'User') {
						$email->add_recipient($member->email, $member->name);
					}
				}
				$email->body = "{$this->active_user->name} ({$this->active_user->uid}) has added the {$entity->name} group as server leader for {$this->hostname}.  You are a member of the {$entity->name} group, so you can manage access to this server from <$url>";
				$logmsg = array('action' => 'Administrator add', 'value' => "group:{$entity->name}");
				break;
			default:
				throw new InvalidArgumentException('Entities of type '.get_class($entity).' cannot be added as server leaders');
			}
			$stmt = $this->database->prepare("INSERT INTO server_admin SET server_id = ?, entity_id = ?");
			$stmt->bind_param('dd', $this->id, $entity_id);
			$stmt->execute();
			$stmt->close();
			$this->log($logmsg, LOG_WARNING);
			if ($send_mail) {
				$email->send();
			}
			return true;
		} catch(mysqli_sql_exception $e) {
			if($e->getCode() == 1062) {
				// Duplicate entry
				return false;
			} else {
				throw $e;
			}
		}
	}

	/**
	* Remove the specified user or group as a leader of the server.
	* This action is logged with a warning level as it means the removed user/group will no longer
	* receive notifications for any changes done to this server.
	* @param Entity $entity user or group to remove as leader
	* @return bool True if the user or group has been removed as leader, false if it was not a leader.
	*/
	public function delete_admin(Entity $entity): bool {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before leaders can be deleted');
		if(is_null($entity->entity_id)) throw new InvalidArgumentException('User or group must be in directory before it can be removed as leader');
		$entity_id = $entity->entity_id;
		switch(get_class($entity)) {
		case 'User':
			$this->log(array('action' => 'Administrator remove', 'value' => "user:{$entity->uid}"), LOG_WARNING);
			break;
		case 'Group':
			$this->log(array('action' => 'Administrator remove', 'value' => "group:{$entity->name}"), LOG_WARNING);
			break;
		default:
			throw new InvalidArgumentException('Entities of type '.get_class($entity).' should not exist as server leaders');
		}
		$stmt = $this->database->prepare("DELETE FROM server_admin WHERE server_id = ? AND entity_id = ?");
		$stmt->bind_param('dd', $this->id, $entity_id);
		$stmt->execute();
		$removed = $stmt->affected_rows == 1;
		$stmt->close();
		return $removed;
	}

	/**
	* List all leaders of this server.
	* @return array of User/Group objects
	*/
	public function list_admins() {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before leaders can be listed');
		$stmt = $this->database->prepare("SELECT entity_id, type FROM server_admin INNER JOIN entity ON entity.id = server_admin.entity_id WHERE server_id = ?");
		$stmt->bind_param('d', $this->id);
		$stmt->execute();
		$result = $stmt->get_result();
		$admins = array();
		while($row = $result->fetch_assoc()) {
			if(strtolower($row['type']) == "user") {
				$admins[] = new User($row['entity_id']);
			} elseif(strtolower($row['type']) == "group") {
				$admins[] = new Group($row['entity_id']);
			}
		}
		$stmt->close();
		return $admins;
	}

	/**
	* Return the list of all users who can manage this server, including
	* via group membership of a group that has been made leader.
	* @return array of User objects
	*/
	public function list_effective_admins() {
		$admins = $this->list_admins();
		$e_admins = array();
		foreach($admins as $admin) {
			switch(get_class($admin)) {
			case 'Group':
				if($admin->active) {
					$members = $admin->list_members();
					foreach($members as $member) {
						if(get_class($member) == 'User') {
							$e_admins[] = $member;
						}
					}
				}
				break;
			case 'User':
				$e_admins[] = $admin;
				break;
			}
		}
		return $e_admins;
	}

	/**
	* Create any standard accounts that should exist on every server, and add them to the related
	* groups.
	* @param string $default_root_accounts to define standard account creation behaviour
	*/
	public function add_standard_accounts(string $default_root_accounts): void {
		global $group_dir, $config;

		if($default_root_accounts == 'none') return;

		if($default_root_accounts == 'default') {
			if(!isset($config['defaults']['account_groups'])) return;

			foreach($config['defaults']['account_groups'] as $account_name => $group_name) {
				$account = new ServerAccount;
				$account->name = $account_name;
				$this->add_account($account);

				try {
					$group = $group_dir->get_group_by_name($group_name);
				} catch(GroupNotFoundException $e) {
					$group = new Group;
					$group->name = $group_name;
					$group->system = 1;
					$group_dir->add_group($group);
				}
				// Enforce privilege, so that a non-admin can add the default accounts
				// to the relevant groups.
				$group->add_member($account, null, true);
			}
		}

		else {
			$account = new ServerAccount;
			$account->name = $default_root_accounts;
			$this->add_account($account);

			if(!isset($config['defaults']['account_groups'])) return;

			$default_groups = $config['defaults']['account_groups'];
			reset($default_groups);
			$first_group_name = current($default_groups) ?: '';

			try {
				$group = $group_dir->get_group_by_name($first_group_name);
			} catch(GroupNotFoundException $e) {
				$group = new Group;
				$group->name = $first_group_name;
				$group->system = 1;
				$group_dir->add_group($group);
			}
			// Enforce privilege, so that a non-admin can add the default accounts
			// to the relevant groups.
			$group->add_member($account, null, true);
		}
	}

	/**
	* Create a new account on the server.
	* Reactivates an existing account if one exists with the same name.
	* @param ServerAccount $account to be added
	* @throws AccountNameInvalid if account name is empty
	*/
	public function add_account(ServerAccount &$account) {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before accounts can be added');
		$account_name = $account->name;
		if($account_name === '') throw new AccountNameInvalid('Account name cannot be empty');
		if(substr($account_name, 0, 1) === '.') throw new AccountNameInvalid('Account name cannot begin with .');
		$sync_status = is_null($account->sync_status) ? 'not synced yet' : $account->sync_status;
		$this->database->begin_transaction();
		$stmt = $this->database->prepare("INSERT INTO entity SET type = 'server account'");
		$stmt->execute();
		$account->entity_id = $stmt->insert_id;
		$stmt->close();
		$stmt = $this->database->prepare("INSERT INTO server_account SET entity_id = ?, server_id = ?, name = ?, sync_status = ?");
		$stmt->bind_param('ddss', $account->entity_id, $this->id, $account_name, $sync_status);
		try {
			$stmt->execute();
			$stmt->close();
			$this->database->commit();
			$this->log(array('action' => 'Account add', 'value' => $account_name));
		} catch(mysqli_sql_exception $e) {
			$this->database->rollback();
			if($e->getCode() == 1062) {
				// Duplicate entry
				$account = $this->get_account_by_name($account_name);
				$account->active = 1;
				$account->update();
			} else {
				throw $e;
			}
		}
	}

	/**
	* Get a server account from the database by its name.
	* @param string $name of account
	* @return ServerAccount with specified name
	* @throws ServerAccountNotFoundException if no account with that name exists
	*/
	public function get_account_by_name($name) {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before accounts can be listed');
		$stmt = $this->database->prepare("SELECT entity_id, name FROM server_account WHERE server_id = ? AND name = ?");
		$stmt->bind_param('ds', $this->id, $name);
		$stmt->execute();
		$result = $stmt->get_result();
		if($row = $result->fetch_assoc()) {
			$account = new ServerAccount($row['entity_id'], $row);
		} else {
			throw new ServerAccountNotFoundException('Account does not exist.');
		}
		$stmt->close();
		return $account;
	}

	/**
	* List accounts stored for this server.
	* @param array $include list of extra data to include in response - currently unused
	* @param array $filter list of field/value pairs to filter results on
	* @return array of ServerAccount objects
	*/
	public function list_accounts($include = array(), $filter = array()) {
		// WARNING: The search query is not parameterized - be sure to properly escape all input
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before accounts can be listed');
		$where = array('server_id = '.intval($this->id), 'active = 1');
		$joins = array("LEFT JOIN access_request ON access_request.dest_entity_id = server_account.entity_id");
		foreach($filter as $field => $value) {
			if($value) {
				switch($field) {
				case 'admin':
					$where[] = "admin_filter.admin = ".intval($value);
					$joins['adminfilter'] = "INNER JOIN entity_admin admin_filter ON admin_filter.entity_id = server_account.entity_id";
					break;
				}
			}
		}
		$stmt = $this->database->prepare("
			SELECT server_account.entity_id, name,
			COUNT(DISTINCT access_request.source_entity_id) AS pending_requests
			FROM server_account
			".implode("\n", $joins)."
			WHERE (".implode(") AND (", $where).")
			GROUP BY server_account.entity_id
			ORDER BY name
		");
		$stmt->execute();
		$result = $stmt->get_result();
		$accounts = array();
		while($row = $result->fetch_assoc()) {
			$accounts[] = new ServerAccount($row['entity_id'], $row);
		}
		$stmt->close();
		return $accounts;
	}

	/**
	* Add an access option that should be applied to all LDAP accounts on the server.
	* Access options include "command", "from", "no-port-forwarding" etc.
	* @param ServerLDAPAccessOption $option to be added
	*/
	public function add_ldap_access_option(ServerLDAPAccessOption $option) {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before LDAP access options can be added');
		$stmt = $this->database->prepare("INSERT INTO server_ldap_access_option SET server_id = ?, `option` = ?, value = ?");
		$stmt->bind_param('dss', $this->id, $option->option, $option->value);
		$stmt->execute();
		$stmt->close();
	}

	/**
	* Remove an access option from all LDAP accounts on the server.
	* Access options include "command", "from", "no-port-forwarding" etc.
	* @param ServerLDAPAccessOption $option to be removed
	*/
	public function delete_ldap_access_option(ServerLDAPAccessOption $option) {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before LDAP access options can be deleted');
		$stmt = $this->database->prepare("DELETE FROM server_ldap_access_option WHERE server_id = ? AND `option` = ?");
		$stmt->bind_param('ds', $this->id, $option->option);
		$stmt->execute();
		$stmt->close();
	}

	/**
	* Replace the current list of LDAP access options with the provided array of options.
	* This is a crude implementation - just deletes all existing options and adds new ones, with
	* table locking for a small measure of safety.
	* @param array $options array of ServerLDAPAccessOption objects
	*/
	public function update_ldap_access_options(array $options) {
		$stmt = $this->database->query("LOCK TABLES server_ldap_access_option WRITE");
		$oldoptions = $this->list_ldap_access_options();
		foreach($oldoptions as $oldoption) {
			$this->delete_ldap_access_option($oldoption);
		}
		foreach($options as $option) {
			$this->add_ldap_access_option($option);
		}
		$stmt = $this->database->query("UNLOCK TABLES");
		$this->sync_access();
	}

	/**
	* List all current LDAP access options applied to the server.
	* @return array of ServerLDAPAccessOption objects
	*/
	public function list_ldap_access_options() {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before LDAP access options can be listed');
		$stmt = $this->database->prepare("
			SELECT *
			FROM server_ldap_access_option
			WHERE server_id = ?
			ORDER BY `option`
		");
		$stmt->bind_param('d', $this->id);
		$stmt->execute();
		$result = $stmt->get_result();
		$options = array();
		while($row = $result->fetch_assoc()) {
			$options[$row['option']] = new ServerLDAPAccessOption($row['option'], $row);
		}
		$stmt->close();
		return $options;
	}

	/**
	* Update the sync status for the server and write a log message if the status details have changed.
	* @param string $status "sync success", "sync failure" or "sync warning"
	* @param string $logmsg details of the sync attempt's success or failure
	*/
	public function sync_report($status, $logmsg) {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before sync reporting can be done');
		$prevlogmsg = $this->get_last_sync_event();
		if(is_null($prevlogmsg) || $logmsg != json_decode($prevlogmsg->details)->value) {
			$logmsg = array('action' => 'Sync status change', 'value' => $logmsg);
			$this->log($logmsg);
		}
		$this->sync_status = $status;
		$this->update();
	}

	/**
	* Add a note to the server. The note is a piece of text with metadata (who added it and when).
	* @param ServerNote $note to be added
	*/
	public function add_note(ServerNote $note) {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before notes can be added');
		$entity_id = $note->user->entity_id;
		$stmt = $this->database->prepare("INSERT INTO server_note SET server_id = ?, entity_id = ?, date = UTC_TIMESTAMP(), note = ?");
		$stmt->bind_param('dds', $this->id, $entity_id, $note->note);
		$stmt->execute();
		$stmt->close();
	}


	/**
	* Delete the specified note from the server.
	* @param ServerNote $note to be deleted
	*/
	public function delete_note(ServerNote $note) {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before notes can be deleted');
		$stmt = $this->database->prepare("DELETE FROM server_note WHERE server_id = ? AND id = ?");
		$stmt->bind_param('dd', $this->id, $note->id);
		$stmt->execute();
		$stmt->close();
	}

	/**
	* Retrieve a specific note for this server by its ID.
	* @param int $id of note to retrieve
	* @return ServerNote matching the ID
	* @throws ServerNoteNotFoundException if no note exists with that ID
	*/
	public function get_note_by_id($id) {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before notes can be listed');
		$stmt = $this->database->prepare("SELECT * FROM server_note WHERE server_id = ? AND id = ? ORDER BY id");
		$stmt->bind_param('dd', $this->id, $id);
		$stmt->execute();
		$result = $stmt->get_result();
		if($row = $result->fetch_assoc()) {
			$note = new ServerNote($row['id'], $row);
		} else {
			throw new ServerNoteNotFoundException('Note does not exist.');
		}
		$stmt->close();
		return $note;
	}

	/**
	* List all notes associated with this server.
	* @return array of ServerNote objects
	*/
	public function list_notes() {
		if(is_null($this->id)) throw new BadMethodCallException('Server must be in directory before notes can be listed');
		$stmt = $this->database->prepare("SELECT * FROM server_note WHERE server_id = ? ORDER BY id");
		$stmt->bind_param('d', $this->id);
		$stmt->execute();
		$result = $stmt->get_result();
		$notes = array();
		while($row = $result->fetch_assoc()) {
			$notes[] = new ServerNote($row['id'], $row);
		}
		$stmt->close();
		return $notes;
	}

	/**
	 * Open an ssh connection using the adapter class SSH and return the connection instance.
	 * This function also performs ip address and host key collision checks.
	 * If turned on in settings, it also does hostname verification.
	 *
	 * @return SSH The connection instance
	 * @throws SSHException If the connection fails for some reason
	 */
	public function connect_ssh(): SSH {
		global $config, $server_dir;

		$this->ip_address = gethostbyname($this->hostname);
		$this->update();

		// IP address check
		$matching_servers = $server_dir->list_servers(array(), array(
			'ip_address' => $this->ip_address,
			'port' => $this->port,
			'key_management' => array('keys'),
			'jumphosts' => $this->jumphosts)
		);
		if(count($matching_servers) > 1) {
			throw new SSHException("Multiple hosts with same IP address");
		}

		$parsed_jumphosts = $this->parse_jumphosts();
		$host_alias = $parsed_jumphosts["host_alias"];
		$jumphosts = $parsed_jumphosts["jumphosts"];
		$connection = SSH::connect_with_pubkey(
			$host_alias ?? $this->hostname,
			$this->port,
			$jumphosts,
			$this->keys_sync_user,
			'config/keys-sync.pub',
			'config/keys-sync',
			$this->host_key
		);
		$this->update(); // fingerprint might have changed

		// Check for host key collisions
		if($this->host_key != "" && (!isset($config['security']) || !isset($config['security']['host_key_collision_protection']) || $config['security']['host_key_collision_protection'] == 1)) {
			$matching_servers = $server_dir->list_servers(array(), array('host_key' => $this->host_key, 'key_management' => array('keys')));
			if(count($matching_servers) > 1) {
				throw new SSHException("Multiple hosts with same host key.");
			}
		}

		// hostname verification
		if(isset($config['security']) && isset($config['security']['hostname_verification']) && $config['security']['hostname_verification'] >= 1) {
			// Verify that we have mutual agreement with the server that we sync to it with this hostname
			$allowed_hostnames = null;
			if($config['security']['hostname_verification'] >= 2) {
				// 2+ = Compare with /var/local/keys-sync/.hostnames
				try {
					$allowed_hostnames = array_map('trim', $connection->file_get_lines("/var/local/keys-sync/.hostnames"));
				} catch(SSHException $e) {
					if($config['security']['hostname_verification'] >= 3) {
						// 3+ = Abort if file does not exist
						throw new SSHException("Could not read /var/local/keys-sync/.hostnames", 0, $e);
					} else {
						$allowed_hostnames = null;
					}
				}
			}
			if(is_null($allowed_hostnames)) {
				// differentiate hostname checking based on os
				$server_identifier = $connection->getServerIdentifier();
				if (stripos($server_identifier, "win") !== false) {
					$hostname_command = ('[System.Net.Dns]::GetHostByName($env:COMPUTERNAME).HostName');
				}
				else {
					$hostname_command = ('/bin/hostname -f');
				}
				$output = $connection->exec($hostname_command);
				$allowed_hostnames = array(trim($output));
			}
			if(!in_array($this->hostname, $allowed_hostnames)) {
				throw new SSHException("Hostname check failed (allowed: ".implode(", ", $allowed_hostnames).").");
			}
		}

		return $connection;
	}

	/**
	* Trigger a sync for all accounts on this server.
	*/
	public function sync_access() {
		global $sync_request_dir;
		$sync_request = new SyncRequest;
		$sync_request->server_id = $this->id;
		$sync_request->account_name = null;
		$sync_request_dir->add_sync_request($sync_request);
	}

	/**
	* List all current pending sync requests for this server. (No scheduled requests in future)
	* @return array of SyncRequest objects
	*/
	public function list_sync_requests() {
		$stmt = $this->database->prepare(
			"SELECT * FROM sync_request
			WHERE server_id = ?
			AND (execution_time IS NULL OR execution_time <= ?)
			ORDER BY account_name"
				);
		$curdate = date("Y-m-d H:i:s");
		$stmt->bind_param('ds', $this->id, $curdate);
		$stmt->execute();
		$result = $stmt->get_result();
		$reqs = array();
		while($row = $result->fetch_assoc()) {
			$reqs[] = new SyncRequest($row['id'], $row);
		}
		return $reqs;
	}

	/**
	* Delete all pending sync requests for this server.
	*/
	public function delete_all_sync_requests() {
		$stmt = $this->database->prepare("DELETE FROM sync_request WHERE server_id = ?");
		$stmt->bind_param('d', $this->id);
		$stmt->execute();
	}

	/**
	 * Delete sync requests for this server and schedule a new request in 30 minutes
	 */
	public function reschedule_sync_request() {
		global $sync_request_dir;

		$this->delete_all_sync_requests();
		$req = new SyncRequest();
		$req->server_id = $this->id;
		$req->execution_time = date("Y-m-d H:i:s", time() + 30 * 60);
		$sync_request_dir->add_sync_request($req);
	}

	/**
	 * Places status information in the file given by $config['monitoring']['status_file_path'] on this server.
	 * The current sync status and the result of external key supervision are included in this file.
	 *
	 * @param SSH $connection The ssh connection instance to this server
	 */
	public function update_status_file(SSH $connection) {
		global $config;
		$timeout = (int)($config['monitoring']['status_file_timeout'] ?? 7200);
		$expire = date('r', time() + $timeout);
		$lastlogmsg = $this->get_last_sync_event();
		if ($lastlogmsg !== null) {
			$sync_status_message = json_decode($lastlogmsg->details)->value;
		} else {
			$sync_status_message = null;
		}
		$unnoticed_keys = $this->get_unnoticed_external_keys();
		$accounts_with_unnoticed_keys = [];
		foreach ($unnoticed_keys as $key) {
			$account = $key->account_name;
			if (!in_array($account, $accounts_with_unnoticed_keys)) {
				$accounts_with_unnoticed_keys[] = $account;
			}
		}
		$status_content = [
			"warn_below_version" => 1,
			"sync_status" => $this->sync_status,
			"sync_status_message" => $sync_status_message,
			"key_supervision_error" => $this->key_supervision_error,
			"accounts_with_unnoticed_keys" => $accounts_with_unnoticed_keys,
			"expire" => $expire,
		];

		$server_identifier = $connection->getServerIdentifier();
		if (stripos($server_identifier, "win") !== false) {
			$filename = $config['monitoring']['windows_status_file_path'] ?? '/ProgramData/ssh/keys-sync.status';
		}
		else {
			$filename = $config['monitoring']['linux_status_file_path'] ?? '/var/local/keys-sync.status';
		}
		$file_content = json_encode($status_content);

		try {
			$connection->file_put_contents($filename, $file_content);
		} catch (SSHException $e) {
			$this->key_supervision_error .= "Could not save status info in {$filename}: {$e->getMessage()}\n";
			$this->update();
		}
	}

	/**
	 * Search for external keys that fulfill all of the following criteria:
	 * - Appeared on this server at least 96 hours ago
	 * - Are still on the server (have been seen at last scan)
	 * - Are in status 'new' (It has not been decided yet if key is 'allowed' or 'denied')
	 *
	 * @return ExternalKeyOccurrence[] The key occurrences that fulfill the criteria
	 */
	public function get_unnoticed_external_keys() {
		$stmt = $this->database->prepare("
			SELECT external_key_occurrence.* FROM external_key_occurrence
			LEFT JOIN external_key on external_key_occurrence.key = external_key.id
			WHERE external_key_occurrence.server = ?
			AND external_key_occurrence.appeared <= date_sub(now(), interval 96 hour)
			AND external_key.status = 'new'
		");
		$stmt->bind_param("i", $this->id);
		$stmt->execute();
		$result = $stmt->get_result();
		$unnoticed = [];
		while ($row = $result->fetch_assoc()) {
			$attributes = [
				'key' => $row['key'],
				'server' => $row['server'],
				'account_name' => $row['account_name'],
				'comment' => $row['comment'],
				'appeared' => $row['appeared'],
			];
			$unnoticed[] = new ExternalKeyOccurrence($row['id'], $attributes);
		}
		return $unnoticed;
	}

	/**
	 * Check if a given hostname string is syntactically correct.
	 *
	 * @param string $hostname The hostname to check
	 * @return bool True if the hostname looks correct, false if not
	 */
	public static function hostname_valid(string $hostname): bool {
		return preg_match("|^[a-zA-Z0-9\\-._\x80-\xff]+\$|", $hostname);
	}

	/**
	 * Check if a given jumphosts string is syntactically correct.
	 *
	 * @param string $jumphosts The string naming all jumphosts
	 * @return bool True if the string looks correct, false if not
	 */
	public static function jumphosts_valid(string $jumphosts): bool {
		$one_jumphost_regex = "[^@]+@[a-zA-Z0-9\\-.\x80-\xff]+(:[0-9]+)?";
		return preg_match("|^($one_jumphost_regex(,$one_jumphost_regex)*)?( *-> *[a-zA-Z0-9\\-.\x80-\xff]+)?\$|", $jumphosts);
	}

	/**
	 * Parse the jumphosts string of this object and return an array of jumphosts, where
	 * each element contains "user", "host", "port".
	 *
	 * @return array Contains one entry per jumphost. Empty array, if there are no jumphosts.
	 */
	public function parse_jumphosts(): array {
		$jumphosts = $this->jumphosts;
		if (preg_match("|^([^ >]*) *-> *([a-zA-Z0-9\\-.\x80-\xff]+)\$|", $jumphosts, $matches)) {
			$jumphosts = $matches[1];
			$host_alias = $matches[2];
		} else {
			$host_alias = null;
		}
		if ($jumphosts == "") {
			$jumphost_list = [];
		} else {
			$parts = explode(",", $jumphosts);
			$jumphost_list = array_map(function($part) {
				preg_match("|^([^@]+)@([a-zA-Z0-9\\-.\x80-\xff]+)(:([0-9]+))?\$|", $part, $matches);
				$port = $matches[4] ?? "22";
				if ($port == "") {
					$port = 22;
				}
				return [
					"user" => $matches[1],
					"host" => $matches[2],
					"port" => (int)$port,
				];
			}, $parts);
		}
		return [
			"host_alias" => $host_alias,
			"jumphosts" => $jumphost_list,
		];
	}
}

class ServerNoteNotFoundException extends Exception {}
class AccountNameInvalid extends InvalidArgumentException {}