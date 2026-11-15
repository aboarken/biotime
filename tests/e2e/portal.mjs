// End-to-end check of the portal and the User Mappings page against a Joomla site with the
// component installed, tests/e2e/fake-zkbiotime.php serving on FAKE_URL, and these test users
// (all Registered, password USER_PASS): sara (custom field employeeid = FIXTURE1),
// omar (omar@example.test), shared (shared@example.test, two employees), nobody.
// The seven zkb.* permissions must be allowed for Registered.
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
await admin.fill('#jform_leave_path', '/att/api/fixture_leave/');
await admin.fill('#jform_leave_fields', 'emp_code = emp_code\nstart = start\nend = end\ntype = category\nduration = days\nstatus = approval');
await admin.fill('#jform_leave_status_map', 'Approved = approved');
// Repeated test runs export many reports; the per-user hourly limit is tested separately.
await admin.fill('#jform_export_per_hour', '1000');
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

// Invalid source refused.
await admin.fill('#jform_daily_extra', 'q = SELECT * FROM users');
await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle' }), admin.click('#toolbar-apply button, button.button-apply')]);
check(/fixed parameters must be/.test(await admin.locator('#system-message-container').innerText()), 'free-form query in a source refused');
await admin.goto(settingsUrl, { waitUntil: 'networkidle' });
await admin.fill('#jform_daily_extra', '');
await admin.fill('#jform_api_password', 'Fixture-Pass-123');
await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle' }), admin.click('#toolbar-apply button, button.button-apply')]);

// Menu item types.
await admin.goto(`${BASE}/administrator/index.php?option=com_menus&view=menutypes&tmpl=component&client_id=0&menutype=mainmenu`, { waitUntil: 'networkidle' });
const types = await admin.evaluate(() => document.body.textContent);
for (const t of ['My Dashboard', 'My Attendance', 'Attendance Calendar', 'My Schedules', 'Attendance Reports', 'My Profile']) {
  check(types.includes(t), `menu item type "${t}"`);
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

const views = ['dashboard', 'attendance', 'calendar', 'schedules', 'reports', 'profile'];
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
check(text.includes('Day Shift') && text.includes('Late Shift'), 'attendance: schedule applied per day');
check(text.includes('07:58') && text.includes('17:02'), 'attendance: check-in / check-out');
check(text.includes('0:20') && text.includes('2:30'), 'attendance: late-in 0:20 and early-out 2:30 from ZKBioTime');
check(/Duplicate punch/.test(text), 'attendance: duplicate punch exception');
check(/Absent/.test(text) && /Rest Day/.test(text), 'attendance: absent and rest day statuses');
await sara.goto(pageUrl(`view=attendance&month=${ym}&status=late`), { waitUntil: 'networkidle' });
check((await sara.locator('table tbody tr').count()) === 1, 'attendance: status filter (late) shows one day');

await sara.goto(pageUrl(`view=dashboard&month=${ym}`), { waitUntil: 'networkidle' });
text = await sara.innerText('body');
check(/Present Days/.test(text) && /Late-In Occurrences/.test(text) && /Worked Hours/.test(text), 'dashboard: summary cards');
check((await sara.locator('svg.biotime-chart').count()) === 1, 'dashboard: chart');
check(/Recent Activity/.test(text) && /Updated \d\d:\d\d/.test(text), 'dashboard: recent activity and updated time');

// A day with only a check-out punch: the time is under Check-Out, never Check-In.
await sara.goto(pageUrl(`view=day&date=${ym}-09`), { waitUntil: 'networkidle' });
const facts = await sara.locator('dl.biotime-facts').innerText();
check(/Check-In\s*—/.test(facts) && /Check-Out\s*17:10/.test(facts), `check-out alone shown under Check-Out (${facts.replace(/\s+/g, ' ').slice(0, 200)})`);
check(/Missing check-in/.test(await sara.innerText('body')), 'check-out alone: missing check-in exception');

await sara.goto(pageUrl(`view=day&date=${ym}-03`), { waitUntil: 'networkidle' });
text = await sara.innerText('body');
check(/Late Shift/.test(text) && /Side Door/.test(text) && /All Punches/.test(text), 'day details: schedule and all punches');

await sara.goto(pageUrl(`view=profile`), { waitUntil: 'networkidle' });
text = await sara.innerText('body');
check(text.includes('FIXTURE1') && text.includes('Finance') && text.includes('Accountant'), 'profile: own record');
check(!/44556677|1112223334/.test(await sara.content()), 'profile: no card number or national id');

await sara.goto(pageUrl(`view=reports&mode=month&month=${ym}`), { waitUntil: 'networkidle' });
text = await sara.innerText('body');
check(/Late-In Report/.test(text) && /Leave Summary/.test(text) && /Annual Leave/.test(text), 'reports: sections and leave');
for (const [label, magic] of [['Download PDF', '%PDF'], ['Download Excel', 'PK']]) {
  const [dl] = await Promise.all([sara.waitForEvent('download'), sara.click(`button:has-text("${label}")`)]);
  const file = `${OUT}/export-${magic === 'PK' ? 'xlsx' : 'pdf'}`;
  await dl.saveAs(file);
  const head = fs.readFileSync(file).subarray(0, 4).toString('latin1');
  const body = fs.readFileSync(file).toString('latin1');
  check(head.startsWith(magic), `export ${label}: file type`);
  check(!body.includes('OTHER-EMPLOYEE'), `export ${label}: no other employee's data`);
}
await sara.goto(pageUrl(`view=reports&mode=range&from=${ym}-01&to=2099-12-31`), { waitUntil: 'networkidle' });
check(/too long/.test(await sara.innerText('body')), 'reports: range limit');

// CSRF: an export without the token is refused.
const csrf = await sara.request.post(pageUrl('task=report.export'), { form: { format: 'pdf', mode: 'month', month: ym } });
check(!(csrf.headers()['content-type'] ?? '').includes('pdf'), 'export without CSRF token refused');

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
check(/Incomplete punch record/.test(omarDay) || new Date(`${ym}-04`) >= new Date(new Date().toDateString()), 'a single punch of unknown state is an incomplete record');
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
await shared.goto(pageUrl('view=profile'), { waitUntil: 'networkidle' });
check((await shared.innerText('body')).includes('FIXTURE3'), 'manually mapped user sees their profile');
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
