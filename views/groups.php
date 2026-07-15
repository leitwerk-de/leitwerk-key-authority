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

if(isset($_POST['add_group']) && ($active_user->admin)) {
	// create a local group
	$name = trim($_POST['name']);
	if(preg_match('|/|', $name)) {
		$content = new PageSection('invalid_group_name');
		$content->set('group_name', $name);
	} else {
		try {
			$new_admin = $user_dir->get_user_by_uid(trim($_POST['admin_uid']));
		} catch(UserNotFoundException $e) {
			$content = new PageSection('user_not_found');
		}
		if(isset($new_admin)) {
			$group = new Group;
			$group->name = $name;
			try {
				$group_dir->add_group($group);
				$group->add_admin($new_admin);
				$alert = new UserAlert;
				$alert->content = 'Group \'<a href="'.rrurl('/groups/'.urlencode($name)).'" class="alert-link">'.hesc($name).'</a>\' successfully created.';
				$alert->escaping = ESC_NONE;
				$active_user->add_alert($alert);
			} catch(GroupAlreadyExistsException $e) {
				$alert = new UserAlert;
				$alert->content = 'Group \'<a href="'.rrurl('/groups/'.urlencode($name)).'" class="alert-link">'.hesc($name).'</a>\' already exists.';
				$alert->escaping = ESC_NONE;
				$alert->class = 'danger';
				$active_user->add_alert($alert);
			}
			redirect('#add');
		}
	}
} else if (isset($_POST['add_ldap_group']) && $active_user->admin) {
	if (isset($_POST['groups'])) {
		$group_guids = $_POST['groups'];
	} else {
		$group_guids = [];
	}
	$added = [];
	$already_existing = [];
	$not_found = 0;
	foreach ($group_guids as $group_guid) {
		$result = $ldap->search($config['ldap']['dn_group'], LDAP::escape(strtolower($config['ldap']['group_num'])).'='.LDAP::query_encode_guid($group_guid), ['cn']);
		if (!empty($result)) {
			try {
				$group = new Group;
				$group->name = $result[0]['cn'];
				$group->system = 1;
				$group->ldap_guid = $group_guid;
				$group_dir->add_group($group);

				$added[] = $group->name;
			} catch (GroupAlreadyExistsException $e) {
				$already_existing[] = $group->name;
			}
		} else {
			$not_found++;
		}
	}
	if (!empty($added)) {
		$success_alert = new UserAlert;
		$html_added = array_map(function ($name) {
			return '<a href="'.rrurl('/groups/'.urlencode($name)).'" class="alert-link">'.hesc($name).'</a>';
		}, $added);
		$list = "<ul><li>" . implode("</li><li>", $html_added) . "</li></ul>";
		if (count($added) == 1) {
			$success_alert->content = "The following group has been added:$list";
		} else {
			$success_alert->content = "The following groups have been added:$list";
		}
		$success_alert->escaping = ESC_NONE;
		$active_user->add_alert($success_alert);
	}
	$error_alert = new UserAlert;
	$content = "";
	if (!empty($already_existing)) {
		$html_existing = array_map(function ($name) {
			return '<a href="'.rrurl('/groups/'.urlencode($name)).'" class="alert-link">'.hesc($name).'</a>';
		}, $already_existing);
		$list = "<ul><li>" . implode("</li><li>", $html_existing) . "</li></ul>";
		if (count($already_existing) == 1) {
			$content .= "The following group already exists:$list";
		} else {
			$content .= "The following groups already exist:$list";
		}
	}
	if ($not_found > 0) {
		if ($not_found == 1) {
			$content .= "1 group could not be found on the ldap server";
		} else {
			$content .= "$not_found groups could not be found on the ldap server";
		}
	}
	if (empty($group_guids)) {
		$content = "No group has been selected";
	}
	if ($content != "") {
		$error_alert->content = $content;
		$error_alert->class = 'danger';
		$error_alert->escaping = ESC_NONE;
		$active_user->add_alert($error_alert);
	}

	redirect('#add');
} else if (isset($_GET['get_ldap_groups']) && $active_user->admin) {
	$guid = $_GET['guid'];
	$return_list = [];
	if ($guid == "null") {
		// Get the main organization unit that contains all groups
		$main = $ldap->search($config['ldap']['dn_group'], 'DistinguishedName='.$ldap->escape($config['ldap']['dn_group']), ['name', 'objectclass', 'objectguid']);
		if (!empty($main) && in_array('organizationalUnit', $main[0]['objectclass'])) {
			$return_list[] = [
				"type" => "ou",
				"name" => $main[0]['name'],
				"guid" => $main[0][strtolower($config['ldap']['group_num'])],
			];
		}
	} else {
		$ou = $ldap->search($config['ldap']['dn_group'], LDAP::escape(strtolower($config['ldap']['group_num'])).'='.$ldap->query_encode_guid($guid), ['dn']);
		if (!empty($ou)) {
			$elements = $ldap->search($ou[0]['dn'], '(objectClass=*)', ['objectclass', 'name', strtolower($config['ldap']['group_num'])], [], true);
			foreach ($elements as $element) {
				if (in_array('organizationalUnit', $element['objectclass'])) {
					$return_list[] = [
						"type" => "ou",
						"name" => $element['name'],
						"guid" => $element[strtolower($config['ldap']['group_num'])],
					];
				} else if (in_array($config['ldap']['group_class'], $element['objectclass'])) {
					$return_list[] = [
						"type" => "group",
						"name" => $element['name'],
						"guid" => $element[strtolower($config['ldap']['group_num'])],
					];
				}
			}
		}
	}
	$page = new PageSection('groups_list_ldap');
	$page->set('groups', $return_list);
	header('Content-type: text/json; charset=utf-8');
	echo $page->generate();
	return;
} else {
	$defaults = array();
	$defaults['active'] = array('1');
	$defaults['name'] = '';
	$filter = simplify_search($defaults, $_GET);
	try {
		$groups = $group_dir->list_groups(array('admins', 'members'), $filter);
	} catch(GroupSearchInvalidRegexpException $e) {
		$groups = array();
		$alert = new UserAlert;
		$alert->content = "The group name search pattern '".$filter['hostname']."' is invalid.";
		$alert->class = 'danger';
		$active_user->add_alert($alert);
	}
	$content = new PageSection('groups');
	$content->set('filter', $filter);
	$content->set('admin', $active_user->admin);
	$content->set('groups', $groups);
	$content->set('all_users', $user_dir->list_users());
}

$page = new PageSection('base');
$page->set('title', 'Groups');
$page->set('content', $content);
$page->set('alerts', $active_user->pop_alerts());
echo $page->generate();
