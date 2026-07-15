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

function show_key(ExternalKey $key, array $buttons, string $relative_request_url, string $csrf_field) {
	?>
	<div class="panel panel-default">
		<dl class="panel-body">
			<dt>Key data</dt>
			<dd><pre><?php out($key->type) ?> <?php out($key->keydata) ?></pre></dd>
			<dt>Occurrences</dt>
			<dd>
				<table class="table">
					<thead>
						<tr>
							<th>Location</th>
							<th>Key comment</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($key->occurrence as $occurrence) { ?>
						<tr>
							<td><?php out($occurrence->account_name) ?>@<?php out($occurrence->hostname) ?></td>
							<td><?php out($occurrence->comment) ?></td>
						</tr>
						<?php } ?>
					</tbody>
				</table>
			</dd>
			<dt>Actions</dt>
			<dd>
				<form method="post" action="<?php outurl($relative_request_url)?>">
					<?php out($csrf_field, ESC_NONE) ?>
					<?php if (in_array('allow', $buttons)) { ?>
					<button type="submit" name="allow" value="<?php out($key->id) ?>" class="btn btn-default btn-xs">Allow</button>
					<?php } ?>
					<?php if (in_array('deny', $buttons)) { ?>
					<button type="submit" name="deny" value="<?php out($key->id) ?>" class="btn btn-default btn-xs">Deny</button>
					<?php } ?>
				</form>
			</dd>
		</dl>
	</div>
	<?php
}

?>
<h1>Public keys</h1>
<ul class="nav nav-tabs">
	<li><a href="#managed" data-toggle="tab">Managed keys</a></li>
	<li><a href="#new" data-toggle="tab">New keys</a></li>
	<li><a href="#allowed" data-toggle="tab">Allowed keys</a></li>
	<li><a href="#denied" data-toggle="tab">Denied keys</a></li>
</ul>
<div class="tab-content">
	<div class="tab-pane fade in active" id="managed">
		<h2 class="sr-only">Managed keys</h2>
		<p><a href="<?php outurl('/pubkeys.json') ?>" class="btn btn-default btn-xs">
			<span class="glyphicon glyphicon-console"></span> JSON
		</a></p>
		<div class="panel-group">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title">Filter options</h3>
				</div>
				<div id="search_filter">
					<div class="panel-body">
						<form>
							<div class="row">
								<div class="col-md-6 form-group">
									<label for="fingerprint-search">Fingerprint</label>
									<input type="text" id="fingerprint-search" name="fingerprint" class="form-control" value="<?php out($this->get('filter')['fingerprint'])?>">
								</div>
								<div class="col-md-2 form-group">
									<label for="type-search">Key type</label>
									<input type="text" id="type-search" name="type" class="form-control" value="<?php out($this->get('filter')['type'])?>">
								</div>
								<div class="col-md-2 form-group">
									<label for="keysize-min">Min key size</label>
									<div class="input-group">
										<span class="input-group-addon">≥</span>
										<input type="text" id="keysize-min" name="keysize-min" class="form-control" value="<?php out($this->get('filter')['keysize-min'])?>">
									</div>
								</div>
								<div class="col-md-2 form-group">
									<label for="keysize-max">Max key size</label>
									<div class="input-group">
										<span class="input-group-addon">≤</span>
										<input type="text" id="keysize-max" name="keysize-max" class="form-control" value="<?php out($this->get('filter')['keysize-max'])?>">
									</div>
								</div>
							</div>
							<button type="submit" class="btn btn-primary">Display results</button>
						</form>
					</div>
				</div>
			</div>
		</div>
		<p><?php $total = count($this->get('pubkeys')); out(number_format($total).' public key'.($total == 1 ? '' : 's').' found'); ?></p>
		<table class="table table-striped">
			<thead>
				<tr>
					<th class="fingerprint">Fingerprint</th>
					<th>Creation Date</th>
					<th>Deletion Date</th>
					<th>Type</th>
					<th>Size</th>
					<th>Comment</th>
					<th>Owner</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach($this->get('pubkeys') as $pubkey) {
				?>
				<tr<?php if ($pubkey->deletion_date !== null) { out(' class="deleted"', ESC_NONE); } ?>>
					<td>
						<a href="<?php outurl('/pubkeys/'.urlencode($pubkey->id))?>">
							<span class="fingerprint_md5"><?php out($pubkey->fingerprint_md5)?></span>
							<span class="fingerprint_sha256"><?php out($pubkey->fingerprint_sha256)?></span>
						</a>
					</td>
					<td><?php out($pubkey->format_creation_date()) ?></td>
					<td><?php out($pubkey->format_deletion_date()) ?></td>
					<td class="nowrap"><?php out($pubkey->type)?></td>
					<td<?php if($pubkey->keysize < 4095) out(' class="danger"', ESC_NONE)?>><?php out($pubkey->keysize)?></td>
					<td><?php out($pubkey->comment)?></td>
					<td>
						<?php
						switch(get_class($pubkey->owner)) {
						case 'User':
						?>
						<a href="<?php outurl('/users/'.urlencode($pubkey->owner->uid))?>" class="user"><?php out($pubkey->owner->uid)?></a>
						<?php if(!$pubkey->owner->active) out(' <span class="label label-default">Inactive</span>', ESC_NONE) ?>
						<?php
							break;
						case 'ServerAccount':
						?>
						<a href="<?php outurl('/servers/'.urlencode($pubkey->owner->server->hostname))?>/accounts/<?php out($pubkey->owner->name, ESC_URL)?>" class="serveraccount"><?php out($pubkey->owner->name.'@'.$pubkey->owner->server->hostname)?></a>
						<?php if($pubkey->owner->server->key_management == 'decommissioned') out(' <span class="label label-default">Inactive</span>', ESC_NONE) ?>
						<?php
							break;
						}
						?>
					</td>
					<td>
						<?php if ($pubkey->deletion_date !== null) { ?>
							<i class="glyphicon glyphicon-remove"></i> Deleted
						<?php } ?>
					</td>
				</tr>
				<?php
				}
				?>
			</tbody>
		</table>
	</div>
	<div class="tab-pane fade" id="new">
		<h2 class="sr-only">New keys</h2>
		<?php
		if (empty($this->get('new_keys'))) {
			?><p>There are currently no new, unknown keys.</p><?php
		} else {
			foreach ($this->get('new_keys') as $newkey) {
				show_key($newkey, $this->get('admin') ? ['allow', 'deny'] : [], $this->data->relative_request_url, $this->get('active_user')->get_csrf_field());
			}
		}
		?>
		<h2>Errors while scanning for new keys</h2>
		<?php
		if (empty($this->get('supervise_errors'))) {
			?><p>There were no errors during the scan for new, unknown keys.</p><?php
		} else {
			?><p>The following errors occurred while scanning for new, unknown keys:</p><?php
			foreach ($this->get('supervise_errors') as $server) { ?>
			<h4><?php out($server->hostname) ?></h4>
			<pre><?php out($server->key_supervision_error) ?></pre>
			<?php }
		}
		?>
	</div>
	<div class="tab-pane fade" id="allowed">
		<h2 class="sr-only">Allowed keys</h2>
		<?php
		if (empty($this->get('allowed_keys'))) {
			?><p>There are currently no explicitly allowed keys.</p><?php
		} else {
			foreach ($this->get('allowed_keys') as $newkey) {
				show_key($newkey, $this->get('admin') ? ['deny'] : [], $this->data->relative_request_url, $this->get('active_user')->get_csrf_field());
			}
		}
		?>
	</div>
	<div class="tab-pane fade" id="denied">
		<h2 class="sr-only">Denied keys</h2>
		<?php
		if (empty($this->get('denied_keys'))) {
			?><p>There are currently no explicitly denied keys.</p><?php
		} else {
			foreach ($this->get('denied_keys') as $newkey) {
				show_key($newkey, $this->get('admin') ? ['allow'] : [], $this->data->relative_request_url, $this->get('active_user')->get_csrf_field());
			}
		}
		?>
	</div>
</div>
