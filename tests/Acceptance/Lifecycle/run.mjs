// End-to-end self-host lifecycle acceptance against real MariaDB, a real
// packaged runtime served by separate PHP processes, and a real browser.
//
//   node tests/Acceptance/Lifecycle/run.mjs <built-runtime-release>
//
// Build/CI acceptance only. See docs/release-readiness/self-host-lifecycle.md.
import { execFileSync, spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
    existsSync,
    mkdirSync,
    mkdtempSync,
    openSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
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
const DESTINATION_OWNER = {
    name: 'مالک مقصد',
    email: 'destination@example.test',
    password: 'Lifecycle#Destination-2026!',
    workspace: 'فضای کاری مقصد',
};
const PROJECT_NAME = 'example.ir';
const PLANNED_PAGE = 'برنامه سئو تهران';

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
    // No manual refresh exists: the page must re-read server truth itself.
    await browser.waitFor(
        async () => {
            const text = await browser.text();
            return (
                !text.includes('در انتظار مشاهده') &&
                !text.includes('هنوز probe تکمیل')
            );
        },
        'scheduler heartbeat and queue probe observed by automatic polling',
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

async function submitClosestForm(browser, selector) {
    const submitted = await browser.evaluate(`(() => {
        const element = document.querySelector(${JSON.stringify(selector)});
        const form = element?.closest('form');
        if (!form) return false;
        form.requestSubmit();
        return true;
    })()`);
    assert(submitted, `form exists for ${selector}`);
}

async function clickSelector(browser, selector) {
    await browser.waitFor(
        () =>
            browser.evaluate(
                `!!document.querySelector(${JSON.stringify(selector)})`,
            ),
        `selector ${selector}`,
    );
    const clicked = await browser.evaluate(`(() => {
        const element = document.querySelector(${JSON.stringify(selector)});
        if (!element || element.disabled) return false;
        element.click();
        return true;
    })()`);
    assert(clicked, `enabled selector ${selector}`);
}

async function createProjectThroughBrowser(browser, instance) {
    log(`${instance.name}: create project through dashboard UI`);
    await browser.goto(`${instance.base}/dashboard`);
    await browser.waitFor(
        () => browser.evaluate('!!document.querySelector("#project_name")'),
        'project creation form',
    );
    await browser.fill('#project_name', PROJECT_NAME);
    await submitClosestForm(browser, '#project_name');
    await browser.waitForUrl(
        (url) => /\/projects\/\d+/.test(url),
        'created project workspace',
    );
    const url = await browser.url();
    const match = url.match(/\/projects\/(\d+)/);
    assert(match, 'project id is visible in workspace URL', { url });
    await browser.waitForText(PROJECT_NAME);
    return Number(match[1]);
}

async function importKeywordsThroughBrowser(browser, instance, projectId) {
    log(`${instance.name}: upload, map, process, and commit CSV through UI`);
    const path = `/projects/${projectId}/imports`;
    await browser.goto(`${instance.base}${path}`);
    await browser.waitFor(
        () => browser.evaluate('!!document.querySelector("#import-file")'),
        'keyword import form',
    );
    await browser.setFile('#import-file', join(fixtures, 'keywords-fa.csv'));
    await submitClosestForm(browser, '#import-file');
    await browser.waitFor(
        () => browser.evaluate('!!document.querySelector("#keyword-column")'),
        'column mapping form',
    );
    const headers = await browser.evaluate(
        '[...document.querySelectorAll("#keyword-column option")].map((option) => option.value)',
    );
    assert(
        headers.includes('Keyword') && headers.includes('Search Volume'),
        'original CSV column identifiers are available for mapping',
        headers,
    );
    await browser.fill('#keyword-column', 'Keyword');
    await browser.fill('#volume-column', 'Search Volume');
    await submitClosestForm(browser, '#keyword-column');

    // Staging and commit progress arrive through automatic polling only.
    await browser.waitFor(
        async () => {
            if (
                await browser.evaluate(
                    `!![...document.querySelectorAll('form')].find((form) => (form.getAttribute('action') ?? '').endsWith('/commit'))`,
                )
            )
                return true;
            return false;
        },
        'staged import from real queue worker',
        120000,
    );
    const stagedText = await browser.text();
    assert(
        stagedText.includes('آماده بررسی و ثبت'),
        'server-truth staged status is visible before commit',
    );
    const commitSubmitted = await browser.evaluate(`(() => {
        const form = [...document.querySelectorAll('form')].find((candidate) => (candidate.getAttribute('action') ?? '').endsWith('/commit'));
        if (!form) return false;
        form.requestSubmit();
        return true;
    })()`);
    assert(
        commitSubmitted,
        'staged import commit form is submitted through UI',
    );

    await browser.waitFor(
        async () => {
            const text = await browser.text();
            if (text.includes('ثبت نهایی شد')) return true;
            return false;
        },
        'committed import from real queue worker',
        120000,
    );

    const state = instance.inspect();
    assert(
        state.counts.keywords >= 30,
        'realistic keyword set committed',
        state,
    );
    await assertApplicationServes(browser, instance, projectId, 'سئو سایت');
    await assertGuidedWorkflow(browser, instance, projectId);
    return { project_id: projectId, keywords: state.counts.keywords };
}

// The overview computes each SEO step from real data: after a real import
// the import step is done and a later step is offered as the next action.
async function assertGuidedWorkflow(browser, instance, projectId) {
    log(`${instance.name}: guided workflow reflects the real import`);
    await browser.goto(`${instance.base}/projects/${projectId}/overview`);
    await browser.waitForText('الان چه کار کنم؟');
    const steps = JSON.parse(
        await browser.evaluate(
            `JSON.stringify(Object.fromEntries([...document.querySelectorAll('[data-step]')].map((node) => [node.dataset.step, node.dataset.status])))`,
        ),
    );
    assert(
        steps.import === 'done' && steps.intent !== 'waiting',
        'guided workflow marks import done and unlocks the next step',
        steps,
    );
    assert(
        Object.keys(steps).length === 8,
        'guided workflow lists the eight SEO steps',
        steps,
    );
}

async function exerciseKeywordIntelligence(browser, instance, projectId) {
    log(`${instance.name}: keyword intelligence review and mapping through UI`);
    const keywordsUrl = `${instance.base}/projects/${projectId}/keywords`;
    await browser.goto(keywordsUrl);
    await browser.click('خوشه‌ها', { exact: true });
    await browser.waitForText('هوشمندی کلمات کلیدی');
    await browser.click('ساخت پیشنهاد خوشه');
    // Let the Inertia mutation finish before navigating to Review Queue.
    await sleep(750);
    await browser.click('صف بررسی');

    let reloadPolls = 0;
    await browser.waitFor(
        async () => {
            const clusterReview = await browser.evaluate(
                `(() => [...document.querySelectorAll('article')].some((article) => (article.innerText || '').includes('پیشنهاد خوشه')))()`,
            );
            if (clusterReview) return true;
            if (++reloadPolls % 8 === 0) {
                await browser.evaluate(`(() => {
                    const button = [...document.querySelectorAll('button')].find((node) => (node.innerText || '').includes('دریافت آخرین نتایج') && !node.disabled);
                    button?.click();
                })()`);
            }
            return false;
        },
        'cluster suggestion in Review Queue',
        120000,
    );
    const reviewKey = await browser.evaluate(`(() => {
        const article = [...document.querySelectorAll('article')].find((node) => (node.innerText || '').includes('پیشنهاد خوشه'));
        if (!article) return '';
        article.dataset.lifecycleReviewKey = 'accepted-cluster-review';
        const button = [...article.querySelectorAll('button')].find((node) => (node.innerText || '').includes('پذیرش پیشنهاد') && !node.disabled);
        if (!button) return '';
        button.click();
        return article.dataset.lifecycleReviewKey;
    })()`);
    assert(
        reviewKey,
        'cluster suggestion can be accepted through Review Queue',
    );
    await browser.waitFor(
        () =>
            browser.evaluate(
                `!document.querySelector('[data-lifecycle-review-key="accepted-cluster-review"]')`,
            ),
        'cluster review decision',
        60000,
    );

    await browser.goto(keywordsUrl);
    await browser.click('خوشه‌ها', { exact: true });
    await browser.waitFor(
        () =>
            browser.evaluate(
                `!!document.querySelector('#keyword-intelligence-title')?.closest('section')?.querySelector('article')`,
            ),
        'accepted cluster card',
        60000,
    );

    const businessSet = await browser.evaluate(`(() => {
        const article = document.querySelector('#keyword-intelligence-title')?.closest('section')?.querySelector('article');
        const select = article?.querySelector('select');
        if (!select) return false;
        const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set;
        setter.call(select, '4');
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    })()`);
    assert(businessSet, 'cluster business relevance control is available');
    await browser.click('ثبت بررسی');
    await sleep(750);
    await browser.waitForText('بررسی‌شده');

    const pageInput = `input[placeholder=${JSON.stringify('عنوان صفحه برنامه‌ریزی‌شده')}]`;
    await browser.fill(pageInput, PLANNED_PAGE);
    await browser.click('ساخت صفحه', { exact: true });
    await browser.waitFor(
        () =>
            browser.evaluate(
                `document.querySelector(${JSON.stringify(pageInput)})?.value === ''`,
            ),
        'planned page creation',
    );

    const searchSelector = `#keyword-intelligence-title ~ * input[placeholder=${JSON.stringify('عنوان یا URL صفحه')}]`;
    const searchReady = await browser.evaluate(`(() => {
        const article = document.querySelector('#keyword-intelligence-title')?.closest('section')?.querySelector('article');
        const input = article?.querySelector('input[placeholder="عنوان یا URL صفحه"]');
        return !!input;
    })()`);
    assert(searchReady, 'target page search is available');
    const filledSearch = await browser.evaluate(`(() => {
        const article = document.querySelector('#keyword-intelligence-title')?.closest('section')?.querySelector('article');
        const input = article?.querySelector('input[placeholder="عنوان یا URL صفحه"]');
        if (!input) return false;
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
        setter.call(input, ${JSON.stringify(PLANNED_PAGE)});
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    })()`);
    assert(filledSearch, `target search input exists (${searchSelector})`);
    await browser.click('جستجوی صفحه هدف');
    await browser.waitForText(PLANNED_PAGE);
    await browser.click('نگاشت به این صفحه');
    await browser.waitFor(
        () =>
            browser.evaluate(`(() => {
                const article = document.querySelector('#keyword-intelligence-title')?.closest('section')?.querySelector('article');
                return (article?.innerText || '').includes(${JSON.stringify(PLANNED_PAGE)});
            })()`),
        'planned-page mapping',
    );

    if (await browser.hasControl('اعلام آماده')) {
        const readyEnabled = await browser.evaluate(`(() => {
            const article = document.querySelector('#keyword-intelligence-title')?.closest('section')?.querySelector('article');
            const button = article && [...article.querySelectorAll('button')].find((node) => (node.innerText || '').includes('اعلام آماده'));
            return !!button && !button.disabled;
        })()`);
        if (readyEnabled) await browser.click('اعلام آماده');
    }

    const state = instance.inspect();
    assert(
        state.counts.keyword_clusters >= 1,
        'human-approved cluster exists after UI review',
        state.counts,
    );
}

// The mapped planned page appears in the pages workspace; writing its H1
// through the form is saved and turns the matching checklist item green.
async function exercisePagesWorkspace(browser, instance, projectId) {
    log(`${instance.name}: pages workspace and on-page checklist through UI`);
    await browser.goto(`${instance.base}/projects/${projectId}/pages`);
    await browser.waitForText(PLANNED_PAGE);
    assert(
        await browser.evaluate(
            `document.querySelector('[data-site-check]')?.dataset.siteCheck === 'idle'`,
        ),
        'own-site page check panel is offered and idle',
    );
    await browser.click(PLANNED_PAGE, { exact: true });
    await browser.waitForText('چک‌لیست سئوی صفحه');
    const checkStatus = (key) =>
        browser.evaluate(
            `document.querySelector('[data-check=${JSON.stringify(key)}]')?.dataset.status ?? null`,
        );
    assert(
        (await checkStatus('h1_present')) === 'fix',
        'a new page asks for its main heading',
    );
    assert(
        (await checkStatus('url_present')) === 'na',
        'a planned page is not faulted for having no address',
    );
    assert(
        await browser.evaluate(
            `document.querySelector('[data-live]')?.dataset.live === 'unchecked'`,
        ),
        'a planned page has no live check yet',
    );
    await browser.fill('#h1', PLANNED_PAGE);
    await browser.click('ذخیره', { exact: true });
    await browser.waitFor(
        async () => (await checkStatus('h1_present')) === 'pass',
        'saved heading passes the checklist',
    );
}

// A brief is generated from the reviewed cluster, scheduled, and appears in
// the content calendar's next-seven-days group.
async function exerciseContentBrief(browser, instance, projectId) {
    log(`${instance.name}: content brief and calendar through UI`);
    const contentUrl = `${instance.base}/projects/${projectId}/content`;
    await browser.goto(contentUrl);
    await browser.waitForText('بریف تازه از یک خوشه');
    await browser.check('input[name="cluster_id"]');
    await browser.click('ساخت بریف', { exact: true });
    await browser.waitForText('کلمه کلیدی اصلی');
    const title = await browser.evaluate(
        `document.querySelector('#title')?.value ?? ''`,
    );
    assert(title !== '', 'generated brief has a title', { title });
    const due = new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 10);
    await browser.fill('#due_on', due);
    await browser.click('ذخیره', { exact: true });
    await browser.waitForText('ذخیره شد.');
    await browser.goto(contentUrl);
    await browser.waitFor(
        () =>
            browser.evaluate(
                `(document.querySelector('[data-bucket="week"]')?.innerText || '').includes(${JSON.stringify(title)})`,
            ),
        'scheduled brief appears in the next seven days',
    );
}

// A task linked to the planned page is added from the page's task list,
// shows as overdue, and moves to done when ticked.
async function exerciseTasks(browser, instance, projectId) {
    log(`${instance.name}: single-user tasks through UI`);
    await browser.goto(`${instance.base}/projects/${projectId}/pages`);
    await browser.click(PLANNED_PAGE, { exact: true });
    await browser.click('کارهای این صفحه', { exact: true });
    await browser.waitForText('کارهای صفحه');
    const title = 'نوشتن توضیحات متا';
    const yesterday = new Date(Date.now() - 86400000)
        .toISOString()
        .slice(0, 10);
    await browser.fill('form input[maxlength="255"]', title);
    await browser.fill('form input[type="date"]', yesterday);
    await browser.click('افزودن', { exact: true });
    await browser.waitForText(title);
    await browser.waitForText('عقب‌افتاده (۱)');
    await browser.check('[data-task] input[type="checkbox"]');
    await browser.waitForText('انجام‌شده (۱)');

    // The page's internal link suggestions open focused on that page.
    await browser.goto(`${instance.base}/projects/${projectId}/pages`);
    await browser.click(PLANNED_PAGE, { exact: true });
    await browser.click('پیشنهاد لینک داخلی', { exact: true });
    await browser.waitForText('لینک‌های مربوط به صفحه');
    await browser.waitForText('پیشنهادها (');
}

// A Persian Search Console queries export is imported; its opportunities are
// listed and one becomes a task.
async function importSearchConsole(browser, instance, projectId) {
    log(
        `${instance.name}: Search Console CSV import and opportunities through UI`,
    );
    await browser.goto(`${instance.base}/projects/${projectId}/performance`);
    await browser.waitForText('وارد کردن فایل سرچ کنسول');
    await browser.setFile(
        '#sc-file',
        join(fixtures, 'search-console-queries-fa.csv'),
    );
    await browser.click('وارد کردن', { exact: true });
    await browser.waitFor(
        () =>
            browser.evaluate(
                `!!document.querySelector('[data-summary="queries"]')`,
            ),
        'imported queries summary',
    );
    await browser.waitForText('نزدیک صدر (۱)');
    await browser.waitForText('کلیک کمتر از انتظار (۱)');
    await browser.click('افزودن به کارها', { exact: true });
    await browser.waitForText('به کارها اضافه شد');
}

// The project report summarises the work done above and downloads as CSV.
async function checkProjectReport(browser, instance, projectId) {
    log(`${instance.name}: project report and CSV through UI`);
    const base = `/projects/${projectId}/reports`;
    await browser.goto(`${instance.base}${base}`);
    await browser.waitForText('کارهای انجام‌شده در این دوره');
    const recorded = await browser.evaluate(
        `[...document.querySelectorAll('[data-activity]')].map((node) => node.dataset.activity)`,
    );
    assert(
        recorded.includes('task.created') &&
            recorded.includes('search_console.imported'),
        'report lists the work done in the period',
        recorded,
    );
    const csvHref = await browser.evaluate(
        `document.querySelector('a[href*="/reports/report.csv"]')?.getAttribute('href') ?? ''`,
    );
    const csv = await browser.fetchText(csvHref);
    assert(csv.status === 200, 'report CSV downloads', csv);
    assert(
        csv.body.includes('tasks.done_in_period'),
        'report CSV contains the task figures',
    );
}

async function downloadAndValidatePersianExport(browser, instance, projectId) {
    log(
        `${instance.name}: download and validate Persian SEO CSV through UI link`,
    );
    const path = `/projects/${projectId}/exports/seo-plan.csv`;
    await browser.goto(`${instance.base}/projects/${projectId}/keywords`);
    const linkExists = await browser.evaluate(
        `!!document.querySelector('a[href=${JSON.stringify(path)}]')`,
    );
    assert(linkExists, 'normal-browser export link is rendered');
    const csv = await browser.fetchText(path);
    assert(csv.status === 200, 'Persian export returns 200', csv);
    assert(
        (csv.type ?? '').includes('text/csv'),
        'Persian export content type is CSV',
        csv,
    );
    assert(
        (csv.disposition ?? '').includes('.csv'),
        'Persian export is an attachment',
        csv,
    );
    // response.text() decodes UTF-8 and silently drops a leading BOM, so the
    // byte-level contract is checked on the raw download.
    const raw = Buffer.from((await browser.fetchBase64(path)).body, 'base64');
    assert(
        raw.subarray(0, 3).equals(Buffer.from([0xef, 0xbb, 0xbf])),
        'Persian export starts with UTF-8 BOM bytes',
        { prefix: raw.subarray(0, 3).toString('hex') },
    );
    const header = csv.body
        .replace(/^\uFEFF/, '')
        .split(/\r?\n/, 1)[0]
        .split(',');
    assert(
        header.length === 74 &&
            header[0] === 'schema_version' &&
            header.includes('keyword_display_text') &&
            header.includes('page_title') &&
            header.at(-1) === 'exact_text_json',
        'seo-plan/1 ordered header is present',
        header,
    );
    assert(csv.body.includes('seo-plan/1'), 'export rows identify seo-plan/1');
    assert(
        csv.body.includes('سئو سایت'),
        'export contains Persian keyword text',
    );
    assert(csv.body.includes('\u200c'), 'export preserves a real Persian ZWNJ');
    assert(
        csv.body.includes(PLANNED_PAGE),
        'export includes mapped planned page',
    );
}

async function ensureRecentPassword(browser, instance, owner) {
    await browser.goto(`${instance.base}/settings/security`);
    if ((await browser.url()).includes('/confirm-password')) {
        await browser.fill('#password', owner.password);
        await submitClosestForm(browser, '#password');
        await browser.waitForUrl(
            (url) => !url.includes('/confirm-password'),
            'recent password confirmation',
        );
    }
}

async function downloadBackupThroughBrowser(browser, instance, owner) {
    log(`${instance.name}: download full backup through UI`);
    await ensureRecentPassword(browser, instance, owner);
    await browser.goto(`${instance.base}/settings/backup`);
    await browser.waitForText('پشتیبان‌گیری و بازیابی');
    const linkExists = await browser.evaluate(
        `document.querySelector('[data-test="download-backup"]')?.getAttribute('href') === '/settings/backup/download'`,
    );
    assert(linkExists, 'backup download link is rendered in Persian UI');
    const download = await browser.fetchBase64('/settings/backup/download');
    assert(download.status === 200, 'backup download returns 200', download);
    assert(
        (download.disposition ?? '').includes('.seobak'),
        'backup is delivered as .seobak attachment',
        download,
    );
    const backupPath = join(work, `${instance.name}.seobak`);
    writeFileSync(backupPath, Buffer.from(download.body, 'base64'));
    assert(existsSync(backupPath), 'disposable backup file was written');
    return backupPath;
}

async function restoreBackupThroughBrowser(
    browser,
    instance,
    owner,
    backupPath,
) {
    log(`${instance.name}: verify and restore backup through UI`);
    await ensureRecentPassword(browser, instance, owner);
    await browser.goto(`${instance.base}/settings/backup`);
    await browser.waitForText('بازیابی از فایل پشتیبان');
    await browser.setFile('#restore-backup', backupPath);
    await clickSelector(browser, '[data-test="verify-backup"]');
    await browser.waitFor(
        () => browser.evaluate('!!document.querySelector("#restore-digest")'),
        'verified backup preview',
        120000,
    );
    const digest = await browser.evaluate(
        'document.querySelector("code")?.textContent?.trim() ?? ""',
    );
    assert(/^[a-f0-9]{64}$/.test(digest), 'verified backup digest is shown', {
        digest,
    });
    await browser.fill('#restore-digest', digest);
    await clickSelector(browser, '[data-test="confirm-restore"]');
    await browser.waitForUrl(
        (url) => url.includes('/login'),
        'restore completion redirect to login',
        180000,
    );
}

async function backupRestoreLifecycle(browser, source, workflow) {
    const sourceState = source.inspect();
    const backupPath = await downloadBackupThroughBrowser(
        browser,
        source,
        OWNER,
    );

    const destination = await new Instance('instance-b', 'seo_e2e_b').create();
    instances.push(destination);
    await browser.clearCookies();
    const destinationBefore = await installThroughBrowser(
        browser,
        destination,
        DESTINATION_OWNER,
    );
    assert(
        destinationBefore.installed.installation_id !==
            sourceState.installed.installation_id,
        'clean destination has a distinct installation identity',
    );
    assert(
        destinationBefore.app_key_sha256 !== sourceState.app_key_sha256,
        'clean destination starts with a distinct APP_KEY hash',
    );

    await restoreBackupThroughBrowser(
        browser,
        destination,
        DESTINATION_OWNER,
        backupPath,
    );
    const restored = destination.inspect();
    assert(
        restored.database === destinationBefore.database,
        'restore preserves destination database selection',
        restored,
    );
    assert(
        restored.installed.installation_id ===
            destinationBefore.installed.installation_id,
        'restore preserves destination installation identity',
        restored.installed,
    );
    assert(
        restored.installed.restored_from_installation_id ===
            sourceState.installed.installation_id,
        'restore records source installation provenance',
        restored.installed,
    );
    assert(
        restored.app_key_sha256 === sourceState.app_key_sha256 &&
            restored.app_key_sha256 !== destinationBefore.app_key_sha256,
        'restore applies source APP_KEY without exposing it',
        {
            source: sourceState.app_key_sha256,
            destinationBefore: destinationBefore.app_key_sha256,
            restored: restored.app_key_sha256,
        },
    );
    assert(
        restored.counts.projects === sourceState.counts.projects &&
            restored.counts.keywords === sourceState.counts.keywords &&
            restored.counts.keyword_clusters ===
                sourceState.counts.keyword_clusters,
        'restore carries project, keyword, and cluster data',
        { source: sourceState.counts, restored: restored.counts },
    );

    await browser.clearCookies();
    await login(browser, destination, OWNER);
    await assertApplicationServes(
        browser,
        destination,
        workflow.project_id,
        'سئو سایت',
    );
    log(
        `${destination.name}: restored source owner and project are operational`,
    );
    return destination;
}

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
            await confirmPasswordIfAsked(browser, owner);
            await browser.waitForUrl(
                (url) => url.endsWith('/settings/update'),
                'return to update page',
            );
        } else {
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
            // Operator-decision phases are deliberately not polled, and a
            // maintenance 503 page is not an Inertia page; reload like an
            // operator would, but never while this tab's request is running.
            if (
                ++polls % 20 === 0 &&
                (text.includes('Application update') ||
                    !(await browser.evaluate(
                        '!!document.querySelector(\'[aria-busy="true"]\')',
                    )))
            )
                await browser.goto(
                    (await browser.url()).replace(
                        /\/settings\/update.*$/,
                        '/settings/update',
                    ),
                );
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

async function updateLifecycle(browser, a, workflow) {
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
    await assertApplicationServes(browser, a, workflow.project_id, 'سئو سایت');
    log('update: restored project remains operational after successful update');

    // 2. Target that cannot boot: the launcher must restore the source release.
    log('update: target bootstrap failure');
    const brokenFingerprint = sourceFingerprint;
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
    await assertApplicationServes(browser, a, workflow.project_id, 'سئو سایت');

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
    await assertApplicationServes(browser, a, workflow.project_id, 'سئو سایت');

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
    await a.stop();
    await a.start();
    await openUpdatePage(browser, a, OWNER);
    state = a.inspect();
    log(
        `update: after interruption journal=${state.journal_phase} columns=${state.project_columns.join(',')}`,
    );
    await resolveAfterInterruption(browser, a);
    state = a.inspect();
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
    await assertApplicationServes(browser, a, workflow.project_id, 'سئو سایت');

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
        if (await browser.tryClick('ادامه بازگردانی ایمن')) continue;
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
        // finishRecovery re-logs in and retries if this click is lost.
        await browser.tryClick('ادامه بازگردانی ایمن');
        await finishRecovery(browser, instance);
        return;
    }
    if (await browser.hasControl('ادامه به‌روزرسانی')) {
        await browser.click('ادامه به‌روزرسانی');
        await sleep(3000);
        await openUpdatePage(browser, instance, OWNER);
        if (await browser.hasControl('ادامه بازگردانی ایمن')) {
            await browser.tryClick('ادامه بازگردانی ایمن');
            await finishRecovery(browser, instance);
        }
        return;
    }
    log(`update: interruption left page state: ${text.slice(0, 400)}`);
}

// Disposable instances only: surfaces server-side causes that the UI
// deliberately hides, so a failed run is diagnosable from the CI log.
function printInstanceLogs(instance) {
    const files = [
        join(instance.root, 'storage', 'logs', 'laravel.log'),
        instance.serverLog,
    ];
    for (const file of files) {
        if (!file || !existsSync(file)) continue;
        const lines = readFileSync(file, 'utf8').split('\n');
        console.error(`--- ${instance.name}: ${file} (last 80 lines) ---`);
        console.error(lines.slice(-80).join('\n'));
    }
}

async function main() {
    const browser = await Browser.launch();
    try {
        const source = await new Instance('instance-a', 'seo_e2e_a').create();
        instances.push(source);
        await installThroughBrowser(browser, source, OWNER);
        const projectId = await createProjectThroughBrowser(browser, source);
        const workflow = await importKeywordsThroughBrowser(
            browser,
            source,
            projectId,
        );
        await exerciseKeywordIntelligence(browser, source, projectId);
        await exercisePagesWorkspace(browser, source, projectId);
        await exerciseContentBrief(browser, source, projectId);
        await exerciseTasks(browser, source, projectId);
        await importSearchConsole(browser, source, projectId);
        await checkProjectReport(browser, source, projectId);
        await downloadAndValidatePersianExport(browser, source, projectId);
        const restored = await backupRestoreLifecycle(
            browser,
            source,
            workflow,
        );
        await updateLifecycle(browser, restored, workflow);
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
    for (const instance of instances) printInstanceLogs(instance);
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
    // Never let a stray child handle keep the job running until its timeout.
    process.exit(process.exitCode ?? 0);
}
