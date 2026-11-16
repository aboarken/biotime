// End-to-end check of the portal and the User Mappings page against a Joomla site with the
// component installed, tests/e2e/fake-zkbiotime.php serving on FAKE_URL, and these test users
// (all Registered, password USER_PASS): sara (custom field employeeid = FIXTURE1),
// omar (omar@example.test), shared (shared@example.test, two employees), nobody.
// The four zkb.* permissions must be allowed for Registered.
//
//   BASE_URL=http://127.0.0.1:8090 ADMIN_PASS=... USER_PASS=... OUT=tests/output node tests/e2e/portal.mjs
import { createRequire } from 'node:module';
import fs from 'node:fs';
const { chromium } = createRequire(import.meta.url)('playwright');

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8090';
const FAKE = process.env.FAKE_URL ?? 'http://127.0.0.1:18081';
const OUT = process.env.OUT ?? 'tests/output';
const USER_PASS = process.env.USER_PASS ?? '';
fs.mkdirSync(OUT, { recursive: true });

const failures = [];
const check = (ok, what) => { console.log(`${ok ? 'PASS' : 'FAIL'} ${what}`); if (!ok) failures.push(what); };
const browser = await chromium.launch();
const ym = new Date().toISOString().slice(0, 7);

async function untranslated(page) { return (await page.content()).match(/COM_BIOTIME_[A-Z0-9_]+/g) ?? []; }
async function overflow(page) { return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth); }

// ---------------------------------------------------------------- administrator
const admin = await browser.newPage({ viewport: { width: 1366, height: 900 } });
await admin.goto(`${BASE}/administrator/index.php`);
await admin.fill('#mod-login-username', process.env.ADMIN_USER ?? 'admin');
await admin.fill('#mod-login-password', process.env.ADMIN_PASS ?? '');
await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle' }), admin.click('#btn-login-submit')]);

const settingsUrl = `${BASE}/administrator/index.php?option=com_biotime&view=integrationsettings`;
await admin.goto(settingsUrl, { waitUntil: 'networkidle' });
check(await admin.locator('#jform_server_port').count() === 0, 'no separate Port field');
check(await admin.locator('#jform_match_field').inputValue() === 'employeeid', 'employee code user field defaults to employeeid');
await admin.fill('#jform_server_url', FAKE);
await admin.fill('#jform_connect_via', '');
await admin.fill('#jform_api_username', 'portal-api');
await admin.fill('#jform_api_password', 'Fixture-Pass-123');
await admin.fill('#jform_daily_path', '');
await admin.fill('#jform_daily_fields', '');
await admin.fill('#jform_daily_status_map', '');
check(await admin.locator('#jform_leave_path, #jform_module_leave').count() === 0, 'no Leave Source or Leave switch any more');
await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle' }), admin.click('#toolbar-apply button, button.button-apply')]);
const saved = await admin.locator('#system-message-container').innerText();
check(/Settings saved/.test(saved), `settings with data sources saved (${saved.replace(/\s+/g, ' ').slice(0, 160)})`);
check((await untranslated(admin)).length === 0, `settings page translated (${(await untranslated(admin)).join(',')})`);
await admin.screenshot({ path: `${OUT}/admin-settings.png`, fullPage: true });

// Find Endpoints: read the API list, check the daily endpoint, save the reviewed suggestions.
await admin.goto(`${BASE}/administrator/index.php?option=com_biotime&view=endpoints`, { waitUntil: 'networkidle' });
await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle' }), admin.click('button:has-text("Read the API list")')]);
let ep = await admin.innerText('body');
check(/describes \d+ list endpoints/.test(ep) && ep.includes('/att/api/fixture_daily/'), 'Find Endpoints: API list read');
check(!ep.includes('{id}'), 'Find Endpoints: only list endpoints');
await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle' }), admin.click('tr:has-text("/att/api/fixture_daily/") a:has-text("Select")')]);
check(await admin.inputValue('#biotime-path') === '/att/api/fixture_daily/' && await admin.inputValue('#biotime-pf') === 'start_date', 'Find Endpoints: path and filters pre-filled');
await admin.fill('#biotime-emp', 'FIXTURE1');
await admin.fill('#biotime-from', `${ym}-01`);
await admin.fill('#biotime-to', `${ym}-28`);
await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle' }), admin.click('button:has-text("Check")')]);
ep = await admin.innerText('body');
check(/returned \d+ rows/.test(ep) && ep.includes('att_status') && ep.includes('employee.emp_code'), 'Find Endpoints: fields listed');
check(/Normal/.test(ep) && /Absent/.test(ep), 'Find Endpoints: status texts listed');
check(!/FIXTURE1/.test(await admin.locator('table').last().innerText()), 'Find Endpoints: no employee codes in the field table');
const fieldsText = await admin.inputValue('#biotime-fields');
check(/date = att_date/.test(fieldsText) && /emp_code = employee\.emp_code/.test(fieldsText) && /schedule = timetable/.test(fieldsText), `Find Endpoints: suggestions (${fieldsText.replace(/\n/g, ' | ')})`);
check(/Normal = present/.test(await admin.inputValue('#biotime-status')), 'Find Endpoints: status suggestions');
check((await untranslated(admin)).length === 0, `Find Endpoints translated (${(await untranslated(admin)).join(',')})`);
await admin.screenshot({ path: `${OUT}/admin-endpoints.png`, fullPage: true });
await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle' }), admin.click('button:has-text("Save as Daily Attendance Source")')]);
check(/Daily Attendance Source is saved/.test(await admin.locator('#system-message-container').innerText()), 'Find Endpoints: saved');
check(await admin.inputValue('#jform_daily_path') === '/att/api/fixture_daily/', 'settings show the saved daily source');
await admin.fill('#jform_api_password', 'Fixture-Pass-123');

// The punch list cannot be saved as a source.
await admin.goto(`${BASE}/administrator/index.php?option=com_biotime&view=endpoints`, { waitUntil: 'networkidle' });
await admin.fill('#biotime-path', '/iclock/api/transactions/');
await admin.fill('#biotime-pf', 'start_time');
await admin.fill('#biotime-pt', 'end_time');
await admin.selectOption('#biotime-df', 'Y-m-d H:i:s');
await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle' }), admin.click('button:has-text("Check")')]);
check(/already reads this endpoint/.test(await admin.innerText('body')) && (await admin.locator('button:has-text("Save as Daily Attendance Source")').count()) === 0, 'punch list: shown but cannot be saved as a source');

// Diagnostics: one report with every check, no personal values.
await admin.fill('#biotime-diag-emp', 'FIXTURE1');
await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle' }), admin.click('#biotime-diag-run')]);
const diag = await admin.inputValue('#biotime-diag-report');
check(/1\. Connection[\s\S]*OK/.test(diag) && /3\. Punches \/iclock\/api\/transactions\/\n\s+OK/.test(diag), 'Diagnostics: connection and punches checked');
check(/\/att\/api\/fixture_daily\/[\s\S]*- att_status \(text\)/.test(diag) && /\/att\/api\/totalTimeCardReport\/[\s\S]*FAILED: bad_response/.test(diag), 'Diagnostics: attendance endpoints checked');
check(!/FIXTURE1|Fixture-Pass|Sara|example\.test|\d{2}:\d{2}:\d{2}/.test(diag), 'Diagnostics: no codes, secrets, names or times in the report');
check((await untranslated(admin)).length === 0, 'Diagnostics translated');
await admin.goto(settingsUrl, { waitUntil: 'networkidle' });

// Invalid source refused.
await admin.fill('#jform_daily_extra', 'q = SELECT * FROM users');
await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle' }), admin.click('#toolbar-apply button, button.button-apply')]);
check(/fixed parameters must be/.test(await admin.locator('#system-message-container').innerText()), 'free-form query in a source refused');
await admin.goto(settingsUrl, { waitUntil: 'networkidle' });
await admin.fill('#jform_daily_extra', '');
// The portal checks below use the punches only, as on ZKBioTime 9.5 (no daily source).
await admin.fill('#jform_daily_path', '');
await admin.fill('#jform_daily_fields', '');
await admin.fill('#jform_daily_status_map', '');
await admin.fill('#jform_api_password', 'Fixture-Pass-123');
await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle' }), admin.click('#toolbar-apply button, button.button-apply')]);

// Menu item types.
await admin.goto(`${BASE}/administrator/index.php?option=com_menus&view=menutypes&tmpl=component&client_id=0&menutype=mainmenu`, { waitUntil: 'networkidle' });
const types = await admin.evaluate(() => document.body.textContent);
for (const t of ['My Dashboard', 'My Attendance', 'Attendance Calendar', 'My Punches']) {
  check(types.includes(t), `menu item type "${t}"`);
}
for (const t of ['My Schedules', 'Attendance Reports', 'My Profile']) {
  check(!types.includes(t), `removed menu item type "${t}" is gone`);
}

// Unmatched users report: everyone without a mapping yet.
const mappingsUrl = `${BASE}/administrator/index.php?option=com_biotime&view=usermappings`;
await admin.goto(`${mappingsUrl}&filter[status]=none&filter[search]=`, { waitUntil: 'networkidle' });
check(/No mapping yet/.test(await admin.innerText('#adminForm')) && (await admin.innerText('#adminForm')).includes('admin'), 'users without a mapping are listed');

// ---------------------------------------------------------------- portal
async function login(username) {
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, acceptDownloads: true });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/index.php?option=com_users&view=login`, { waitUntil: 'networkidle' });
  await page.fill('#username', username);
  await page.fill('#password', USER_PASS);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('.com-users-login__form button[type=submit], form#com-users-login__form button[type=submit]')]);
  return { ctx, page };
}

const views = ['dashboard', 'attendance', 'calendar', 'punches'];
const pageUrl = (q) => `${BASE}/index.php?option=com_biotime&${q}`;

// Guests are sent to the login page.
const guest = await browser.newPage();
await guest.goto(pageUrl('view=dashboard'), { waitUntil: 'networkidle' });
check(/com_users|login/i.test(guest.url()) || (await guest.locator('#username').count()) > 0, 'guest is asked to log in');

const { ctx: saraCtx, page: sara } = await login('sara');
for (const view of views) {
  const resp = await sara.goto(pageUrl(`view=${view}&month=${ym}`), { waitUntil: 'networkidle' });
  const html = await sara.content();
  check(resp.status() === 200, `sara ${view}: HTTP ${resp.status()}`);
  check((await untranslated(sara)).length === 0, `sara ${view}: translated (${(await untranslated(sara)).join(',')})`);
  check(!/OTHER-EMPLOYEE/.test(html), `sara ${view}: no other employee's data`);
  check(/noindex/.test(html), `sara ${view}: noindex`);
  await sara.screenshot({ path: `${OUT}/portal-${view}-desktop.png`, fullPage: true });
}

await sara.goto(pageUrl(`view=attendance&month=${ym}`), { waitUntil: 'networkidle' });
let text = await sara.innerText('main, body');
check(text.includes('07:58') && text.includes('17:02') && text.includes('Main Gate'), 'attendance: check-in / check-out with the device');
check(!/Schedule|Late-In|Early-Out|Worked Hours|Overtime/.test(text), 'attendance: no fields ZKBioTime 9.5 does not provide');
check(/Duplicate punch/.test(text), 'attendance: duplicate punch exception');
check(/Present/.test(text) && /Missing Punch/.test(text) && /Weekend/.test(text) && !/Incomplete|Leave/.test(text), 'attendance: present, missing punch and weekend; no Incomplete or Leave');
check(/Absent/.test(text) || Number(new Date().getDate()) <= 2, 'attendance: past working days without punches are absent');
await sara.goto(pageUrl(`view=attendance&month=${ym}&status=missingpunch`), { waitUntil: 'networkidle' });
check((await sara.locator('table tbody tr').count()) >= 1, 'attendance: status filter (missing punch)');
await sara.goto(pageUrl(`view=attendance&month=${ym}&date=02/${ym.slice(5, 7)}/${ym.slice(0, 4)}`), { waitUntil: 'networkidle' });
check((await sara.locator('table tbody tr').count()) === 1 && (await sara.inputValue('#bt-date')) === `02/${ym.slice(5, 7)}/${ym.slice(0, 4)}`, 'attendance: date filter in DD/MM/YYYY');
check(!(await sara.title()).includes('|'), `page title uses " - " (${await sara.title()})`);
await sara.click('#bt-date');
check(await sara.locator('.bt-datepicker-pop').isVisible() && await sara.locator('#bt-date').evaluate((el) => el.readOnly), 'attendance: date picker opens, field not typed into');
await Promise.all([sara.waitForNavigation({ waitUntil: 'networkidle' }), sara.locator('.bt-datepicker-day', { hasText: /^3$/ }).first().click()]);
check((await sara.inputValue('#bt-date')) === `03/${ym.slice(5, 7)}/${ym.slice(0, 4)}`, 'attendance: choosing a day in the date picker filters to it');

await sara.goto(pageUrl(`view=dashboard&month=${ym}`), { waitUntil: 'networkidle' });
text = await sara.innerText('body');
check(/Days Attended/.test(text) && /Days Absent/.test(text) && /Average Check-In/.test(text), 'dashboard: summary cards');
check(!/Late-In|Worked Hours|Overtime/.test(text), 'dashboard: no fields ZKBioTime 9.5 does not provide');
check((await sara.locator('.bt-bar').count()) === 1 && (await sara.locator('.bt-tile').count()) >= 4, 'dashboard: overview bar and tiles');
check((await sara.locator('.bt-stat').count()) >= 8 && (await sara.locator('.bt-panel').count()) >= 3, 'dashboard: cards and panels in the portal design');
check(await sara.locator('form[data-bt-filters] .bt-toolbar-submit').isHidden(), 'filter bar: applied on change (no Show button with JavaScript)');
check(/Today:/.test(text), 'dashboard: today card');
check(/NOTE/i.test(await sara.locator('.bt-panel:has-text("Recent Activity") thead').innerText()), 'dashboard: Recent Activity has a Note column');
check(/Recent Activity/.test(text) && /Updated \d\d:\d\d/.test(text), 'dashboard: recent activity and updated time');

// A day with only a check-out punch: the time is under Check-Out, never Check-In.
await sara.goto(pageUrl(`view=day&date=${ym}-09`), { waitUntil: 'networkidle' });
text = await sara.innerText('body');
check(/Check-In\s*Missing/i.test(text) && /Check-Out\s*↗?\s*17:10/i.test(text), `check-out alone shown under Check-Out (${text.replace(/\s+/g, ' ').slice(0, 200)})`);
check(/Present/.test(text) && /Missing Punch/.test(text), 'check-out alone: present with a missing punch');

// "Check In" then "Unknown" (state 255), as the real terminals record.
await sara.goto(pageUrl(`view=day&date=${ym}-12`), { waitUntil: 'networkidle' });
text = await sara.innerText('body');
check(/07:45/.test(text) && /15:20/.test(text) && /7:35/.test(text), 'Check In + Unknown punches give check-in, check-out and the time between');
check(!/Unknown/.test(text), 'the word Unknown is not shown in the punch details');

await sara.goto(pageUrl(`view=day&date=${ym}-03`), { waitUntil: 'networkidle' });
text = await sara.innerText('body');
check(/Side Door/.test(text) && /All Punches/.test(text), 'day details: all punches');

// My Punches: the user's own punches by fingerprint and face, per month and per device.
await sara.goto(pageUrl('view=punches'), { waitUntil: 'networkidle' });
text = await sara.innerText('body');
check(/Fingerprint/.test(text) && /Face/.test(text) && /Main Gate/.test(text) && /Punches per Month/.test(text), 'punches: by verification, month and device');
check(!/OTHER-EMPLOYEE/.test(await sara.content()), 'punches: no other employee\'s data');
const years = await sara.locator('#bt-y-punches option').allInnerTexts();
check(years.at(-1) === '2025', `year lists start at 2025 (${years.join(',')})`);

// Removed pages are gone.
for (const view of ['schedules', 'reports', 'profile']) {
  const r = await sara.goto(pageUrl(`view=${view}`), { waitUntil: 'networkidle' });
  check(r.status() === 404, `removed page ${view}: HTTP ${r.status()}`);
}

// IDOR attempts: URL values never choose the employee.
await sara.goto(pageUrl(`view=attendance&month=${ym}&emp_code=FIXTURE2&employee_id=2`), { waitUntil: 'networkidle' });
check(!/OTHER-EMPLOYEE|06:06/.test(await sara.content()), 'IDOR: emp_code/employee_id in the URL are ignored');
const bad = await sara.goto(pageUrl('view=day&date=2026-13-45'), { waitUntil: 'networkidle' });
check(bad.status() === 404, `invalid day is "not found" (HTTP ${bad.status()})`);

// Phone width: no horizontal page scrolling.
await sara.setViewportSize({ width: 390, height: 844 });
for (const view of [...views, 'day']) {
  await sara.goto(pageUrl(view === 'day' ? `view=day&date=${ym}-03` : `view=${view}&month=${ym}`), { waitUntil: 'networkidle' });
  const o = await overflow(sara);
  check(o <= 0, `phone ${view}: no horizontal overflow (${o}px)`);
  await sara.screenshot({ path: `${OUT}/portal-${view}-phone.png`, fullPage: true });
}
await saraCtx.close();

// Omar is matched by e-mail and sees his own punch (the one hidden from Sara).
const { ctx: omarCtx, page: omar } = await login('omar');
await omar.goto(pageUrl(`view=day&date=${ym}-04`), { waitUntil: 'networkidle' });
const omarDay = await omar.innerText('body');
check(omarDay.includes('06:06'), 'omar matched by e-mail sees his own punch');
check(/06:06/.test(omarDay) && /Missing Punch/.test(omarDay) || new Date(`${ym}-04`) >= new Date(new Date().toDateString()), 'a single punch of unknown state is the check-in, with a missing punch');
await omarCtx.close();

for (const user of ['shared', 'nobody']) {
  const { ctx, page } = await login(user);
  await page.goto(pageUrl('view=dashboard'), { waitUntil: 'networkidle' });
  check(/could not be matched/.test(await page.innerText('body')), `${user}: friendly "could not be matched" page`);
  await page.screenshot({ path: `${OUT}/portal-unmatched-${user}.png`, fullPage: true });
  await ctx.close();
}

// ---------------------------------------------------------------- administrator: mappings
await admin.goto(`${mappingsUrl}&filter[status]=&filter[search]=`, { waitUntil: 'networkidle' });
const list = await admin.innerText('#adminForm');
check(/FIXTURE1/.test(list) && /Active/.test(list) && /Ambiguous/.test(list) && /Not found/.test(list), 'mappings list shows statuses');
check((await untranslated(admin)).length === 0, 'mappings page translated');
await admin.screenshot({ path: `${OUT}/admin-mappings.png`, fullPage: true });

async function mapUser(username, code) {
  // The Joomla user id, from the Users list (the user field is a modal picker).
  const users = await admin.evaluate(async (u) => (await fetch(`index.php?option=com_users&view=users&filter[search]=username:${u}`)).text(), username);
  // The row of exactly this username (the page header also links to the admin's own account).
  const row = users.split('<tr').find((r) => new RegExp(`>\\s*${username}\\s*<`).test(r)) ?? '';
  const id = (row.match(/task=user\.edit(?:&amp;|&)id=(\d+)/) ?? [])[1] ?? '';
  await admin.goto(`${BASE}/administrator/index.php?option=com_biotime&view=usermap&layout=edit`, { waitUntil: 'networkidle' });
  await admin.fill('#jform_emp_code', code);
  await Promise.all([
    admin.waitForNavigation({ waitUntil: 'networkidle' }),
    admin.evaluate((v) => {
      document.querySelector('input[name="jform[joomla_user_id]"]').value = v;
      const f = document.getElementById('adminForm');
      f.task.value = 'usermap.save';
      f.submit();
    }, id),
  ]);
  return admin.locator('#system-message-container').innerText();
}
check(/already mapped/.test(await mapUser('nobody', 'FIXTURE1')), 'duplicate mapping refused');
check(/is now mapped/.test(await mapUser('shared', 'FIXTURE3')), 'manual mapping by emp_code');
const { ctx: sharedCtx, page: shared } = await login('shared');
await shared.goto(pageUrl('view=dashboard'), { waitUntil: 'networkidle' });
check(!/could not be matched/.test(await shared.innerText('body')), 'manually mapped user sees their dashboard');
await sharedCtx.close();

// Re-match: the mapping is deleted and automatic matching runs on the next visit (also makes this
// test repeatable: "shared" is ambiguous again afterwards).
await admin.goto(`${mappingsUrl}&filter[status]=&filter[search]=shared`, { waitUntil: 'networkidle' });
await admin.locator('input[name="cid[]"]').first().check();
await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle' }), admin.click('#toolbar-rematch button, button.button-rematch')]);
check(/matched again/.test(await admin.locator('#system-message-container').innerText()), 'Re-match deletes the mapping');
await admin.goto(`${mappingsUrl}&filter[status]=&filter[search]=`, { waitUntil: 'networkidle' });

await browser.close();
console.log(failures.length ? `\n${failures.length} FAILED` : '\nALL PASSED');
process.exit(failures.length ? 1 : 0);
