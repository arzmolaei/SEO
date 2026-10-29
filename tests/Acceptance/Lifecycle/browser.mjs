// Minimal dependency-free Chrome DevTools Protocol driver for lifecycle acceptance.
// Build/CI acceptance only; nothing here ships in the runtime release.
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';

const CHROME_CANDIDATES = [
    process.env.LIFECYCLE_CHROME,
    'google-chrome',
    'google-chrome-stable',
    'chromium',
    'chromium-browser',
].filter(Boolean);

export class Browser {
    static async launch() {
        let lastError = null;
        // A runner occasionally starts Chrome without DevTools; one more
        // round of attempts separates that from a real launch problem.
        for (let round = 1; round <= 2; round++) {
            for (const binary of CHROME_CANDIDATES) {
                const profile = mkdtempSync(
                    join(tmpdir(), 'seo-lifecycle-chrome-'),
                );
                let child = null;
                try {
                    child = spawn(
                        binary,
                        [
                            '--headless=new',
                            '--no-sandbox',
                            '--disable-gpu',
                            '--disable-dev-shm-usage',
                            '--no-first-run',
                            '--no-default-browser-check',
                            '--remote-debugging-port=0',
                            '--lang=fa-IR',
                            `--user-data-dir=${profile}`,
                            'about:blank',
                        ],
                        { stdio: ['ignore', 'ignore', 'pipe'], detached: true },
                    );
                    const started = child;
                    const endpoint = await new Promise((resolve, reject) => {
                        let buffer = '';
                        const timer = setTimeout(
                            () =>
                                reject(
                                    new Error(
                                        'Chrome did not expose DevTools.',
                                    ),
                                ),
                            20000,
                        );
                        started.once('error', (error) => {
                            clearTimeout(timer);
                            reject(error);
                        });
                        started.stderr.on('data', (chunk) => {
                            buffer += chunk.toString();
                            const match = buffer.match(
                                /DevTools listening on (ws:\/\/\S+)/,
                            );
                            if (match) {
                                clearTimeout(timer);
                                resolve(match[1]);
                            }
                        });
                    });
                    const browser = new Browser(child, profile, endpoint);
                    await browser.connect();
                    return browser;
                } catch (error) {
                    lastError = error;
                    // A half-started Chrome keeps this process alive; end it.
                    Browser.kill(child);
                    rmSync(profile, { recursive: true, force: true });
                }
            }
        }
        throw new Error(
            `Unable to launch Chrome: ${lastError?.message ?? 'not found'}`,
        );
    }

    static kill(child) {
        if (!child || child.exitCode !== null) return;
        child.stderr?.destroy();
        try {
            process.kill(-child.pid, 'SIGKILL');
        } catch {
            child.kill('SIGKILL');
        }
    }

    constructor(child, profile, endpoint) {
        this.child = child;
        this.profile = profile;
        this.endpoint = endpoint;
        this.nextId = 1;
        this.pending = new Map();
        this.listeners = new Set();
        this.consoleErrors = [];
    }

    async connect() {
        this.socket = new WebSocket(this.endpoint);
        await new Promise((resolve, reject) => {
            this.socket.addEventListener('open', resolve, { once: true });
            this.socket.addEventListener('error', reject, { once: true });
        });
        this.socket.addEventListener('message', (event) => {
            const message = JSON.parse(event.data);
            if (message.id && this.pending.has(message.id)) {
                const { resolve, reject } = this.pending.get(message.id);
                this.pending.delete(message.id);
                if (message.error) {
                    reject(
                        new Error(
                            `${message.error.message} ${message.error.data ?? ''}`,
                        ),
                    );
                } else {
                    resolve(message.result);
                }
                return;
            }
            for (const listener of this.listeners) {
                listener(message);
            }
        });
        const { targetId } = await this.send('Target.createTarget', {
            url: 'about:blank',
        });
        const { sessionId } = await this.send('Target.attachToTarget', {
            targetId,
            flatten: true,
        });
        this.sessionId = sessionId;
        await this.page('Page.enable');
        await this.page('Runtime.enable');
        await this.page('DOM.enable');
        this.listeners.add((message) => {
            if (message.sessionId !== this.sessionId) return;
            if (message.method === 'Runtime.exceptionThrown') {
                this.consoleErrors.push(
                    message.params.exceptionDetails?.exception?.description ??
                        'exception',
                );
            }
        });
    }

    send(method, params = {}, sessionId) {
        const id = this.nextId++;
        const payload = { id, method, params };
        if (sessionId) payload.sessionId = sessionId;
        return new Promise((resolve, reject) => {
            this.pending.set(id, { resolve, reject });
            this.socket.send(JSON.stringify(payload));
        });
    }

    page(method, params = {}) {
        return this.send(method, params, this.sessionId);
    }

    async evaluate(expression) {
        const result = await this.page('Runtime.evaluate', {
            expression,
            awaitPromise: true,
            returnByValue: true,
        });
        if (result.exceptionDetails) {
            throw new Error(
                `Browser evaluation failed: ${result.exceptionDetails.exception?.description ?? result.exceptionDetails.text}`,
            );
        }
        return result.result.value;
    }

    async goto(url) {
        await this.page('Page.navigate', { url });
        await this.waitForReady();
    }

    async waitForReady() {
        await this.waitFor(
            () =>
                this.evaluate(
                    'document.readyState === "complete" && !!document.body',
                ),
            'document ready',
        );
    }

    async waitFor(predicate, description, timeoutMs = 30000) {
        const deadline = Date.now() + timeoutMs;
        let lastError = null;
        while (Date.now() < deadline) {
            try {
                if (await predicate()) return;
            } catch (error) {
                lastError = error;
            }
            await sleep(150);
        }
        const text = await this.text().catch(() => '');
        throw new Error(
            `Timed out waiting for ${description}. ${lastError?.message ?? ''}\n--- page ---\n${text.slice(0, 3000)}`,
        );
    }

    text() {
        return this.evaluate('document.body ? document.body.innerText : ""');
    }

    url() {
        return this.evaluate('location.href');
    }

    async waitForText(fragment, timeoutMs = 30000) {
        await this.waitFor(
            async () => (await this.text()).includes(fragment),
            `text "${fragment}"`,
            timeoutMs,
        );
    }

    async waitForUrl(predicate, description, timeoutMs = 30000) {
        await this.waitFor(
            async () => predicate(await this.url()),
            description,
            timeoutMs,
        );
    }

    async fill(selector, value) {
        const ok = await this.evaluate(`(() => {
            const element = document.querySelector(${JSON.stringify(selector)});
            if (!element) return false;
            const prototype = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype
                : element instanceof HTMLSelectElement ? HTMLSelectElement.prototype : HTMLInputElement.prototype;
            Object.getOwnPropertyDescriptor(prototype, 'value').set.call(element, ${JSON.stringify(value)});
            element.dispatchEvent(new Event('input', { bubbles: true }));
            element.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        })()`);
        if (!ok) throw new Error(`No element for ${selector}`);
    }

    async check(selector) {
        const ok = await this.evaluate(`(() => {
            const element = document.querySelector(${JSON.stringify(selector)});
            if (!element) return false;
            if (!element.checked) element.click();
            return true;
        })()`);
        if (!ok) throw new Error(`No checkbox for ${selector}`);
    }

    // Click the first enabled visible button/link whose accessible text contains `label`.
    async click(label, { exact = false } = {}) {
        await this.waitFor(
            () =>
                this.evaluate(`(() => {
                    const label = ${JSON.stringify(label)};
                    const candidates = [...document.querySelectorAll('button, a, [role="button"]')];
                    const element = candidates.find((node) => {
                        const text = (node.innerText || node.getAttribute('aria-label') || '').trim();
                        const matches = ${exact} ? text === label : text.includes(label);
                        return matches && !node.disabled && node.getAttribute('aria-disabled') !== 'true' && node.offsetParent !== null;
                    });
                    if (!element) return false;
                    element.click();
                    return true;
                })()`),
            `enabled control "${label}"`,
        );
    }

    // One-shot variant of click(): the page may navigate away (e.g. to
    // /login) between seeing a control and clicking it.
    async tryClick(label) {
        return this.evaluate(`(() => {
            const label = ${JSON.stringify(label)};
            const element = [...document.querySelectorAll('button, a, [role="button"]')].find((node) =>
                (node.innerText || node.getAttribute('aria-label') || '').includes(label) &&
                !node.disabled && node.getAttribute('aria-disabled') !== 'true' && node.offsetParent !== null);
            if (!element) return false;
            element.click();
            return true;
        })()`);
    }

    async hasControl(label) {
        return this.evaluate(
            `(() => [...document.querySelectorAll('button, a')].some((node) => (node.innerText || '').includes(${JSON.stringify(label)}) && node.offsetParent !== null))()`,
        );
    }

    async setFile(selector, path) {
        const { root } = await this.page('DOM.getDocument', { depth: 0 });
        const { nodeId } = await this.page('DOM.querySelector', {
            nodeId: root.nodeId,
            selector,
        });
        if (!nodeId) throw new Error(`No file input ${selector}`);
        await this.page('DOM.setFileInputFiles', { nodeId, files: [path] });
    }

    // Same-origin fetch with the session cookies; used to download real files the UI links to.
    async fetchText(path) {
        return this.evaluate(
            `fetch(${JSON.stringify(path)}, { credentials: 'same-origin' }).then(async (response) => ({ status: response.status, type: response.headers.get('content-type'), disposition: response.headers.get('content-disposition'), cache: response.headers.get('cache-control'), body: await response.text() }))`,
        );
    }

    async fetchBase64(path) {
        return this
            .evaluate(`fetch(${JSON.stringify(path)}, { credentials: 'same-origin' }).then(async (response) => {
            const bytes = new Uint8Array(await response.arrayBuffer());
            let binary = '';
            for (let index = 0; index < bytes.length; index += 0x8000) binary += String.fromCharCode(...bytes.subarray(index, index + 0x8000));
            return { status: response.status, disposition: response.headers.get('content-disposition'), body: btoa(binary) };
        })`);
    }

    async clearCookies() {
        await this.page('Network.clearBrowserCookies');
    }

    async close() {
        try {
            this.socket?.close();
        } catch {
            // ignore
        }
        const exited = new Promise((resolve) =>
            this.child.once('exit', resolve),
        );
        try {
            process.kill(-this.child.pid, 'SIGKILL');
        } catch {
            this.child.kill('SIGKILL');
        }
        await Promise.race([exited, sleep(5000)]);
        try {
            rmSync(this.profile, {
                recursive: true,
                force: true,
                maxRetries: 10,
                retryDelay: 200,
            });
        } catch {
            // A late Chrome helper may still hold the disposable profile; it is temporary.
        }
    }
}
