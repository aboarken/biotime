// End-to-end check of the administrator pages against a running Joomla with the component
// installed and tests/e2e/fake-zkbiotime.php serving on FAKE_URL.
//
//   BASE_URL=http://127.0.0.1:8090 ADMIN_USER=admin ADMIN_PASS=... OUT=tests/output node tests/e2e/admin-settings.mjs
//
// Checks: page renders without untranslated COM_BIOTIME_ strings, Save validates and never
// echoes secrets, Test Connection succeeds and fails as expected, Close and Options exist,
// no horizontal page overflow at 390 px. Writes screenshots to OUT.
import { createRequire } from 'node:module';
const { chromium } = createRequire(import.meta.url)('playwright');
import fs from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8090';
const FAKE = process.env.FAKE_URL ?? 'http://127.0.0.1';
const FAKE_PORT = process.env.FAKE_PORT ?? '18081';
const OUT = process.env.OUT ?? 'tests/output';
const PASSWORD = 'Fixture-Pass-123';
fs.mkdirSync(OUT, { recursive: true });

const failures = [];
const check = (ok, what) => { console.log(`${ok ? 'PASS' : 'FAIL'} ${what}`); if (!ok) failures.push(what); };

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1366, height: 900 }, deviceScaleFactor: 2 });

await page.goto(`${BASE}/administrator/index.php`);
await page.fill('#mod-login-username', process.env.ADMIN_USER ?? 'admin');
await page.fill('#mod-login-password', process.env.ADMIN_PASS ?? '');
await Promise.all([page.waitForURL((u) => !u.toString().includes('login'), { waitUntil: 'networkidle' }).catch(() => {}), page.click('#btn-login-submit')]);
await page.waitForSelector('#header, .header', { timeout: 15000 });

const settingsUrl = `${BASE}/administrator/index.php?option=com_biotime&view=integrationsettings`;
const messages = async () => (await page.locator('#system-message-container').innerText().catch(() => '')).trim();
const untranslated = async () => (await page.content()).match(/COM_BIOTIME_[A-Z0-9_]+/g) ?? [];
const clickTask = async (selector) => { await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click(selector)]); };
const save = () => clickTask('#toolbar-apply button, button.button-apply');
const test = () => clickTask('#toolbar-test button, button.button-test');

await page.goto(settingsUrl, { waitUntil: 'networkidle' });
await page.waitForSelector('#jform_server_url');
check((await untranslated()).length === 0, `no untranslated strings (${(await untranslated()).join(',')})`);
check(await page.locator('#toolbar-test button, button.button-test').count() === 1, 'Test Connection button');
check((await page.locator('#toolbar-apply').innerText()).trim() === 'Save', 'Save button says Save');
check(await page.locator('#toolbar-readpin button, button.button-readpin').count() === 1, 'Read Certificate Pin button');
check(await page.locator('#toolbar-close a, #toolbar-close button, .button-close').count() >= 1, 'Close button');
check(await page.locator('#toolbar-options button, #toolbar-options a, .button-options').count() >= 1, 'Options button');
check((await page.innerText('body')).includes('Not set'), 'unset secrets shown as Not set');
await page.screenshot({ path: `${OUT}/settings-empty.png`, fullPage: true });

// Validation: wrong port and missing password.
await page.fill('#jform_server_url', `${FAKE}`);
await page.fill('#jform_server_port', '99999');
await page.fill('#jform_api_username', 'portal-api');
await page.check('#jform_clear_password');
await save();
const errorText = await messages();
check(/port must be a number/i.test(errorText), 'invalid port refused');
check(/JWT needs an API username and password/i.test(errorText), 'missing password refused');

// Valid save.
await page.fill('#jform_server_url', `${FAKE}`);
await page.fill('#jform_server_port', FAKE_PORT);
await page.fill('#jform_api_username', 'portal-api');
await page.fill('#jform_api_password', PASSWORD);
await save();
check(/Settings saved/.test(await messages()), 'settings saved');
const html = await page.content();
check(!html.includes(PASSWORD), 'password never sent back to the browser');
check(!html.includes('v1:'), 'ciphertext never sent to the browser');
check((await page.innerText('body')).includes('Stored (encrypted)'), 'password shown as Stored (encrypted)');
check(/plain http/i.test(await page.innerText('body')), 'plain http warning');

// Test connection succeeds.
await test();
const ok = await messages();
check(/Connected to ZKBioTime with JWT/.test(ok) && /can read 3 employees/.test(ok), `test connection ok (${ok.replace(/\s+/g, ' ')})`);
await page.screenshot({ path: `${OUT}/settings-test-ok.png`, fullPage: true });

// Wrong password: authentication error with hint.
await page.fill('#jform_api_password', 'wrong-password');
await save();
await test();
const bad = await messages();
check(/did not accept the portal's credentials/.test(bad) && /Check the API username/.test(bad), `wrong password reported (${bad.replace(/\s+/g, ' ')})`);
check(!(await page.content()).includes('wrong-password'), 'wrong password not echoed');

// Unreachable port: network error.
await page.fill('#jform_api_password', PASSWORD);
await page.fill('#jform_server_port', '18999');
await save();
await test();
check(/could not be reached/.test(await messages()), 'unreachable server reported');
await page.screenshot({ path: `${OUT}/settings-test-network-error.png`, fullPage: true });

// Connect via: the server URL's name does not resolve; only the "connect via" address reaches
// the server. The stand-in server logs the Host header it receives (FAKE_LOG).
await page.fill('#jform_server_url', 'http://zkb.mysite.invalid');
await page.fill('#jform_server_port', FAKE_PORT);
await page.fill('#jform_connect_via', 'https://wrong.example');
await save();
check(/Connect via must be a host name/.test(await messages()), 'invalid connect via refused');
await page.fill('#jform_connect_via', `127.0.0.1:${FAKE_PORT}`);
await save();
await test();
const via = await messages();
check(/Connected to ZKBioTime with JWT/.test(via), `test connection through connect via (${via.replace(/\s+/g, ' ')})`);
if (process.env.FAKE_LOG) {
  const hosts = fs.readFileSync(process.env.FAKE_LOG, 'utf8');
  check(hosts.includes(`fake-zkbiotime Host: zkb.mysite.invalid:${FAKE_PORT} /jwt-api-token-auth/`), 'Host header keeps the server URL name');
}
await page.screenshot({ path: `${OUT}/settings-connect-via.png`, fullPage: true });
await page.fill('#jform_server_url', 'http://zkb.mysite.invalid');
await page.fill('#jform_connect_via', '');
await save();
await test();
check(/could not be reached/.test(await messages()), 'same name without connect via cannot be reached');

// Restore a working configuration.
await page.fill('#jform_server_url', `${FAKE}`);
await page.fill('#jform_server_port', FAKE_PORT);
await page.fill('#jform_connect_via', '');
await save();

// Phone width: no horizontal page scroll.
await page.setViewportSize({ width: 390, height: 844 });
await page.goto(settingsUrl, { waitUntil: 'networkidle' });
await page.waitForSelector('#jform_server_url');
const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
check(overflow <= 0, `no horizontal overflow at 390 px (${overflow}px)`);
await page.screenshot({ path: `${OUT}/settings-phone.png`, fullPage: true });

// Permissions tab lists the seven portal actions.
await page.setViewportSize({ width: 1366, height: 900 });
await page.goto(`${BASE}/administrator/index.php?option=com_config&view=component&component=com_biotime`);
const perms = await page.innerText('body');
for (const label of ['View My Dashboard', 'View My Attendance', 'View Attendance Calendar', 'View My Schedules', 'View Attendance Reports', 'Export Attendance Reports', 'View My Profile']) {
  check(perms.includes(label), `permission "${label}" listed`);
}
check((await untranslated()).length === 0, 'Options page has no untranslated strings');

// A GET to a task must not change anything (CSRF): expect a token error, not a save.
await page.goto(`${BASE}/administrator/index.php?option=com_biotime&task=settings.test`);
check(!/Connected to ZKBioTime/.test(await page.innerText('body')), 'task without token refused');

await browser.close();
console.log(failures.length ? `\n${failures.length} FAILED` : '\nALL PASSED');
process.exit(failures.length ? 1 : 0);
