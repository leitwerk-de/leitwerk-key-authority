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
?>
<h1>Servers</h1>
<ul class="nav nav-tabs">
	<li><a href="#list" data-toggle="tab">Server list</a></li>
	<li><a href="#add" data-toggle="tab">Add server</a></li>
	<li><a href="#add_bulk" data-toggle="tab">Add multiple servers</a></li>
</ul>

<!-- Tab panes -->
<div class="tab-content">
	<div class="tab-pane fade" id="list">
		<h2 class="sr-only">Server list</h2>
		<div class="panel-group">
			<p><a href="<?php outurl('/servers.json') ?>" class="btn btn-default btn-xs">
				<span class="glyphicon glyphicon-console"></span> JSON
			</a></p>
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title">
						Filter options
					</h3>
				</div>
					<div class="panel-body">
					<form>
						<div class="row">
							<div class="col-sm-4">
								<div class="form-group">
									<label for="hostname-search">Hostname (<a href="https://mariadb.com/kb/en/mariadb/regular-expressions-overview/">regexp</a>)</label>
									<input type="text" id="hostname-search" name="hostname" class="form-control" value="<?php out($this->get('filter')['hostname'])?>" autofocus>
								</div>
								<div class="form-group">
									<label for="ipaddress-search">IP address</label>
									<input type="text" id="ipaddress-search" name="ip_address" class="form-control" value="<?php out($this->get('filter')['ip_address'])?>">
								</div>
							</div>
							<div class="col-sm-3">
								<h4>Key management</h4>
								<?php
								$options = array();
								$options['keys'] = 'Managed by Leitwerk Key Authority';
								$options['other'] = 'Managed by another system';
								$options['none'] = 'Unmanaged';
								$options['decommissioned'] = 'Decommissioned';
								foreach($options as $value => $label) {
									$checked = in_array($value, $this->get('filter')['key_management']) ? ' checked' : '';
								?>
								<div class="checkbox"><label><input type="checkbox" name="key_management[]" value="<?php out($value)?>"<?php out($checked) ?>> <?php out($label) ?></label></div>
								<?php } ?>
							</div>
							<div class="col-sm-2">
								<h4>Sync status</h4>
								<?php
								$options = array();
								$options['sync success'] = 'Sync success';
								$options['sync warning'] = 'Sync warning';
								$options['sync failure'] = 'Sync failure';
								$options['not synced yet'] = 'Not synced yet';
								foreach($options as $value => $label) {
									$checked = in_array($value, $this->get('filter')['sync_status']) ? ' checked' : '';
								?>
								<div class="checkbox"><label><input type="checkbox" name="sync_status[]" value="<?php out($value)?>"<?php out($checked) ?>> <?php out($label) ?></label></div>
								<?php } ?>
							</div>
						</div>
						<button type="submit" class="btn btn-primary">Display results</button>
					</form>
				</div>
			</div>
		</div>
		<?php if($this->get('admin')) { ?>
		<form action="/servers_bulk_action" method="post">
		<?php } ?>
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<p><?php $total = count($this->get('servers')); out(number_format($total).' server'.($total == 1 ? '' : 's').' found')?></p>
			<table class="table table-hover table-condensed">
				<thead>
					<tr>
						<?php if($this->get('admin')) { ?>
						<th><input type="checkbox" id="cb_all_servers"></th>
						<?php } ?>
						<th>Hostname</th>
						<th>Config</th>
						<?php if($this->get('admin')) { ?>
						<th>Leaders</th>
						<?php } ?>
						<th>Status</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach($this->get('servers') as $server) {
						if($server->key_management != 'keys') {
							$syncclass = '';
						} else {
							switch($server->sync_status) {
							case 'not synced yet': $syncclass = 'warning'; break;
							case 'sync failure':   $syncclass = 'danger';  break;
							case 'sync success':   $syncclass = 'success'; break;
							case 'sync warning':   $syncclass = 'warning'; break;
							}
						}
						if($last_sync = $server->get_last_sync_event()) {
							$sync_details = json_decode($last_sync->details)->value;
						} else {
							$sync_details = ucfirst($server->sync_status);
						}
					?>
					<tr>
						<?php if($this->get('admin')) { ?>
						<td>
							<input type="checkbox" name="selected_servers[]" value="<?php out($server->hostname) ?>">
						</td>
						<?php } ?>
						<td>
							<a href="<?php outurl('/servers/'.urlencode($server->hostname)) ?>" class="server"><?php out($server->hostname) ?></a>
							<?php if($server->pending_requests > 0 && $this->get('admin')) { ?>
							<a href="<?php outurl('/servers/'.urlencode($server->hostname)) ?>"><span class="badge" title="Pending requests"><?php out(number_format($server->pending_requests)) ?></span></a>
							<?php } ?>
						</td>
						<td class="nowrap">
							<?php
							switch($server->key_management) {
							case 'keys':
								switch($server->authorization) {
								case 'manual': out('Manual account management'); break;
								case 'automatic LDAP': out('LDAP accounts - automatic'); break;
								case 'manual LDAP': out('LDAP accounts - manual'); break;
								}
								break;
							case 'other': out('Managed by another system'); break;
							case 'none': out('Unmanaged'); break;
							case 'decommissioned': out('Decommissioned'); break;
							}
							?>
						</td>
						<?php if($this->get('admin')) { ?>
						<?php if(is_null($server->admins)) { ?>
						<td<?php if($server->key_management == 'keys') out(' class="danger"', ESC_NONE)?>>Server has no leaders</td>
						<?php } else { ?>
						<td>
							<?php
							$admins = explode(',', $server->admins);
							$admin_list = '';
							foreach($admins as $admin) {
								$type = substr($admin, 0, 1);
								$name = substr($admin, 2);
								if($type == 'G') {
									$admin_list .= '<span class="glyphicon glyphicon-list-alt"></span> ';
								}
								$admin_list .= hesc($name).', ';
							}
							$admin_list = substr($admin_list, 0, -2);
							out($admin_list, ESC_NONE);
							?>
						</td>
						<?php } ?>
						<?php } ?>
						<td class="<?php out($syncclass)?> nowrap"><?php if($server->key_management != 'none') out($sync_details) ?></td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
			<?php if($this->get('admin')) { ?>
			<button type="submit" class="btn btn-primary">Perform a Bulk action on selected servers</button>
		</form>
		<?php } ?>
	</div>
	<div class="tab-pane fade" id="add">
		<h2 class="sr-only">Add server</h2>
		<div class="alert alert-info">
			See <a href="<?php outurl('/help#sync_setup')?>" class="alert-link">the sync setup instructions</a> for how to set up the server for key synchronization.
		</div>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="form-group">
				<label for="hostname">Server hostname</label>
				<input type="text" id="hostname" name="hostname" class="form-control" required>
			</div>
			<div class="form-group">
				<label for="port">SSH port number</label>
				<input type="number" id="port" name="port" class="form-control" value="22" required>
			</div>
			<div class="form-group">
				<label for="jumphosts">Jumphosts (<a href="<?php outurl('/help#jumphost_format')?>">format</a>)</label>
				<input type="text" id="jumphosts" name="jumphosts" pattern="([^@ >]+@[a-zA-Z0-9\-.\u0080-\uffff]+(:[0-9]+)?(,[^@ >]+@[a-zA-Z0-9\-.\u0080-\uffff]+(:[0-9]+)?)*)?( *-> *[a-zA-Z0-9\-.\u0080-\uffff]+)?" class="form-control">
			</div>
			<div class="form-group">
				<label for="server_admin">Leaders</label>
				<input type="text" id="server_admins" name="admins" class="form-control hidden" required>
				<input type="text" id="server_admin" name="admin" class="form-control" placeholder="Type user/group name and press 'Enter' key" list="adminlist" required>
				<datalist id="adminlist">
					<?php foreach($this->get('all_users') as $user) { ?>
					<option value="<?php out($user->uid)?>" label="<?php out($user->name)?>">
					<?php } ?>
					<?php foreach($this->get('all_groups') as $group) { ?>
					<option value="<?php out($group->name)?>" label="<?php out($group->name)?>">
					<?php } ?>
				</datalist>
			</div>
			<div class="form-group">
				<label for="keys_sync_user">User for keys-sync</label>
				<input type="text" id="keys_sync_user" name="keys_sync_user" class="form-control" value="keys-sync" required>
			</div>
			<div class="form-group">
			<label for="default_root_account">Default account creation</label>
			<div>
				<div class="radio">
					<label>
						<input type="radio" id="default_accounts_group" name="default_root_account" value="default_accounts_group">
						Create standard accounts (config default: 'root' added to 'root-accounts' group)
					</label>
				</div>
				<div class="radio">
					<label>
						<input type="radio" id="use_custom_server_account" name="default_root_account" value="use_custom_server_account">
						Create custom server account:
					</label>
					<input type="text" id="custom_server_account" name="custom_server_account" value="root" required>
				</div>
				<div class="radio">
					<label>
						<input type="radio" id="no_accounts" name="default_root_account" value="no_accounts">
						Do not create standard server accounts
					</label>
				</div>
				</div>
			</div>
			<button type="submit" name="add_server" value="1" class="btn btn-primary">Add server to key management</button>
		</form>
	</div>
	<div class="tab-pane fade" id="add_bulk">
		<h2 class="sr-only">Add multiple servers</h2>
		<div class="alert alert-info">
			See <a href="<?php outurl('/help#sync_setup')?>" class="alert-link">the sync setup instructions</a> for how to set up the server for key synchronization.
		</div>
		<h3>Format</h3>
		<p>The csv content must consist of 4 columns and not contain a headline.</p>
		<p>Columns:</p>
		<ol>
			<li>The dns name of the server.</li>
			<li>The port number (optional, if empty 22 is assumed).</li>
			<li>A list of jumphosts (optional, may be empty) see <a href="<?php outurl('/help#jumphost_format')?>">format specification.</a></li>
			<li>A semicolon-separated list of leader login names and leader group names. At least one leader or leader group is needed per server.</li>
			<li>The keys-sync user used for syncing keys to the server. Needs to be an existing user on the server.</li>
		</ol>
		<h4>Example</h4>
		<pre>host1.example.com,,"root@j1.example.com:7022,keys-sync@j2.example.com",leader1
host2.example.com,2222,,leader1;ld_group4
host3.example.com,22,,ld_group4;leader2,keys-sync
host4.example.com,22,,leader1,svc_automation</pre>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="form-group">
				<label for="import">CSV import data</label>
				<textarea id="import" name="import" class="form-control" required></textarea>
			</div>
			<button type="submit" name="add_bulk" value="1" class="btn btn-primary">Add servers to key management</button>
		</form>
	</div>
</div>
