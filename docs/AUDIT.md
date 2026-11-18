# Audit of com_biotime (version 1.0.17, fixed in 1.0.18)

Two independent reviews of the whole component, by reading the code (nothing was run against a
live site): one against the Joomla Manual and extension conventions, one for security. Every
finding was checked against the code before it was changed.

## Result

- **Security: no critical, high or medium finding.** No cross-site scripting, SQL injection,
  request forgery, secret leak, or way to see another employee's data was found.
- **Structure: no high finding.** The component follows Joomla's service-provider structure
  (namespaced MVC, `services/provider.php`, `InstallerScriptInterface`, a `RouterView` router,
  web assets through `joomla.asset.json`, language keys for every string).

## Fixed in 1.0.18

| # | Finding | Change |
|---|---|---|
| S1 | The PDF report reads the whole team (up to 300 employees × 31 days) without the cache and could be requested without limit. | At most 30 reports per manager and hour; each report is recorded in the audit log (`manager.pdf`). |
| S2 | A manager's departments and team were kept for the cache time (up to 60 minutes), so a permission removed in ZKBioTime kept showing that long. | Kept for at most 5 minutes. |
| S3 | Any `layout` could be asked for on any page (only an error page resulted). | Only the Manager Dashboard's `print` layout can be chosen; anything else is the page itself. |
| S4 | The stored logo path and colour were checked with `$`, which accepts a trailing line break. | Checked with `\z`. |
| J1 | The setting *Modules → Charts* did nothing since 0.9.0. | Removed (field, default, strings); its stored row is deleted on update. |
| J2 | `style=""` attributes (month arrows, chart legends and bars) need `'unsafe-inline'` under a strict Content Security Policy. | A CSS class and SVG attributes instead; no portal page has a `style` attribute. |
| J3 | The User Mappings filters used inline `onchange="this.form.submit();"`. | Joomla's `js-select-submit-on-change` class. |
| J4 | A session clean-up for versions before 0.4.6 still ran on every page. | Removed. |
| J5 | 17 language strings were no longer used. | Removed. |
| J6 | The release workflow used the runner's PHP, not the PHP 8.3 the component requires. | PHP 8.3 set up explicitly (action pinned to a commit). |
| J7 | Manifest and `access.xml` comments were misplaced or missing. | Corrected. |

## Not changed: decisions for the site owner

- **How a manager is linked to a ZKBioTime user.** The link uses the employee ID (`emp_pin` or
  username) or the e-mail of the employee record. Someone who can edit employee records in
  ZKBioTime, and who also has the Manager Dashboard permission, could set their own employee
  e-mail to an administrator's and see every department. Whether that matters depends on who may
  edit employees in ZKBioTime. Linking by `emp_pin` only would close it, but would break managers
  whose ZKBioTime user is linked by e-mail or username today, so it was left as it is.
- **Framing.** The component sets no `X-Frame-Options` itself; enable Joomla's *System – HTTP
  Headers* plugin for that. The portal has no state-changing action a frame could trigger.
- **Administrator-only inputs.** The CA file path and Test Database accept any path or host; both
  need Super User rights and a token, and Test Database is limited to 20 an hour.

## Noted, left for later (low)

- Portal CSS uses physical `left`/`right` in places and the month arrows are fixed: a right-to-left
  language would need logical properties.
- The date filter is always DD/MM/YYYY, whatever *Date format* is set to.
- The day details page has no clean URL of its own (`?view=day&date=…` under the menu item).
- Models and views call `Factory::getApplication()`; Joomla's `getCurrentUser()` is preferred.
- The Test Database summary for support requests is English only, on purpose.
