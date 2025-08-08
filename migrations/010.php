<?php
$migration_name = 'Add key_sync_user setting for servers';

$this->database->query("
ALTER TABLE `server`
ADD `key_sync_user` varchar(100) NOT NULL DEFAULT 'keys-sync';
");
