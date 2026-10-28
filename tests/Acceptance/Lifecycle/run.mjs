// End-to-end self-host lifecycle acceptance against real MariaDB, a real
// packaged runtime served by separate PHP processes, and a real browser.
//
//   node tests/Acceptance/Lifecycle/run.mjs <built-runtime-release>
//
// Build/CI acceptance only. See docs/release-readiness/self-host-lifecycle.md.
import { execFileSync, spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, openSync, rmSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';
import { Browser } from './browser.mjs';

const here = fileURLToPath(new URL('.', import.meta.url));
const harnessPath = join(here, 'harness.php');
const runtime = resolve(process.argv[2] ?? '');
if (!process.argv[2] || !existsSync(join(runtime, 'VERSION'))) {
    console.error(
        'Usage: node tests/Acceptance/Lifecycle/run.mjs <built-runtime-release>',
    );
    process.exit(2);
}
const php = process.env.LIFECYCLE_PHP ?? 'php';
const db = {
    host: process.env.LIFECYCLE_DB_HOST ?? '127.0.0.1',
    port: process.env.LIFECYCLE_DB_PORT ?? '3306',
    username: process.env.LIFECYCLE_DB_USERNAME ?? 'seo_e2e',
    password: process.env.LIFECYCLE_DB_PASSWORD ?? 'e2e-secret',
};
const work = mkdtempSync(join(tmpdir(), 'seo-lifecycle-'));
const keepArtifacts = process.env.LIFECYCLE_KEEP === '1';
const children = new Set();
const OWNER = {
    name: 'مالک سامانه',
    email: 'owner@example.test',
    password: 'Lifecycle#Owner-2026!',
    workspace: 'آژانس سئو تهران',
};

function log(message) {
    console.log(`[lifecycle] ${message}`);
}

function assert(condition, message, evidence) {
    if (!condition) {
        const detail =
            evidence === undefined
                ? ''
                : `\n${JSON.stringify(evidence, null, 2)}`;
        throw new Error(`Assertion failed: ${message}${detail}`);
    }
}

function harness(...args) {
    const output = execFileSync(php, [harnessPath, ...args], {
        env: {
            ...process.env,
            LIFECYCLE_DB_HOST: db.host,
            LIFECYCLE_DB_PORT: db.port,
            LIFECYCLE_DB_USERNAME: db.username,
            LIFECYCLE_DB_PASSWORD: db.password,
        },
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024,
        stdio: ['ignore', 'pipe', 'inherit'],
    });
    return JSON.parse(output.trim().split('\n').pop());
}

async function freePort() {
    return new Promise((resolvePort, reject) => {
        const server = createServer();
        server.once('error', reject);
        server.listen(0, '127.0.0.1', () => {
            const { port } = server.address();
            server.close(() => resolvePort(port));
        });
    });
}

// The environment a shared host would give the web server: no Composer,
// Node, application secrets or DB_* values from this test runner.
function hostEnvironment() {
    return {
        PATH: process.env.PATH,
        HOME: process.env.HOME ?? '/tmp',
        LANG: 'C.UTF-8',
    };
}

class Instance {
    constructor(name, database) {
        this.name = name;
        this.database = database;
        this.root = join(work, name);
        this.server = null;
        this.background = [];
    }

    async create() {
        harness('db:reset', this.database);
        harness('instance', runtime, this.root);
        this.port = await freePort();
        this.base = `http://127.0.0.1:${this.port}`;
        await this.start();
        return this;
    }

    async start() {
        const logFile = join(work, `${this.name}-server.log`);
        this.server = spawn(
            php,
            // Hosts must allow uploads at least as large as a release package (documented
            // operator requirement); PHP's 2M/8M defaults are too small.
            [
                '-d',
                'upload_max_filesize=256M',
                '-d',
                'post_max_size=256M',
                '-S',
                `127.0.0.1:${this.port}`,
                '-t',
                'public',
            ],
            {
                cwd: this.root,
                env: hostEnvironment(),
                stdio: ['ignore', 'ignore', openSync(logFile, 'a')],
            },
        );
        this.serverLog = logFile;
        children.add(this.server);
        for (let attempt = 0; attempt < 100; attempt++) {
            try {
                const response = await fetch(`${this.base}/up`, {
                    redirect: 'manual',
                });
                if (response.status > 0) return;
            } catch {
                // server still starting
            }
            await sleep(100);
        }
        throw new Error(`${this.name} server did not start`);
    }

    async stop() {
        if (!this.server) return;
        const exited = new Promise((done) => this.server.once('exit', done));
        this.server.kill('SIGKILL');
        await exited;
        children.delete(this.server);
        this.server = null;
    }

    // Cron/worker equivalents a host control panel would run.
    artisan(...args) {
        return execFileSync(php, ['artisan', ...args], {
            cwd: this.root,
            env: hostEnvironment(),
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
            timeout: 120000,
        });
    }

    startOperations() {
        const loop = (args, delay) => {
            let stopped = false;
            const run = async () => {
                while (!stopped) {
                    await new Promise((done) => {
                        const child = spawn(php, ['artisan', ...args], {
                            cwd: this.root,
                            env: hostEnvironment(),
                            stdio: 'ignore',
                        });
                        children.add(child);
                        child.once('exit', () => {
                            children.delete(child);
                            done();
                        });
                    });
                    await sleep(delay);
                }
            };
            const promise = run();
            return {
                stop: async () => {
                    stopped = true;
                    await promise;
                },
            };
        };
        // Exactly the commands the installer tells the operator to schedule.
        this.background.push(loop(['schedule:run', '--no-interaction'], 1000));
        this.background.push(
            loop(
                [
                    'queue:work',
                    'database',
                    '--stop-when-empty',
                    '--max-time=50',
                    '--tries=3',
                    '--no-interaction',
                ],
                500,
            ),
        );
    }

    async stopOperations() {
        await Promise.all(this.background.map((job) => job.stop()));
        this.background = [];
    }

    inspect() {
        return harness('inspect', this.root);
    }
}

async function installThroughBrowser(browser, instance, owner) {
    log(`${instance.name}: browser installation`);
    await browser.goto(`${instance.base}/`);
    await browser.waitForUrl(
        (url) => url.endsWith('/install'),
        'redirect to installer',
    );
    await browser.waitForText('میزبان آماده است');
    const direction = await browser.evaluate(
        'getComputedStyle(document.querySelector("main") ?? document.body).direction',
    );
    assert(direction === 'rtl', 'installer renders right-to-left', {
        direction,
    });

    await browser.fill('#app_url', instance.base);
    await browser.fill('#database_driver', 'mariadb');
    await browser.fill('#database_host', db.host);
    await browser.fill('#database_port', db.port);
    await browser.fill('#database_name', instance.database);
    await browser.fill('#database_username', db.username);
    await browser.fill('#database_password', db.password);
    await browser.click('ذخیره امن تنظیمات');
    await browser.waitForText('تست اتصال دیتابیس');
    await browser.click('تست اتصال دیتابیس');
    await browser.waitForText('مرحله ۴ — ساختار دیتابیس');
    for (
        let batch = 0;
        batch < 20 && !(await browser.text()).includes('#owner_name'.slice(1));
        batch++
    ) {
        if (await browser.evaluate('!!document.querySelector("#owner_name")'))
            break;
        await browser.click('اجرای migrations');
        await browser.waitFor(
            async () =>
                (await browser.evaluate(
                    '!!document.querySelector("#owner_name")',
                )) ||
                (await browser.evaluate(
                    '![...document.querySelectorAll("button")].some((b) => b.disabled && b.innerText.includes("migrations"))',
                )),
            'migration batch',
            120000,
        );
    }
    await browser.waitFor(
        () => browser.evaluate('!!document.querySelector("#owner_name")'),
        'owner form',
        120000,
    );
    await browser.fill('#owner_name', owner.name);
    await browser.fill('#owner_email', owner.email);
    await browser.fill('#workspace_name', owner.workspace);
    await browser.fill('#owner_password', owner.password);
    await browser.fill('#owner_password_confirmation', owner.password);
    await browser.click('ساخت مالک و فضای کاری');
    await browser.waitForText('مرحله ۶');
    instance.startOperations();
    await browser.click('ارسال Queue Probe');
    let polls = 0;
    await browser.waitFor(
        async () => {
            const text = await browser.text();
            if (
                !text.includes('در انتظار مشاهده') &&
                !text.includes('هنوز probe تکمیل')
            )
                return true;
            if (++polls % 15 === 0)
                await browser.click('بررسی دوباره وضعیت اجرا');
            return false;
        },
        'scheduler heartbeat and queue probe observed',
        120000,
    );
    await browser.click('ادامه به نهایی‌سازی');
    await browser.waitForText('مرحله ۷');
    await browser.click('نهایی‌سازی و ورود');
    await browser.waitForUrl(
        (url) => !url.includes('/install'),
        'authenticated handoff',
        60000,
    );
    const state = instance.inspect();
    assert(
        state.installed?.installation_id,
        'installation lock written last with identity',
        state,
    );
    assert(
        state.counts.users === 1 && state.counts.workspaces === 1,
        'exactly one owner and workspace',
        state.counts,
    );
    const html = await browser.evaluate(
        'document.documentElement.lang + "|" + document.documentElement.dir',
    );
    assert(html === 'fa|rtl', 'installed application is Persian/RTL', { html });
    log(
        `${instance.name}: installed ${state.installed.product_version} (${state.installed.installation_id})`,
    );
    return state;
}

const instances = [];
const fixtures = join(here, 'fixtures');

// Target releases are built the way a release host would: from the runtime
// tree, with an additional nullable column migration and a new hashed asset.
function buildTarget(name, version, scenario, previous = null) {
    const root = join(work, 'targets', name);
    mkdirSync(join(work, 'targets'), { recursive: true });
    const target = harness(
        'target',
        runtime,
        root,
        version,
        scenario,
        ...(previous ? [previous.root] : []),
    );
    target.fingerprint = harness(
        'fingerprint',
        root,
        'seo_e2e_fingerprint',
    ).fingerprint;
    return target;
}

function buildPackage(
    target,
    sourceVersion,
    sourceFingerprint,
    targetFingerprint = target.fingerprint,
) {
    const output = join(
        work,
        `${target.version}-${createHash('sha256')
            .update(target.root + targetFingerprint)
            .digest('hex')
            .slice(0, 8)}.seoupdate`,
    );
    return harness(
        'package',
        target.root,
        output,
        sourceVersion,
        sourceFingerprint,
        targetFingerprint,
    );
}

async function confirmPasswordIfAsked(browser, owner) {
    const asked = () =>
        browser.evaluate(
            '!!document.querySelector("form input#password") && !document.querySelector("input#email")',
        );
    await browser.waitFor(
        async () =>
            (await asked()) || (await browser.text()).includes('وضعیت سرور'),
        'update page or password confirmation',
    );
    if (await asked()) {
        await browser.fill('#password', owner.password);
        await browser.evaluate(
            'document.querySelector("form").requestSubmit()',
        );
        await browser.waitFor(
            async () => !(await asked()),
            'password confirmation',
        );
    }
}

async function login(browser, instance, owner) {
    await browser.goto(`${instance.base}/login`);
    await browser.fill('#email', owner.email);
    await browser.fill('#password', owner.password);
    await browser.evaluate('document.querySelector("form").requestSubmit()');
    await browser.waitForUrl((url) => !url.includes('/login'), 'login', 30000);
}

async function openUpdatePage(browser, instance, owner) {
    await browser.goto(`${instance.base}/settings/update`);
    await confirmPasswordIfAsked(browser, owner);
    if (!(await browser.url()).endsWith('/settings/update')) {
        await browser.goto(`${instance.base}/settings/update`);
    }
    await browser.waitForText('وضعیت سرور');
}

async function uploadAndConfirm(browser, instance, owner, pkg, targetVersion) {
    await openUpdatePage(browser, instance, owner);
    await browser.waitForText('آماده دریافت بسته');
    for (let attempt = 0; attempt < 4; attempt++) {
        await browser.setFile('#update-package', pkg.package);
        await browser.click('بارگذاری و بررسی بسته');
        await browser.waitFor(
            async () => {
                const text = await browser.text();
                return (
                    text.includes(pkg.sha256) ||
                    text.includes('Too Many Attempts') ||
                    (await browser.url()).includes('confirm-password')
                );
            },
            'package verification',
            120000,
        );
        if ((await browser.text()).includes(pkg.sha256)) break;
        if ((await browser.url()).includes('confirm-password')) {
            // A stale password confirmation (423) sends the owner to re-authenticate.
            await confirmPasswordIfAsked(browser, owner);
            await browser.waitForUrl(
                (url) => url.endsWith('/settings/update'),
                'return to update page',
            );
        } else {
            // Package verification is deliberately limited to three attempts per minute.
            await sleep(61000);
            await openUpdatePage(browser, instance, owner);
        }
        await browser.waitForText('وضعیت سرور');
    }
    await browser.waitForText(pkg.sha256);
    const preview = await browser.text();
    assert(
        preview.includes(targetVersion),
        'preview shows the target version',
        { targetVersion },
    );
    assert(
        preview.includes('امضای دیجیتال نیست'),
        'integrity is not presented as publisher authenticity',
    );
    await browser.fill('#update-digest', pkg.sha256);
    await browser.click('شروع به‌روزرسانی');
}

async function waitForStatus(browser, fragment, timeoutMs = 120000) {
    let polls = 0;
    await browser.waitFor(
        async () => {
            const text = await browser.text();
            if (text.includes(fragment)) return true;
            if (++polls % 20 === 0) {
                // The dependency-free launcher answers a failed boot with a bare 503;
                // an operator reloads the update page, as the browser does here.
                if (text.startsWith('Application update'))
                    await browser.goto(
                        (await browser.url()).replace(
                            /\/settings\/update.*$/,
                            '/settings/update',
                        ),
                    );
                else await browser.click('بررسی دوباره وضعیت').catch(() => {});
            }
            return false;
        },
        `update status "${fragment}"`,
        timeoutMs,
    );
}

async function assertApplicationServes(browser, instance, projectId, keyword) {
    await browser.goto(`${instance.base}/projects/${projectId}/keywords`);
    await browser.waitForText(keyword, 30000);
    const html = await browser.evaluate(
        'document.documentElement.lang + "|" + document.documentElement.dir',
    );
    assert(html === 'fa|rtl', 'keyword grid renders Persian/RTL', { html });
}

async function updateLifecycle(browser, a, seeded) {
    const before = a.inspect();
    const sourceVersion = before.installed.product_version;
    const sourceFingerprint = harness(
        'fingerprint',
        runtime,
        'seo_e2e_fingerprint',
    ).fingerprint;

    // 1. Successful update through the Persian UI with live cron/worker running.
    log('update: successful source -> target');
    const t2 = buildTarget('alpha2', '0.1.0-alpha.2', 'ok');
    const p2 = buildPackage(t2, sourceVersion, sourceFingerprint);
    await uploadAndConfirm(browser, a, OWNER, p2, t2.version);
    await waitForStatus(browser, 'نسخه جدید راه‌اندازی شده');
    await browser.click('ادامه به‌روزرسانی');
    await browser.waitForText(
        'نسخه جدید ثبت شد و بررسی سلامت را گذراند.',
        180000,
    );
    let state = a.inspect();
    assert(
        state.installed.product_version === t2.version,
        'product version finalized',
        state,
    );
    assert(
        typeof state.pointer?.release_id === 'string' &&
            state.pointer?.product_version === t2.version,
        'active pointer is the target slot',
        state.pointer,
    );
    assert(
        state.project_columns.includes(t2.column),
        'target migration applied on MariaDB',
        state.project_columns,
    );
    assert(
        state.counts.keywords === before.counts.keywords &&
            state.counts.projects === before.counts.projects,
        'domain data preserved',
        state.counts,
    );
    assert(
        state.env_sha256 === before.env_sha256 &&
            state.installed.installation_id ===
                before.installed.installation_id,
        '.env and installation identity untouched',
        state,
    );
    assert(
        state.journal_phase === null,
        'journal closed after completion',
        state,
    );
    const asset = await fetch(`${a.base}/build/assets/${t2.asset}`);
    assert(
        asset.status === 200,
        'target public asset served from stable public root',
    );
    await assertApplicationServes(browser, a, seeded.project_id, 'سئو سایت');

    // 2. Target that cannot boot: the launcher must restore the source release.
    log('update: target bootstrap failure');
    const brokenFingerprint = sourceFingerprint; // unused by a target that never boots
    const t3broken = buildTarget(
        'alpha3-broken',
        '0.1.0-alpha.3',
        'broken-boot',
        t2,
    );
    const p3broken = buildPackage(
        t3broken,
        t2.version,
        t2.fingerprint,
        t3broken.fingerprint ?? brokenFingerprint,
    );
    await uploadAndConfirm(browser, a, OWNER, p3broken, t3broken.version);
    await waitForStatus(browser, 'بازیابی خودکار قابل اثبات نیست');
    state = a.inspect();
    assert(
        state.pointer?.product_version === t2.version,
        'launcher reverted to the source release',
        state.pointer,
    );
    assert(
        !state.project_columns.includes(t3broken.column),
        'no target migration ran',
        state.project_columns,
    );
    await browser.click('ادامه بازگردانی ایمن');
    await finishRecovery(browser, a);
    state = a.inspect();
    assert(
        state.installed.product_version === t2.version &&
            state.journal_phase === null,
        'bootstrap failure recovered to source',
        state,
    );
    await assertApplicationServes(browser, a, seeded.project_id, 'سئو سایت');

    // 3. Post-migration health failure: exact suffix reversed, source snapshot applied.
    log('update: post-migration health failure');
    const t3 = buildTarget('alpha3', '0.1.0-alpha.3', 'ok', t2);
    const p3bad = buildPackage(t3, t2.version, t2.fingerprint, t2.fingerprint);
    const p3ok = buildPackage(t3, t2.version, t2.fingerprint);
    await uploadAndConfirm(browser, a, OWNER, p3bad, t3.version);
    await waitForStatus(browser, 'نسخه جدید راه‌اندازی شده');
    await browser.click('ادامه به‌روزرسانی');
    await waitForStatus(browser, 'نیازمند بازگردانی نسخه قبلی', 180000);
    await browser.click('ادامه بازگردانی ایمن');
    await finishRecovery(browser, a);
    state = a.inspect();
    assert(
        state.installed.product_version === t2.version,
        'rolled back to source version',
        state,
    );
    assert(
        !state.project_columns.includes(t3.column) &&
            state.project_columns.includes(t2.column),
        'exact target suffix reversed only',
        state.project_columns,
    );
    assert(
        state.counts.keywords === before.counts.keywords,
        'domain data intact after rollback',
        state.counts,
    );
    assert(
        state.env_sha256 === before.env_sha256,
        '.env untouched by rollback',
        state,
    );
    await assertApplicationServes(browser, a, seeded.project_id, 'سئو سایت');

    // 4. Hard process interruption while the target migration is running.
    log('update: hard interruption during migration');
    const t3slow = buildTarget('alpha3-slow', '0.1.0-alpha.3', 'slow', t2);
    const p3slow = buildPackage(
        t3slow,
        t2.version,
        t2.fingerprint,
        t3slow.fingerprint,
    );
    await uploadAndConfirm(browser, a, OWNER, p3slow, t3slow.version);
    await waitForStatus(browser, 'نسخه جدید راه‌اندازی شده');
    await browser.click('ادامه به‌روزرسانی');
    await sleep(4000);
    await a.stop(); // the web server process dies mid-request
    await a.start();
    await openUpdatePage(browser, a, OWNER);
    state = a.inspect();
    log(
        `update: after interruption journal=${state.journal_phase} columns=${state.project_columns.join(',')}`,
    );
    await resolveAfterInterruption(browser, a);
    state = a.inspect();
    // No DDL ran before the process died: exact rollback + snapshot restore is provable.
    assert(
        state.journal_phase === null &&
            state.installed.product_version === t2.version,
        'interrupted migration rolled back to source',
        state,
    );
    assert(
        !state.project_columns.includes(t3slow.column) &&
            state.project_columns.includes(t2.column),
        'schema is exactly the source schema',
        state.project_columns,
    );
    assert(
        state.counts.keywords === before.counts.keywords,
        'data intact after interrupted update',
        state.counts,
    );
    await assertApplicationServes(browser, a, seeded.project_id, 'سئو سایت');

    // 5. Pre-switch failure: active work blocks quiescence; the owner cancels and retries.
    log('update: pre-switch block, cancel and retry');
    await a.stopOperations();
    harness(
        'db:query',
        a.database,
        "INSERT INTO jobs (queue, payload, attempts, reserved_at, available_at, created_at) VALUES ('default', '{}', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP())",
    );
    const snapshotsBeforeBlock = a.inspect().safety_snapshots;
    await uploadAndConfirm(browser, a, OWNER, p3ok, t3.version);
    await waitForStatus(browser, 'لغو به‌روزرسانی');
    let html = (await fetch(`${a.base}/dashboard`, { redirect: 'manual' }))
        .status;
    assert(
        html === 503,
        'ordinary traffic is held in maintenance while blocked',
        { html },
    );
    await browser.click('لغو به‌روزرسانی');
    await waitForStatus(browser, 'آماده دریافت بسته');
    state = a.inspect();
    assert(
        state.journal_phase === null &&
            state.safety_snapshots === snapshotsBeforeBlock &&
            state.installed.product_version === t2.version,
        'cancel discards pre-switch artifacts and keeps the source active',
        state,
    );
    harness(
        'db:query',
        a.database,
        'DELETE FROM jobs WHERE reserved_at IS NOT NULL',
    );
    a.startOperations();
    await uploadAndConfirm(browser, a, OWNER, p3ok, t3.version);
    await waitForStatus(browser, 'نسخه جدید راه‌اندازی شده');

    // 6. Unexpected migration-history drift after the switch: fail closed, no guessing.
    log('update: migration history drift fails closed');
    harness(
        'db:query',
        a.database,
        "INSERT INTO migrations (migration, batch) VALUES ('2099_12_31_999999_foreign_drift', 99)",
    );
    await browser.click('ادامه به‌روزرسانی');
    await waitForStatus(browser, 'بازیابی خودکار قابل اثبات نیست');
    assert(
        !(await browser.hasControl('ادامه بازگردانی ایمن')),
        'no automatic recovery is offered for ambiguous drift',
    );
    assert(
        (await browser.text()).includes('ادامه خودکار مسدود است'),
        'operator is told to retain artifacts and escalate',
    );
    state = a.inspect();
    assert(
        state.journal_phase === 'recovery_required' &&
            state.safety_snapshots >= 1,
        'artifacts retained under recovery_required',
        state,
    );
    html = (await fetch(`${a.base}/dashboard`, { redirect: 'manual' })).status;
    assert(html === 503, 'application stays fail-closed', { html });
    return state;
}

// Drive "continue safe rollback" until the server reports a terminal state,
// re-authenticating when the rolled-back source invalidates the session.
async function finishRecovery(browser, instance) {
    for (let step = 0; step < 6; step++) {
        await sleep(1500);
        const url = await browser.url();
        if (url.includes('/login')) {
            await login(browser, instance, OWNER);
            await openUpdatePage(browser, instance, OWNER);
        }
        await confirmPasswordIfAsked(browser, OWNER);
        if (!(await browser.url()).includes('/settings/update'))
            await openUpdatePage(browser, instance, OWNER);
        const text = await browser.text();
        if (text.includes('آماده دریافت بسته')) return;
        if (await browser.hasControl('ادامه بازگردانی ایمن')) {
            await browser.click('ادامه بازگردانی ایمن');
            continue;
        }
        if (text.includes('بازیابی خودکار قابل اثبات نیست'))
            throw new Error('Recovery stopped in recovery_required');
    }
    throw new Error(
        `Recovery did not reach a terminal state: ${(await browser.text()).slice(0, 800)}`,
    );
}

async function resolveAfterInterruption(browser, instance) {
    const text = await browser.text();
    if (await browser.hasControl('ادامه بازگردانی ایمن')) {
        await browser.click('ادامه بازگردانی ایمن');
        await finishRecovery(browser, instance);
        return;
    }
    if (await browser.hasControl('ادامه به‌روزرسانی')) {
        await browser.click('ادامه به‌روزرسانی');
        await sleep(3000);
        await openUpdatePage(browser, instance, OWNER);
        if (await browser.hasControl('ادامه بازگردانی ایمن')) {
            await browser.click('ادامه بازگردانی ایمن');
            await finishRecovery(browser, instance);
        }
        return;
    }
    log(`update: interruption left page state: ${text.slice(0, 400)}`);
}

async function main() {
    const browser = await Browser.launch();
    try {
        const a = await new Instance('instance-a', 'seo_e2e_a').create();
        instances.push(a);
        await installThroughBrowser(browser, a, OWNER);
        const seeded = harness(
            'seed',
            a.root,
            join(fixtures, 'keywords-fa.csv'),
        );
        assert(
            seeded.keywords >= 30,
            'realistic keyword set committed',
            seeded,
        );
        await updateLifecycle(browser, a, seeded);
        if (browser.consoleErrors.length > 0)
            log(`browser exceptions: ${browser.consoleErrors.join(' | ')}`);
    } finally {
        await browser.close();
    }
}

try {
    await main();
    log('PASS');
} catch (error) {
    console.error(error.stack ?? error);
    process.exitCode = 1;
} finally {
    await Promise.all(
        instances.map((instance) => instance.stopOperations().catch(() => {})),
    );
    for (const child of children) child.kill('SIGKILL');
    if (!keepArtifacts)
        rmSync(work, {
            recursive: true,
            force: true,
            maxRetries: 5,
            retryDelay: 200,
        });
    else log(`artifacts kept in ${work}`);
}
