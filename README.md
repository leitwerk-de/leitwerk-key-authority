LKA - Leitwerk Key Authority
=======================

*Please see the [Security Advisories](#security-advisories) section below for a recently addressed security issue*

A tool for managing user and server SSH access to any number of servers.

Features
--------

* Easily manage SSH key access for all accounts on your servers.
* Manage user access and server-to-server access rules.
* Integrate with your LDAP directory service for user authorization.
* Automatically remove server access from people when they leave your team.
* Provides an easy interface for your users to upload their public keys.
* Designate server leaders and let them manage access to their own server.
* Create group-based access rules for easier management.
* Specify SSH access options such as `command=`, `nopty` etc on your access rules.
* All access changes are logged to the database and to the system logs. Granting of access is also reported by email.
* Be notified when a server becomes orphaned (has no active leaders).

Requirements
------------

* An LDAP directory service
* Apache 2.2 or higher
* PHP 5.6 or higher
* Composer
* PHP JSON extension
* PHP LDAP extension
* PHP mbstring (Multibyte String) extension
* PHP MySQL extension
* PHP GMP extension (not strictly needed, but a big performance improvement)
* MySQL (5.5+), Percona Server (5.5+) or MariaDB database

Installation
------------

1.  Clone the repo somewhere outside of your default Apache document root.

2.  Install the dependencies via composer:

        composer install

3.  Add the following directives to your Apache configuration (eg. virtual host config):

        DocumentRoot /path/to/lka/public_html
        DirectoryIndex init.php
        FallbackResource /init.php

4.  Create a MySQL user and database (run in MySQL shell):

        CREATE USER 'lka-user'@'localhost' IDENTIFIED BY 'password';
        CREATE DATABASE `lka-db` DEFAULT CHARACTER SET utf8mb4;
        GRANT ALL ON `lka-db`.* to 'lka-user'@'localhost';

5.  Copy the file `config/config-sample.ini` to `config/config.ini` and edit the settings as required.

6.  Set up authnz_ldap for your virtual host (or any other authentication module that will pass on an Auth-user
    variable to the application).

7.  Set `scripts/ldap_update.php` and `scripts/supervise_external_keys.php` to run on a regular cron job.

8.  Generate an SSH key pair to synchronize with. Leitwerk Key Authority will expect to find the files as `config/keys-sync` and `config/keys-sync.pub` for the private and public keys respectively. Be careful to use PEM format.

    ssh-keygen -b 4096 -m pem -f config/keys-sync

9.  Install the SSH key synchronization daemon. For systemd:

    1.  Copy `services/systemd/keys-sync.service` to `/etc/systemd/system/`
    2.  Modify `ExecStart` path and `User` as necessary. If Leitwerk Key Authority is installed under `/home`, disable `ProtectHome`.
    3.  `systemctl daemon-reload`
    4.  `systemctl enable keys-sync.service`

    for sysv-init:

    1.  Copy `services/init.d/keys-sync` to `/etc/init.d/`
    2.  Modify `SCRIPT` path and `USER` as necessary.
    3.  `update-rc.d keys-sync defaults`

Usage
-----

Anyone in the LDAP group defined under `admin_group_cn` in `config/config.ini` will be able to manage accounts and servers.

Key distribution
----------------

Leitwerk Key Authority distributes authorized keys to your servers via SSH. It does this by:

1.  Connecting to the server with SSH, authorizing as the keys-sync-user defined per server(default:`keys-sync`).
2.  Writing the appropriate authorized keys to named user files in `/var/local/keys-sync/` for linux and `/ProgramData/ssh/keys-sync/` for windows (eg. all authorized keys for the root user will be written to `/var/local/keys-sync/root`).

This means that your SSH installation will need to be reconfigured to read authorized keys from the given ssh keys dir.

Please note that doing so can deny access to any existing SSH public key authorized in the default `~/.ssh` directories.

Under OpenSSH, the configuration changes needed are:

    AuthorizedKeysFile /var/local/keys-sync/%u
    StrictModes no

StrictModes must be disabled because the files will all be owned by the keys-sync user.

Alternatively existing user authorized keys can be used by setting the config file like this:

    AuthorizedKeysFile /var/local/keys-sync/%u .ssh/authorized_keys

The file `/var/local/keys-sync/keys-sync` must exist, with the same contents as the `config/keys-sync.pub` file in order for the synchronization daemon to authenticate.
These keys will be used by the defined keys-sync-user regardless of if its name is actually keys-sync.

Screenshots
-----------

### Homepage overview
![Homepage overview](public_html/screenshot-home.png)

### Server listing
![Server listing](public_html/screenshot-servers.png)

### Server account access management
![Server account access management](public_html/screenshot-account.png)

### Activity log
![Activity log](public_html/screenshot-activity.png)

### Getting started guide for new users
![Getting started guide for new users](public_html/screenshot-getting-started.png)

Security advisories
-------------------
* [security advisory: SSH port redirection attack](https://github.com/operasoftware/ssh-key-authority/wiki/SKA-security-advisory%3A-SSH-port-redirection-attack)

License
-------

Copyright 2013-2017 Opera Software
Modifications Copyright 2021 Leitwerk AG

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

   http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
