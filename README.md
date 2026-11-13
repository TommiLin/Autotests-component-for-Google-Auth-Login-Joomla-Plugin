# Google Auth Login – Live Test Runner

A Joomla **administrator component** (`com_googleauthtests`) that runs automated tests for the [Google Auth Login](README.md) plugin **on your own server**, against the real Joomla and the real plugin. Install it, open it in the back end, press **Run all** and get a pass/fail report together with the versions of your environment.

**Version 1.0.1** · for plugin **1.10.2** · Super Users only · interface language: Russian

![Test runner overview](images/tests-overview.png)

---

## Requirements

* Joomla **4.4** / **5.x** / **6.x**
* PHP **8.1** or higher
* The **Google Auth Login** plugin installed and enabled (the tests target version 1.10.2; an older plugin shows failures where behavior changed)
* A **test site** is recommended: some tests create temporary users (see [What the tests change](#what-the-tests-change))

---

## Installation

1. **System → Install → Extensions** → upload `com_googleauthtests-1.0.1.zip`.
2. Open **Components → Google Auth Tests**.
3. When you are done, **uninstall the component** (System → Manage → Extensions). It is a diagnostic tool and is not meant to stay on a production site.

Only users with the **Super User** permission (`core.admin`) can open it or call its AJAX endpoints, and every request is protected by Joomla's CSRF token.

---

## Using the runner

![Test results](images/tests-results.png)

**Environment** (top of the page) lists the versions and settings the results depend on: PHP and its extensions, web server, Joomla, database, site URL and HTTPS, template, mail settings, the plugin (version in `googleauth.xml` and in the database, enabled, non-secret settings) and Community Builder. Secrets are never shown: the Client ID is masked and the Client Secret only shows "set / not set".

**Running tests**

| Control | Action |
|---|---|
| **Run all** | Runs every test, one after another |
| **Run selected** | Runs the ticked tests only (tick a whole group with its header checkbox) |
| ▶ next to a test | Runs just that test |
| **Stop** | Stops after the current test |
| **Select all / Clear / Select failed** | Convenience for picking tests; "Select failed" ticks tests that failed, errored or warned in the last run |
| Click a test row | Shows its detailed messages |

**Options**

* **Include tests that write data** (on by default) – tests marked *writes data* create temporary users. Untick to run only read-only tests.
* **Include risky tests** – reserved for tests with side effects beyond temporary users; none are marked risky in this version.
* **Stop on first failure.**

**Report** – *Copy report* puts the environment and all non-passing messages on the clipboard (handy for sending to the developer); *Download JSON* saves the full result set.

**Delete leftover test data** – removes temporary users if a run was interrupted. The runner also does this automatically after every test and at the end of every run.

### Result statuses

| Status | Meaning |
|---|---|
| ✓ passed | All checks of the test succeeded |
| ✗ failed | At least one check failed: a real problem in the plugin, its installation or its settings |
| ✗ error | The test itself threw an exception or the server returned something other than JSON (usually a PHP fatal error); details are shown |
| ! warning | Everything required passed, but something deserves attention (for example, a Privacy Policy link is not configured) |
| – skipped | The test cannot run here, with the reason (for example, Community Builder is not installed, or the site is offline) |

---

## What is tested

57 tests in 10 groups.

| Group | Tests | What they check |
|---|---|---|
| **Installation and settings** | 7 | PHP/Joomla requirements; plugin files; `googleauth.xml` fields and defaults; registration and enabled state, version in the database equals the XML; plugin class loads and the methods the tests rely on exist; saved settings are valid (list values, Client ID format, links, admin IDs); site readiness (HTTPS, redirect URI, writable log folder) |
| **Language files** | 7 | `.ini` syntax (finds glued lines, stray quotes, BOM); no duplicate keys; en-GB / ru-RU / uk-UA have the same keys; every label in the manifest and every key used in the PHP code is translated; `%s` / `%d` placeholders match; Joomla's own loader reads the files |
| **Button rendering** | 9 | The plugin's real `onAfterRender()` on sample pages: when the button is added (Joomla/CB/JReviews forms, forms with `task=user.login`; not for logged-in users, AJAX or an empty Client ID); the Google URL (client_id, https redirect_uri, scope, 64-hex `state`); `prompt` vs *force Google consent*; all color schemes; type × shape; icon alignment; copy-submit-style mode; state and return URL in the session; the injected JavaScript contains the expected logic |
| **OAuth state** | 4 | State is single-use; expires after 10 minutes; several tabs work at once; garbage values are rejected |
| **Redirect after sign-in** | 4 | *Home*, *current page*, *profile* (Community Builder or Joomla), *custom URL*, and the separate setting for first registration; foreign hosts and login pages are never used as a target |
| **Consent and document versions** | 4 | Accepted consent is valid only for the current document versions; re-consent reason (terms / privacy / both); when the consent screen is needed |
| **Accounts, sign-in and notifications** | 8 | Username generation; user creation (group, hash, Google ID); lookup by Google ID / e-mail / conflict; saving parameters without losing others or overwriting corrupt JSON; LIKE wildcards in Google IDs; sign-in (blocked and guest users refused, `onUserAfterLogin` fired); administrator notifications (recipients, text, skipped users, one message per recipient); Community Builder sync |
| **Plugin log** | 2 | The plugin writes to `googleauth.log.php`; the log contains no secrets, tokens or JWTs |
| **Visitor's view (HTTP)** | 5 | The server requests its own pages like a visitor: login page opens; the button is present and matches the settings; the Google callback rejects forged requests; the consent endpoint without a session creates nothing; responses contain no secret and no PHP errors |
| **In the browser** | 7 | Runs the plugin's real JavaScript on **your template** in a hidden same-origin frame: script compiles; button is placed after the submit button and is visible; no duplicates after resize/click; link points to Google; button size; copied submit classes; hover CSS present |

---

## What the tests change

| Where | What happens | How it is cleaned up |
|---|---|---|
| Database | Tests marked *writes data* create users `gat-…@example.invalid` (and Community Builder rows if CB is installed) | Deleted after each test, again at the end of each run, or with the **Delete leftover test data** button |
| Files | A Community Builder test may write avatar files for those users | Removed together with the users |
| Plugin settings | Overridden **in memory only** for the duration of a test | Never saved to the database |
| E-mail | Joomla itself mails users created in the administrator, and the notification test sends e-mail | The mailer is replaced by a capturing one: **no e-mail leaves the server** (Joomla 4.4+) |
| Other plugins | The sign-in test would fire `onUserAfterLogin` | Events are recorded, not dispatched, so other extensions are not triggered |
| Sessions | Tests use an isolated in-memory session | Your own session and the visitors' sessions are untouched |
| Log | The log test appends one test line to `googleauth.log.php` | – |

The HTTP tests only **read** pages of your own site and send deliberately invalid requests to the plugin's callback.

---

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Most tests are skipped: "plugin not found, disabled…" | Enable the plugin in **System → Plugins**; check the *Installation* group first |
| *HTTP* tests and *In the browser* tests are skipped with "Offline" | Global Configuration → Site Offline is on; visitors see the "site closed" page. Switch the site on for the check |
| *HTTP* tests are skipped with "server cannot request its own address" | The host blocks loopback requests (DNS or firewall). This is a hosting setting, not a plugin problem |
| *In the browser* tests are skipped: "login page did not open in the frame" | The site sends `X-Frame-Options` / CSP that forbids framing even for the same origin |
| `http.button` fails: no button on the login page | The plugin is disabled, no Client ID is set, the page is served from a page cache, or the template renders the login form in a way the plugin does not recognize |
| `http.button`: two requests got the same `state` | The login page is cached; a cached page breaks the one-time `state` protection |
| An *error* with "server returned non-JSON" | A PHP fatal error in that test; the first characters of the response are shown. Send the report to the developer |
| Console shows a 404 for `tests.js` / `tests.css` | Clear **System → Clear Cache** and reload; reinstall the component |
| `redirect.*` tests mention `/administrator/` | The tests run in the back end, where the "site" URL is the administrator URL; the logic under test is unaffected |

---

## Sending a report

Press **Copy report** and paste the text. It contains the environment, every non-passing test and its messages, and no secrets.

---

## Changelog

* **1.0.1** – Tests skip (instead of failing) when the site is in Offline mode; the notification test no longer needs to create a user in the Super Users group (some sites forbid it); the form-detection test expects the behavior of plugin 1.10.2.
* **1.0.0** – First release: 57 tests in 10 groups.

## License

GNU General Public License v2 or later. Copyright (C) 2026 Tomilin Serhii.
