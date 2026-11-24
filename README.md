# Ringotel Sync for FusionPBX

This app keeps your FusionPBX extensions and your Ringotel users in step, and gives you a page in FusionPBX to manage who has a Ringotel license.

Before this, any time someone changed a name on an extension we had to go into Ringotel and change it there too. Now FusionPBX is the place you make changes, and Ringotel follows.

## What it does

When you add an extension in FusionPBX, it shows up in Ringotel as a directory user. It does not use a license until you activate it.

When you change an extension's name, number, SIP password or voicemail email, the change is sent to Ringotel. Activation is left alone, so an activated user stays activated and a directory user stays a directory user.

When you delete an extension, the matching Ringotel user is deleted too, whether it was activated or not.

Activating and deactivating is done from the app's own Activations page. You don't need to touch the extension page or the Ringotel portal for it.

If someone hasn't used the Ringotel app in a set number of days (30 by default), their user can be deactivated automatically so the license is freed. You can mark people as exempt if they need to stay activated no matter what.

The app also shows you users that exist in Ringotel but don't match any extension. These are usually old users someone added by hand. The sync never changes them on its own, but you can deactivate or delete them from the list.

A few other things worth knowing:

- Extensions are tracked by their internal ID, not their number. If you renumber 101 to 201, the same Ringotel user is updated instead of a new one being created.
- The first time a domain syncs, any Ringotel user that already has the same extension number is picked up and linked. You won't get duplicates.
- Every change is written to a log you can view in FusionPBX.

## Requirements

- FusionPBX 5.4 or newer
- PHP with the curl extension (normally already installed)
- A Ringotel API key, created in the Ringotel admin portal

## Installing

1. Put the app in the FusionPBX app directory. The easiest way is to clone it straight into place:

   ```
   cd /var/www/fusionpbx/app
   git clone https://github.com/tony1661/fusionpbx-ringotel.git ringotel
   chown -R www-data:www-data /var/www/fusionpbx/app/ringotel
   ```

   The folder must be named `ringotel`. If you downloaded a zip instead, unzip it and rename the folder so that `app_config.php` ends up at `/var/www/fusionpbx/app/ringotel/app_config.php`.

2. Run the FusionPBX upgrade. This creates the database tables, settings, permissions and the menu entry.

   ```
   cd /var/www/fusionpbx && php core/upgrade/upgrade.php
   ```

   You can also do this from Advanced > Upgrade. Tick Schema, App Defaults, Menu Defaults and Permission Defaults.

   If you've customized your menu, check what Menu Defaults does on your version first. You can skip it and add the menu item by hand instead (see below).

3. Log out of FusionPBX and log back in so the new permissions are picked up.

4. Go to Advanced > Default Settings and search for `ringotel`. Put your Ringotel API key in `api_key`. For your first run, set `dry_run` to `true` so nothing changes in Ringotel while you check things. Click Reload when you're done.

5. Open Applications > Ringotel, then click Domains. Open a domain, pick its Ringotel organization and connection, set Sync Enabled to True and click Save and Sync.

6. Click Logs and read through what the sync did (or would have done, if dry run is on). If it all looks right, set `dry_run` back to `false`, click Reload, and press Sync Now on the Activations page.

7. Install the background service so syncing happens on its own:

   ```
   cp /var/www/fusionpbx/app/ringotel/resources/service/ringotel.service /etc/systemd/system/
   systemctl daemon-reload
   systemctl enable --now ringotel
   ```

   You can watch it with `journalctl -u ringotel -f`.

### Adding the menu item by hand

If the Ringotel entry doesn't appear under Applications, go to Advanced > Menu Manager, open your menu and add an item:

- Title: Ringotel
- Link: `/app/ringotel/ringotel_activations.php`
- Parent: Applications
- Groups: superadmin and admin

The page works at that link even without a menu entry.

### Upgrading

Pull the latest version, run the upgrade again with Schema ticked, and restart the service:

```
cd /var/www/fusionpbx/app/ringotel && git pull
cd /var/www/fusionpbx && php core/upgrade/upgrade.php
systemctl restart ringotel
```

If you installed from a zip, copy the new files over the old ones instead of running git pull.

## Using it

**Activations** is the main page. It lists every extension in the domain you're logged into, with its Ringotel status, whether the app is online, when it was last used, and when it was activated. Tick the users you want and click Activate or Deactivate. Superadmins can also mark users as exempt from auto deactivation.

The filters along the top let you narrow the list to activated users, directory-only users, users who haven't been seen in a while, and users that are in Ringotel only.

Sync Now pushes every extension in the domain to Ringotel straight away. It also fixes anything that was changed directly in the Ringotel portal. The background service runs every two minutes and only sends extensions that have changed.

**Domains** (superadmin only) is where you link each FusionPBX domain to a Ringotel organization and connection, turn syncing on or off, and set auto deactivation. It also shows how many users are activated in each domain and how many Ringotel users aren't linked to anything.

**Logs** shows every change the app made or tried to make, who made it, and any errors.

## Settings

All settings are under Advanced > Default Settings, category `ringotel`. Changes take effect on the next sync, so you don't need to restart the service.

| Setting | Default | What it does |
| --- | --- | --- |
| api_url | https://shell.ringotel.co/api | The Ringotel API address. You shouldn't need to change this. |
| api_key | empty | Your Ringotel API key. |
| sync_interval | 120 | How often the service syncs, in seconds. |
| inactivity_days | 30 | Days without app use before a user is deactivated. Each domain can override this. |
| inactivity_notify_only | true | While this is on, inactive users are only listed in the logs. Turn it off when you're ready for the app to deactivate them. |
| activation_send_email | true | Lets Ringotel send its welcome email when a user is activated. |
| sync_email | true | Sends the extension's voicemail email address to Ringotel. |
| remove_disabled | false | If on, disabling an extension removes it from Ringotel the same as deleting it. |
| max_deletes_per_run | 10 | If one sync would delete more users than this in a domain, it deletes nothing and writes a warning to the log instead. |
| dry_run | false | If on, nothing is changed in Ringotel. Everything the app would have done is written to the log. |

## Permissions

| Permission | Given to | Lets you |
| --- | --- | --- |
| ringotel_activation_view | superadmin, admin | See the Activations page |
| ringotel_activation_edit | superadmin, admin | Activate, deactivate and delete users |
| ringotel_exempt_edit | superadmin | Mark users as exempt |
| ringotel_sync | superadmin, admin | Press Sync Now |
| ringotel_domain_view, ringotel_domain_edit | superadmin | Use the Domains page |
| ringotel_log_view | superadmin, admin | See the logs |

You can change these under Advanced > Group Manager like any other FusionPBX permission.

## Database tables

The upgrade adds three tables to the FusionPBX database. Nothing in the existing FusionPBX tables is changed. The app only reads from `v_extensions`, `v_voicemails` and `v_domains`.

**v_ringotel_domains** has one row per FusionPBX domain you've set up on the Domains page.

| Column | What it holds |
| --- | --- |
| ringotel_domain_uuid | Row ID |
| domain_uuid | The FusionPBX domain |
| ringotel_org_id, ringotel_org_domain | The Ringotel organization it's linked to |
| ringotel_branch_id | The Ringotel connection it's linked to |
| sync_enabled | Whether the domain syncs |
| auto_deactivate, inactivity_days | Auto deactivation on or off, and the days for this domain (empty means use the default setting) |
| last_sync, last_sync_status, last_sync_message | When the last sync ran and how it went |
| unlinked_count | How many Ringotel users aren't linked to an extension |

**v_ringotel_users** links each extension to its Ringotel user. This is how the app knows which Ringotel users it looks after.

| Column | What it holds |
| --- | --- |
| ringotel_user_uuid | Row ID |
| domain_uuid, extension_uuid | The FusionPBX domain and extension |
| extension, name | The number and name as of the last sync |
| ringotel_user_id | The user's ID in Ringotel |
| ringotel_status | -1 directory only, 0 blocked, 1 activated, 2 attached |
| ringotel_state | Whether the app is online, away, on a call and so on |
| sync_hash | A fingerprint of the extension's details, used to spot changes |
| last_seen | Last time the user logged in to the Ringotel app |
| activated_at | When the user was activated |
| exempt | Whether the user is exempt from auto deactivation |
| deactivated_reason | Why the user was last deactivated, for example manual or inactive 45 days |
| inactivity_notified_at | Last time an inactivity warning was logged, so it's only logged once a day |
| last_synced | Last time the user's details were sent to Ringotel |

**v_ringotel_logs** is the history shown on the Logs page: the date, domain, extension, what was done, whether it worked, a message, and who or what did it. Rows older than 90 days are deleted automatically.

All three tables also have the usual FusionPBX insert_date, insert_user, update_date and update_user columns.

## Good to know

Testing on a lab server: create a separate organization in Ringotel for your lab and link only your lab domain to it. The app only touches organizations you've linked on the Domains page. Keep in mind that activating users in a test organization still uses licenses from your account.

Last seen comes from the last time Ringotel saw the user log in on any device. The auto deactivation clock starts when the user is activated, so someone who was activated but never signed in is covered too. Users that were already activated before the app was installed get the full number of days from the first sync.

When a user is deactivated, Ringotel gives them a new ID behind the scenes. The app handles this for you. If someone deactivates a user in the Ringotel portal, the next sync finds them again by extension number.

If you change which organization or connection a domain is linked to, the app forgets its old links for that domain and rebuilds them on the next sync.

If FusionPBX returns no extensions at all for a domain, the app assumes something is wrong and doesn't delete anything.

Logs older than 90 days are cleared automatically.

## Running a sync by hand

```
sudo -u www-data php /var/www/fusionpbx/app/ringotel/resources/service/ringotel.php --once
```

Add `--domain=` followed by a domain UUID to sync just one domain, or `--force` to push every extension. Run it as www-data so the lock files stay owned by the right user.

## Troubleshooting

If the Activations page says the domain isn't linked, open Domains and pick an organization and connection for it.

If the organization list on the Domains page is empty or shows an error, check the API key and that the server can reach shell.ringotel.co.

If the service isn't syncing, run `systemctl status ringotel` and `journalctl -u ringotel -n 50` to see what it's doing.

If something in Ringotel doesn't match FusionPBX, press Sync Now, then check Logs for errors.

## Removing it

Removing the app doesn't change anything in Ringotel. Users it created stay there as they are. If you want them gone or deactivated, do that from the Activations page (or the Ringotel portal) before you remove the app.

1. Stop and remove the service:

   ```
   systemctl disable --now ringotel
   rm /etc/systemd/system/ringotel.service
   systemctl daemon-reload
   ```

2. Delete the app folder and its lock files:

   ```
   rm -rf /var/www/fusionpbx/app/ringotel
   rm -f /tmp/ringotel_sync_*.lock /tmp/ringotel_service.pid
   ```

At this point the app is gone from the server. FusionPBX doesn't clean up after removed apps, so the tables, settings, permissions and menu entry are still in the database. They're harmless, and leaving them means your links and logs are still there if you reinstall. If you want a clean removal, carry on.

3. Back up the database first:

   ```
   sudo -u postgres pg_dump fusionpbx > fusionpbx_before_ringotel_removal.sql
   ```

4. Open the database with `sudo -u postgres psql fusionpbx` and run:

   ```
   DROP TABLE IF EXISTS v_ringotel_logs, v_ringotel_users, v_ringotel_domains;

   DELETE FROM v_menu_item_groups WHERE menu_item_uuid = 'f0ca5f2e-c49c-4cd6-bda3-9a438da38fab';
   DELETE FROM v_menu_items WHERE menu_item_uuid = 'f0ca5f2e-c49c-4cd6-bda3-9a438da38fab';

   DELETE FROM v_group_permissions WHERE permission_name LIKE 'ringotel\_%';

   DELETE FROM v_default_settings WHERE default_setting_category = 'ringotel';
   ```

   Type `\q` to leave psql.

5. Log out of FusionPBX and back in so the menu refreshes.

If you added the menu item by hand under a different ID, delete it from Advanced > Menu Manager instead of using the two menu lines above.
