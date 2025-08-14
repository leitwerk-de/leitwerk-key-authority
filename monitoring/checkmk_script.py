#! /usr/bin/env python
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

from typing import Any
import calendar
import email.utils
import json
import time


def oneline(s: str):
    lines = s.split("\n")
    if len(lines) > 0 and lines[-1] == "":
        lines = lines[:-1]
    return " / ".join(lines)


def collect_errors(status_info: dict[str, Any]):
    OK = 0
    WARN = 1

    SCRIPT_VERSION = 1
    errors = []

    if SCRIPT_VERSION < status_info["warn_below_version"]:
        errors += [(WARN, f"This checkscript (version {SCRIPT_VERSION}) is outdated. Please update the script on the affected machine.")]

    if status_info["sync_status"] != "sync success":
        if status_info["sync_status_message"] is None:
            message = ""
        else:
            message = ": " + oneline(status_info["sync_status_message"])
        errors += [(WARN, "The keys-sync status is '" + oneline(status_info["sync_status"]) + "'" + message + " - Please check the machine's error in the LKA web ui")]

    if status_info["key_supervision_error"] is not None:
        errors += [(WARN, "Error supervising keys: " + oneline(status_info["key_supervision_error"]))]

    exp_tup = email.utils.parsedate_tz(status_info["expire"])
    expired = calendar.timegm(exp_tup[0:6]) - exp_tup[9]
    curtime = time.time()
    if expired <= curtime:
        errors += [(WARN, "The keys-sync status has expired (Got no update from ssh-key-authority before the expire-time was reached) - Please check the machine's error in the LKA web ui")]

    if len(status_info["accounts_with_unnoticed_keys"]) > 0:
        account_list = "(" + ", ".join(status_info["accounts_with_unnoticed_keys"]) + ")"
        errors += [(WARN, "There have been new external keys for at least 96 hours on following accounts: " + account_list + " - Please allow or deny them in the LKA web ui.")]

    if len(errors) == 0:
        errors = [(OK, "The keys-sync status is OK and up-to-date")]

    return errors


def check_content(status_filename: str):
    with open(status_filename, "r") as f:
        content = json.load(f)
    errors = collect_errors(content)

    # Move warnings behind errors
    errors.sort(key=lambda e: e[0], reverse=True)

    # Find the most critical state
    final_state = max([e[0] for e in errors])

    return final_state, "; ".join([e[1] for e in errors])


def main():
    status_filename = "/var/local/keys-sync.status"

    try:
        status, info = check_content(status_filename)
    except BaseException as e:
        status = 1
        info = "Check script failed to execute: " + oneline(str(e))

    print(f"{status} keys_sync_status - {info}")


if __name__ == "__main__":
    main()
