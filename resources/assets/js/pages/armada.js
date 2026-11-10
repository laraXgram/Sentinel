import { Page, html } from '../core/page.js';
import { api } from '../core/api.js';
import { icon } from '../core/icons.js';
import { store } from '../core/store.js';
import * as fmt from '../core/format.js';
import { card, tile, pageHead, empty, badge, props, meter, modal, toast } from '../components/ui.js';
import { SERIES, legend } from '../components/charts.js';

const megabytes = (bytes) => (bytes === null || bytes === undefined ? null : bytes / 1048576);

const STATE_TONES = { running: 'success', restarting: 'warning', paused: 'warning', created: '', exited: 'error', dead: 'error', removing: 'warning' };

const HEALTH_TONES = { healthy: 'success', starting: 'warning', unhealthy: 'error' };

const PROCESS_TONES = { RUNNING: 'success', STARTING: 'warning', BACKOFF: 'warning', STOPPING: 'warning', STOPPED: '', EXITED: 'error', FATAL: 'error', UNKNOWN: 'error' };

function stateBadge(service) {
    return html`${badge(service.state, STATE_TONES[service.state] ?? '', service.state === 'running' ? 'radio' : null)}${service.health ? html` ${badge(service.health, HEALTH_TONES[service.health] ?? '')}` : ''}`;
}

function memory(service) {
    if (service.memory === null || service.memory === undefined) return '—';
    const limit = service.memory_limit ? html` <span class="muted">/ ${fmt.bytes(megabytes(service.memory_limit))}</span>` : '';

    return html`${fmt.bytes(megabytes(service.memory))}${limit}`;
}

function cpu(service) {
    if (service.cpu === null || service.cpu === undefined) return html`<span class="muted">—</span>`;

    return html`<div class="row" style="gap:8px;min-width:120px">${meter(Math.min(100, service.cpu))}<span class="small">${fmt.number(service.cpu)}%</span></div>`;
}

function ports(service) {
    return service.ports?.length
        ? service.ports.map((port) => html`<span class="mono small">${port.public}→${port.private}${port.type !== 'tcp' ? `/${port.type}` : ''}</span> `)
        : html`<span class="muted">—</span>`;
}

function actionButtons(service, allowed) {
    if (!allowed) return '';
    const running = service.state === 'running' || service.state === 'restarting';

    return html`
        <div class="row" style="gap:6px;justify-content:flex-end">
            ${running
                ? html`<button class="btn sm outline" data-action="container" data-service="${service.service}" data-op="restart" title="Restart">${icon('refresh')}</button>
                       <button class="btn sm outline" data-action="container" data-service="${service.service}" data-op="stop" title="Stop">${icon('pause')}</button>`
                : html`<button class="btn sm outline" data-action="container" data-service="${service.service}" data-op="start" title="Start">${icon('play')}</button>`}
        </div>`;
}

function processTable(processes, allowed, { hasApp = true, error = null } = {}) {
    if (!hasApp) {
        return empty({ compact: true, iconName: 'layers', title: 'No application container', text: html`The <code>laragram.test</code> (or <code>app</code>) service runs the background processes.` });
    }

    if (processes === null || processes === undefined) {
        return empty({ compact: true, iconName: 'layers', title: error ? 'Supervisor did not answer' : 'The application container is not running', text: error ? html`${error}<br>Rebuild the application image with the latest Armada so <code>supervisorctl</code> can reach Supervisor.` : '' });
    }

    return html`
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Process</th><th>State</th><th>Details</th>${allowed ? html`<th></th>` : ''}</tr></thead>
            <tbody>${processes.map((process) => html`
                <tr>
                    <td class="mono">${process.name}</td>
                    <td>${badge(process.state.toLowerCase(), PROCESS_TONES[process.state] ?? '')}</td>
                    <td class="small muted">${process.description}</td>
                    ${allowed ? html`<td><div class="row" style="gap:6px;justify-content:flex-end">
                        ${process.state === 'RUNNING'
                            ? html`<button class="btn sm outline" data-action="process" data-process="${process.name}" data-op="restart" title="Restart">${icon('refresh')}</button>
                                   <button class="btn sm outline" data-action="process" data-process="${process.name}" data-op="stop" title="Stop">${icon('pause')}</button>`
                            : html`<button class="btn sm outline" data-action="process" data-process="${process.name}" data-op="start" title="Start">${icon('play')}</button>`}
                    </div></td>` : ''}
                </tr>`)}</tbody>
        </table></div>`;
}

function series(graph) {
    return Object.entries(graph.series || {}).map(([name, values], index) => ({ name, values, color: SERIES[index % 8] }));
}

/**
 * Shared actions of the Armada pages: containers and Supervisor processes.
 */
class ArmadaActions extends Page {
    setup() {
        this.on('click', '[data-action=container]', (e, button) => this.container(button.dataset.service, button.dataset.op));
        this.on('click', '[data-action=process]', (e, button) => this.process(button.dataset.process, button.dataset.op));
    }

    async container(service, op) {
        await modal({
            title: `${fmt.title(op)} ${service}?`,
            text: op === 'stop'
                ? 'The container stops until you start it again, and everything depending on it may stop working.'
                : `Docker will ${op} the container of the ${service} service.`,
            confirm: fmt.title(op),
            tone: op === 'start' ? 'primary' : 'danger',
            onConfirm: async () => {
                await api.post(`armada/services/${encodeURIComponent(service)}/${op}`);
                toast(`${fmt.title(op)} requested`, service);
                this.render({ soft: true });
            },
        });
    }

    async process(process, op) {
        await modal({
            title: `${fmt.title(op)} the ${process} process?`,
            text: 'Supervisor manages the process inside the application container.',
            confirm: fmt.title(op),
            tone: op === 'start' ? 'primary' : 'danger',
            onConfirm: async () => {
                const result = await api.post(`armada/processes/${encodeURIComponent(process)}/${op}`);
                toast(`${fmt.title(op)} done`, result.output || process);
                this.render({ soft: true });
            },
        });
    }
}

export class ArmadaPage extends ArmadaActions {
    static live = true;

    title() {
        return 'Armada';
    }

    load() {
        return api.get('armada', store.query());
    }

    charts(data) {
        if (!data.enabled || !data.ok) return {};
        const period = store.get('period');

        return {
            cpu: { buckets: data.cpu.buckets, series: series(data.cpu), format: (v) => `${fmt.number(v)}%`, integer: false, period, height: 200 },
            memory: { buckets: data.memory.buckets, series: series(data.memory), format: fmt.bytes, period, height: 200 },
        };
    }

    view(data) {
        if (!data.enabled) {
            return html`
                ${pageHead({ title: 'Armada', sub: 'The containers of your Armada project.' })}
                <div class="card">${empty({ iconName: 'server', title: 'The Armada page is turned off', text: html`Set <code>SENTINEL_ARMADA=true</code> to watch your containers.` })}</div>`;
        }

        if (!data.ok) {
            return html`
                ${pageHead({ title: 'Armada', sub: html`<span class="mono">${data.host}</span>`, tools: badge('unreachable', 'error', 'alertCircle') })}
                <div class="card">${empty({
                    iconName: 'server',
                    title: 'Docker is not reachable',
                    text: html`${data.error}<br><br>Add the <code>docker-proxy</code> service with <code>php laragram armada:add docker-proxy</code>, which points <code>SENTINEL_DOCKER_HOST</code> at it, or give Sentinel access to the Docker socket.`,
                })}</div>`;
        }

        const services = data.services;
        const running = services.filter((service) => service.state === 'running').length;
        const unhealthy = services.filter((service) => service.health === 'unhealthy' || ['exited', 'dead', 'restarting'].includes(service.state)).length;
        const totalCpu = services.reduce((sum, service) => sum + (service.cpu || 0), 0);
        const totalMemory = services.reduce((sum, service) => sum + (service.memory || 0), 0);
        const missing = (data.declared || []).filter((declared) => !services.some((service) => service.service === declared.service));
        const settings = data.settings || {};

        return html`
            ${pageHead({
                title: 'Armada',
                sub: html`Project <span class="mono">${data.project}</span> · Docker ${data.engine?.version ?? '?'} (${data.engine?.os ?? ''}) · <span class="mono">${data.host}</span>`,
                tools: html`${badge(`${running}/${services.length} running`, running === services.length ? 'success' : 'warning', 'radio')} ${this.periodPicker()}`,
            })}

            <div class="grid cols-4">
                ${tile({ label: 'Containers', value: services.length, iconName: 'layers', suffix: ` · ${fmt.number(running)} running` })}
                ${tile({ label: 'Need attention', value: unhealthy, iconName: 'alertTriangle', tone: unhealthy ? 'error' : '' })}
                ${tile({ label: 'CPU', value: totalCpu, iconName: 'cpu', format: (v) => `${fmt.number(v)}%` })}
                ${tile({ label: 'Memory', value: megabytes(totalMemory), iconName: 'hardDrive', format: fmt.bytes })}
            </div>

            ${card({
                title: 'Services',
                sub: 'Every container of the compose project',
                flush: true,
                body: services.length ? html`
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>Service</th><th>State</th><th>CPU</th><th class="num">Memory</th><th class="num">Network in / out</th><th>Ports</th><th class="num">Restarts</th><th class="num">Started</th>${data.actions ? html`<th></th>` : ''}</tr></thead>
                        <tbody>${services.map((service) => html`
                            <tr class="clickable" data-href="#/armada/${encodeURIComponent(service.service)}">
                                <td><div class="mono" style="font-weight:600">${service.service}</div><div class="small muted">${fmt.truncate(service.image || '', 48)}</div></td>
                                <td>${stateBadge(service)}<div class="small muted" style="margin-top:4px">${service.status}</div></td>
                                <td>${cpu(service)}</td>
                                <td class="num">${memory(service)}</td>
                                <td class="num small">${service.network_rx !== null ? html`${fmt.bytes(megabytes(service.network_rx))} / ${fmt.bytes(megabytes(service.network_tx))}` : '—'}</td>
                                <td>${ports(service)}</td>
                                <td class="num">${service.restart_count ? badge(fmt.number(service.restart_count), 'warning') : '0'}</td>
                                <td class="num muted">${service.started_at && service.state === 'running' ? fmt.ago(service.started_at) : '—'}</td>
                                ${data.actions ? html`<td>${actionButtons(service, data.actions)}</td>` : ''}
                            </tr>`)}</tbody>
                    </table></div>` : empty({ compact: true, iconName: 'layers', title: 'The project has no containers', text: html`Start them with <code>./vendor/bin/armada up -d</code>.` }),
            })}

            ${missing.length ? html`<div class="card" style="margin-bottom:24px">${empty({
                compact: true,
                iconName: 'alertTriangle',
                title: `${fmt.plural(missing.length, 'service')} of compose.yaml ${missing.length === 1 ? 'has' : 'have'} no container`,
                text: html`${missing.map((service) => html`<code>${service.service}</code> `)}`,
            })}</div>` : ''}

            <div class="grid cols-2">
                ${card({ title: 'CPU', sub: 'Recorded by sentinel:check', actions: Object.keys(data.cpu.series || {}).length ? legend(series(data.cpu).map((item) => ({ label: item.name, color: item.color }))) : '', body: Object.keys(data.cpu.series || {}).length ? html`<div data-chart="cpu"></div>` : empty({ compact: true, iconName: 'activity', title: 'No history yet', text: html`Run <code>php laragram sentinel:check</code>, or the <code>sentinel-check</code> Armada process.` }) })}
                ${card({ title: 'Memory', sub: 'Recorded by sentinel:check', body: Object.keys(data.memory.series || {}).length ? html`<div data-chart="memory"></div>` : empty({ compact: true, iconName: 'activity', title: 'No history yet' }) })}
            </div>

            <div class="grid cols-2">
                ${card({ title: 'Processes', sub: 'Run by Supervisor in the application container', flush: data.processes?.length > 0, body: processTable(data.processes, data.actions, { hasApp: data.has_app, error: data.processes_error }) })}
                ${card({
                    title: 'Settings',
                    sub: 'From your .env file',
                    body: props([
                        ['Server', settings.server ? html`<span class="mono">${settings.server}</span>` : '—'],
                        ['Updates', settings.updates ? html`<span class="mono">${settings.updates}</span>` : '—'],
                        ['Processes', settings.processes ? html`<span class="mono">${settings.processes}</span>` : '—'],
                        ['Bot API', settings.api_endpoint ? html`<span class="mono">${settings.api_endpoint}</span>` : '—'],
                        ['File streaming', settings.stream_files ? html`<span class="mono">${settings.stream_files}</span>` : '—'],
                        ['File storage', settings.file_storage ? html`<span class="mono">${settings.file_storage}</span>` : '—'],
                    ]),
                })}
            </div>`;
    }
}

export class ArmadaServicePage extends ArmadaActions {
    static live = true;

    setup() {
        super.setup();
        this.on('change', '[data-action=lines]', (e, select) => {
            this.lines = select.value;
            this.render({ soft: true });
        });
    }

    title() {
        return this.params.service;
    }

    after() {
        // the newest lines are at the bottom
        const logs = this.el.querySelector('.logs');
        if (logs) logs.scrollTop = logs.scrollHeight;
    }

    load() {
        return api.get(`armada/services/${encodeURIComponent(this.params.service)}`, { lines: this.lines || 300 });
    }

    view(data) {
        const lines = String(this.lines || 300);

        return html`
            ${pageHead({
                title: data.service,
                sub: html`<span class="mono">${data.container}</span> · <span class="mono">${data.image}</span>`,
                crumbs: [{ label: 'Armada', href: '#/armada' }, { label: data.service }],
                tools: html`${stateBadge(data)} ${actionButtons(data, data.actions)}`,
            })}

            <div class="grid cols-4">
                ${tile({ label: 'CPU', value: data.cpu, iconName: 'cpu', format: (v) => (v === null || v === undefined ? '—' : `${fmt.number(v)}%`) })}
                ${tile({ label: 'Memory', value: megabytes(data.memory), iconName: 'hardDrive', format: (v) => (v === null || v === undefined ? '—' : fmt.bytes(v)) })}
                ${tile({ label: 'Processes (PIDs)', value: data.pids, iconName: 'layers' })}
                ${tile({ label: 'Restarts', value: data.restart_count, iconName: 'refresh', tone: data.restart_count ? 'warning' : '' })}
            </div>

            <div class="grid cols-2">
                ${card({
                    title: 'Container',
                    body: props([
                        ['State', html`${data.status}${data.oom_killed ? html` ${badge('out of memory', 'error')}` : ''}`],
                        ['Started', data.started_at ? html`${fmt.dateTime(data.started_at)} <span class="muted">(${fmt.ago(data.started_at)})</span>` : '—'],
                        ['Finished', data.finished_at && data.state !== 'running' ? html`${fmt.dateTime(data.finished_at)} · exit code ${data.exit_code}` : '—'],
                        ['Error', data.error || '—'],
                        ['Command', html`<span class="mono small">${fmt.truncate(data.command || '', 120)}</span>`],
                        ['Ports', ports(data)],
                        ['Network in / out', data.network_rx !== null ? html`${fmt.bytes(megabytes(data.network_rx))} / ${fmt.bytes(megabytes(data.network_tx))}` : '—'],
                        ['Disk read / write', data.block_read !== null ? html`${fmt.bytes(megabytes(data.block_read))} / ${fmt.bytes(megabytes(data.block_write))}` : '—'],
                    ]),
                })}
                ${card({
                    title: 'Health checks',
                    sub: 'The last checks Docker ran',
                    flush: data.health_checks.length > 0,
                    body: data.health_checks.length ? html`
                        <div class="table-wrap"><table class="table compact">
                            <thead><tr><th>When</th><th class="num">Exit code</th><th>Output</th></tr></thead>
                            <tbody>${data.health_checks.map((check) => html`
                                <tr><td class="small">${check.end ? fmt.ago(Date.parse(check.end) / 1000) : '—'}</td><td class="num">${badge(String(check.exit_code), check.exit_code === 0 ? 'success' : 'error')}</td><td class="mono small">${fmt.truncate(check.output || '', 80)}</td></tr>`)}</tbody>
                        </table></div>` : empty({ compact: true, iconName: 'checkCircle', title: 'This service has no health check' }),
                })}
            </div>

            ${data.processes !== null && data.processes !== undefined ? card({ title: 'Processes', sub: 'Run by Supervisor in this container', flush: true, body: processTable(data.processes, data.actions) }) : ''}

            ${card({
                title: 'Logs',
                sub: 'The output of the container',
                actions: html`<select class="input sm" data-action="lines">${['100', '300', '1000', '2000'].map((value) => html`<option value="${value}" ${value === lines ? 'selected' : ''}>Last ${value} lines</option>`)}</select>`,
                flush: true,
                body: data.logs.length ? html`
                    <div class="logs" style="max-height:520px;overflow:auto;padding:12px 24px;font-family:var(--font-mono);font-size:12px;line-height:1.6">
                        ${data.logs.map((line) => html`<div style="white-space:pre-wrap;word-break:break-all${line.stream === 'stderr' ? ';color:var(--error-500, #e5484d)' : ''}"><span class="muted">${line.time ? fmt.clock(Date.parse(line.time) / 1000) : ''}</span> ${line.line}</div>`)}
                    </div>` : empty({ compact: true, iconName: 'log', title: 'Nothing was written yet' }),
            })}

            <div class="grid cols-2">
                ${card({
                    title: 'Environment',
                    sub: 'Secrets are hidden',
                    flush: true,
                    body: html`<div class="table-wrap" style="max-height:360px;overflow:auto"><table class="table compact">
                        <tbody>${data.environment.map((variable) => html`<tr><td class="mono small">${variable.key}</td><td class="mono small">${fmt.truncate(variable.value, 80)}</td></tr>`)}</tbody>
                    </table></div>`,
                })}
                ${card({
                    title: 'Mounts & networks',
                    body: props([
                        ...data.mounts.map((mount) => [html`<span class="mono small">${mount.destination}</span>`, html`<span class="small">${mount.type} · <span class="mono">${fmt.truncate(mount.source || '', 50)}</span>${mount.writable ? '' : ' · read-only'}</span>`]),
                        ...data.networks.map((network) => [html`${icon('network')} <span class="mono small">${network.name}</span>`, html`<span class="mono small">${network.ip || '—'}</span>`]),
                    ]),
                })}
            </div>`;
    }
}
