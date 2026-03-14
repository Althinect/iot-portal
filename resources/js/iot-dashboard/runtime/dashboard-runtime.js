import { normalizeNumericValue } from './theme';
import { GridLayoutManager } from './grid-layout-manager';
import { PollingManager } from './polling-manager';
import { RealtimeManager } from './realtime-manager';
import { lineChartOption } from '../widgets/line-chart/renderer';
import { barChartOption } from '../widgets/bar-chart/renderer';
import { gaugeChartOption } from '../widgets/gauge-chart/renderer';

const WIDGET_TYPES = Object.freeze({
    lineChart: 'line_chart',
    barChart: 'bar_chart',
    gaugeChart: 'gauge_chart',
});

function buildChartOption(widget, series) {
    if (widget?.type === WIDGET_TYPES.gaugeChart) {
        return gaugeChartOption(widget, series);
    }

    if (widget?.type === WIDGET_TYPES.barChart) {
        return barChartOption(widget, series);
    }

    return lineChartOption(series);
}

function normalizeSeriesConfiguration(widget) {
    if (!Array.isArray(widget.series)) {
        return [];
    }

    return widget.series
        .filter((entry) => typeof entry?.key === 'string' && entry.key.trim() !== '')
        .map((entry) => ({
            key: entry.key,
            label: typeof entry.label === 'string' && entry.label.trim() !== '' ? entry.label : entry.key,
            color: typeof entry.color === 'string' && entry.color.trim() !== '' ? entry.color : '#38bdf8',
            points: [],
        }));
}

class DashboardRuntime {
    constructor(config) {
        this.config = config;
        this.widgets = new Map();
        this.charts = new Map();
        this.resizeObservers = new Map();
        this.pollingManager = new PollingManager();
        this.realtimeManager = new RealtimeManager(
            (payload) => this.appendRealtimePayload(payload),
            () => this.syncPolling(),
        );
        this.gridManager = new GridLayoutManager((nodes) => this.resizeChartsForNodes(nodes));
        this.hydrationFrame = null;
        this.gridIntegrityObserver = null;
        this.gridIntegrityTimer = null;
        this.lastHydratedWidgets = Array.isArray(config?.widgets) ? config.widgets : [];
        this.pendingRealtimePayloads = new Map();
        this.realtimeFlushTimers = new Map();
        this.lastRealtimeAppliedAt = new Map();
    }

    boot() {
        this.ensureGridIntegrityObserver();
        this.scheduleHydration(this.lastHydratedWidgets);
    }

    destroy() {
        if (this.hydrationFrame !== null) {
            cancelAnimationFrame(this.hydrationFrame);
            this.hydrationFrame = null;
        }

        if (this.gridIntegrityTimer !== null) {
            clearTimeout(this.gridIntegrityTimer);
            this.gridIntegrityTimer = null;
        }

        if (this.gridIntegrityObserver) {
            this.gridIntegrityObserver.disconnect();
            this.gridIntegrityObserver = null;
        }

        this.pollingManager.destroy();
        this.realtimeManager.destroy();
        this.gridManager.destroy();

        this.resizeObservers.forEach((observer) => {
            observer.disconnect();
        });

        this.resizeObservers.clear();

        this.charts.forEach((chart) => {
            if (!chart.isDisposed()) {
                chart.dispose();
            }
        });

        this.charts.clear();
        this.widgets.clear();
        this.clearAllRealtimeBuffers();
    }

    updateWidgets(rawWidgets) {
        this.lastHydratedWidgets = Array.isArray(rawWidgets) ? rawWidgets : [];
        this.scheduleHydration(this.lastHydratedWidgets);
    }

    scheduleHydration(rawWidgets) {
        this.lastHydratedWidgets = Array.isArray(rawWidgets) ? rawWidgets : [];

        if (this.hydrationFrame !== null) {
            cancelAnimationFrame(this.hydrationFrame);
        }

        this.hydrationFrame = requestAnimationFrame(() => {
            this.hydrationFrame = requestAnimationFrame(() => {
                this.hydrationFrame = null;
                this.hydrate(this.lastHydratedWidgets);
            });
        });
    }

    hydrate(rawWidgets) {
        this.pollingManager.stopAll();
        this.clearAllRealtimeBuffers();

        this.widgets.clear();

        rawWidgets.forEach((rawWidget) => {
            if (!rawWidget || typeof rawWidget.id !== 'number') {
                return;
            }

            const widget = {
                ...rawWidget,
                seriesData: normalizeSeriesConfiguration(rawWidget),
            };

            this.widgets.set(widget.id, widget);
            this.renderWidget(widget);
        });

        this.cleanupOrphanCharts();
        this.requestInitialSnapshots();

        this.realtimeManager.update(Array.from(this.widgets.values()));
        this.syncPolling();

        this.mountGrid();
    }

    syncPolling() {
        const widgets = Array.from(this.widgets.values());

        this.pollingManager.sync(
            widgets,
            (widget) => this.realtimeManager.shouldPollWidget(widget),
            (widgetIds) => this.requestPollingSnapshots(widgetIds),
        );
    }

    mountGrid() {
        const container = document.getElementById('iot-dashboard-grid');
        this.gridManager.mount(container, Array.from(this.widgets.values()));

        window.requestAnimationFrame(() => {
            this.resizeAllCharts();
        });
    }

    hasGridStackRuntimeState(container) {
        if (!container) {
            return false;
        }

        const hasColumnClass = container.classList.contains('gs-24')
            || container.classList.contains('gs-1');

        if (!hasColumnClass) {
            return false;
        }

        if (container.getAttribute('gs-current-row') === null) {
            return false;
        }

        return document.querySelectorAll('style[gs-style-id]').length > 0;
    }

    ensureGridIntegrity() {
        if (this.lastHydratedWidgets.length === 0) {
            return;
        }

        const container = document.getElementById('iot-dashboard-grid');

        if (!container) {
            return;
        }

        const gridMatchesContainer = this.gridManager.isMountedOn(container);

        if (gridMatchesContainer && this.hasGridStackRuntimeState(container)) {
            return;
        }

        this.scheduleHydration(this.lastHydratedWidgets);
    }

    scheduleGridIntegrityCheck() {
        if (this.gridIntegrityTimer !== null) {
            clearTimeout(this.gridIntegrityTimer);
        }

        this.gridIntegrityTimer = window.setTimeout(() => {
            this.gridIntegrityTimer = null;
            this.ensureGridIntegrity();
        }, 120);
    }

    ensureGridIntegrityObserver() {
        if (this.gridIntegrityObserver || typeof window.MutationObserver === 'undefined') {
            return;
        }

        this.gridIntegrityObserver = new window.MutationObserver(() => {
            this.scheduleGridIntegrityCheck();
        });

        this.gridIntegrityObserver.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'style'],
        });
    }

    cleanupOrphanCharts() {
        const widgetIds = new Set(Array.from(this.widgets.keys()));

        Array.from(this.charts.keys()).forEach((widgetId) => {
            if (widgetIds.has(widgetId)) {
                return;
            }

            const chart = this.charts.get(widgetId);

            if (chart && !chart.isDisposed()) {
                chart.dispose();
            }

            this.charts.delete(widgetId);

            const observer = this.resizeObservers.get(widgetId);

            if (observer) {
                observer.disconnect();
                this.resizeObservers.delete(widgetId);
            }

            this.clearRealtimeBufferForWidget(widgetId);
        });
    }

    resizeAllCharts() {
        this.charts.forEach((chart) => {
            if (!chart.isDisposed()) {
                chart.resize();
            }
        });
    }

    resizeChartsForNodes(nodes) {
        if (!Array.isArray(nodes)) {
            return;
        }

        nodes.forEach((node) => {
            const widgetId = this.gridManager.parseWidgetIdFromNode(node);

            if (widgetId === null) {
                return;
            }

            const chart = this.charts.get(widgetId);

            if (chart && !chart.isDisposed()) {
                chart.resize();
            }
        });
    }

    ensureChart(widgetId) {
        if (!window.echarts) {
            return null;
        }

        const target = document.getElementById(`iot-widget-chart-${widgetId}`);

        if (!target) {
            return null;
        }

        const existing = this.charts.get(widgetId);

        if (existing && !existing.isDisposed()) {
            const existingTarget = typeof existing.getDom === 'function'
                ? existing.getDom()
                : null;
            const targetWasReplaced = existingTarget !== target;
            const targetWasEmptied = target.childElementCount === 0;

            if (!targetWasReplaced && !targetWasEmptied) {
                return existing;
            }

            existing.dispose();
            this.charts.delete(widgetId);
        }

        const existingObserver = this.resizeObservers.get(widgetId);

        if (existingObserver) {
            existingObserver.disconnect();
            this.resizeObservers.delete(widgetId);
        }

        const chart = window.echarts.init(target);
        this.charts.set(widgetId, chart);

        if (typeof window.ResizeObserver !== 'undefined') {
            const observer = new window.ResizeObserver(() => {
                if (!chart.isDisposed()) {
                    chart.resize();
                }
            });

            observer.observe(target);
            this.resizeObservers.set(widgetId, observer);
        }

        return chart;
    }

    renderWidget(widget) {
        const chart = this.ensureChart(widget.id);

        if (!chart) {
            return;
        }

        chart.setOption(buildChartOption(widget, widget.seriesData), true);
    }

    requestInitialSnapshots() {
        this.requestSnapshotsForWidgetIds(Array.from(this.widgets.keys()));
    }

    requestPollingSnapshots(widgetIds) {
        this.requestSnapshotsForWidgetIds(widgetIds);
    }

    requestSnapshotsForWidgetIds(widgetIds) {
        const normalizedWidgetIds = Array.from(new Set(
            (Array.isArray(widgetIds) ? widgetIds : [])
                .map((widgetId) => Number(widgetId))
                .filter((widgetId) => Number.isInteger(widgetId) && widgetId > 0),
        ));

        if (!window.axios || normalizedWidgetIds.length === 0) {
            return;
        }

        const dashboardSnapshotUrl = this.resolveDashboardSnapshotUrl(normalizedWidgetIds);

        if (!dashboardSnapshotUrl) {
            normalizedWidgetIds.forEach((widgetId) => {
                const widget = this.widgets.get(widgetId);

                if (widget) {
                    this.requestWidgetSnapshot(widget);
                }
            });

            return;
        }

        window.axios
            .get(dashboardSnapshotUrl)
            .then((response) => {
                this.applySnapshotBatch(response.data);
            })
            .catch((error) => {
                console.error('IoT dashboard polling failed', error);
            });
    }

    resolveDashboardSnapshotUrl(widgetIds) {
        if (typeof this.config?.snapshot_url !== 'string' || this.config.snapshot_url.trim() === '') {
            return null;
        }

        const url = new URL(this.config.snapshot_url, window.location.origin);
        const activeWidgetIds = Array.from(this.widgets.keys()).sort((left, right) => left - right);
        const requestedWidgetIds = [...widgetIds].sort((left, right) => left - right);
        const requestsAllWidgets = activeWidgetIds.length === requestedWidgetIds.length
            && activeWidgetIds.every((widgetId, index) => widgetId === requestedWidgetIds[index]);

        if (!requestsAllWidgets) {
            requestedWidgetIds.forEach((widgetId) => {
                url.searchParams.append('widgets[]', String(widgetId));
            });
        }

        return url.toString();
    }

    requestWidgetSnapshot(widget) {
        if (!window.axios || typeof widget.snapshot_url !== 'string' || widget.snapshot_url.trim() === '') {
            return;
        }

        window.axios
            .get(widget.snapshot_url)
            .then((response) => {
                this.applySnapshotResponse(widget, response.data);
            })
            .catch((error) => {
                console.error('IoT dashboard polling failed', error);
            });
    }

    applySnapshotBatch(payload) {
        if (!Array.isArray(payload?.widgets)) {
            return;
        }

        payload.widgets.forEach((snapshot) => {
            const widgetId = Number(snapshot?.id ?? 0);

            if (!Number.isInteger(widgetId) || widgetId <= 0) {
                return;
            }

            const widget = this.widgets.get(widgetId);

            if (!widget) {
                return;
            }

            this.applySnapshotEntry(widget, snapshot);
        });
    }

    applySnapshotResponse(widget, payload) {
        const snapshot = Array.isArray(payload?.widgets)
            ? payload.widgets.find((item) => Number(item?.id) === Number(widget.id))
            : payload;

        this.applySnapshotEntry(widget, snapshot);
    }

    applySnapshotEntry(widget, snapshot) {
        if (!snapshot || !Array.isArray(snapshot.series)) {
            return;
        }

        this.applySnapshotSeries(widget, snapshot.series);
    }

    applySnapshotSeries(widget, snapshotSeries) {
        const seriesByKey = new Map();

        snapshotSeries.forEach((entry) => {
            if (typeof entry?.key !== 'string') {
                return;
            }

            const points = Array.isArray(entry.points)
                ? entry.points
                    .map((point) => {
                        const value = normalizeNumericValue(point?.value);

                        if (value === null || typeof point?.timestamp !== 'string') {
                            return null;
                        }

                        return {
                            timestamp: point.timestamp,
                            value,
                        };
                    })
                    .filter(Boolean)
                : [];

            seriesByKey.set(entry.key, points);
        });

        widget.seriesData = widget.seriesData.map((series) => ({
            ...series,
            points: seriesByKey.has(series.key) ? seriesByKey.get(series.key) : [],
        }));

        this.renderWidget(widget);
    }

    clearAllRealtimeBuffers() {
        Array.from(this.realtimeFlushTimers.keys()).forEach((widgetId) => {
            this.clearRealtimeBufferForWidget(widgetId);
        });

        this.pendingRealtimePayloads.clear();
        this.realtimeFlushTimers.clear();
        this.lastRealtimeAppliedAt.clear();
    }

    clearRealtimeBufferForWidget(widgetId, preserveLastApplied = false) {
        const timerId = this.realtimeFlushTimers.get(widgetId);

        if (timerId !== undefined) {
            clearTimeout(timerId);
        }

        this.realtimeFlushTimers.delete(widgetId);
        this.pendingRealtimePayloads.delete(widgetId);

        if (!preserveLastApplied) {
            this.lastRealtimeAppliedAt.delete(widgetId);
        }
    }

    resolveRealtimeSampleWindowMilliseconds(widget) {
        const sampleWindowSeconds = Number(widget?.realtime?.sample_window_seconds ?? 0);

        if (!Number.isFinite(sampleWindowSeconds) || sampleWindowSeconds <= 0) {
            return 0;
        }

        return Math.round(sampleWindowSeconds * 1000);
    }

    extractRealtimeSeriesValues(widget, transformedValues) {
        const seriesValues = {};
        let hasValues = false;

        widget.seriesData.forEach((series) => {
            const value = normalizeNumericValue(transformedValues[series.key]);

            if (value === null) {
                return;
            }

            hasValues = true;
            seriesValues[series.key] = value;
        });

        return hasValues ? seriesValues : null;
    }

    scheduleRealtimePayload(widget, recordedAt, seriesValues, waitMilliseconds) {
        this.pendingRealtimePayloads.set(widget.id, {
            recordedAt,
            seriesValues,
        });

        if (this.realtimeFlushTimers.has(widget.id)) {
            return;
        }

        const timerId = window.setTimeout(() => {
            this.realtimeFlushTimers.delete(widget.id);

            const pendingPayload = this.pendingRealtimePayloads.get(widget.id);
            const currentWidget = this.widgets.get(widget.id);

            this.pendingRealtimePayloads.delete(widget.id);

            if (!pendingPayload || !currentWidget) {
                return;
            }

            this.applyRealtimeSeriesValues(currentWidget, pendingPayload.recordedAt, pendingPayload.seriesValues);
        }, waitMilliseconds);

        this.realtimeFlushTimers.set(widget.id, timerId);
    }

    applyRealtimeSeriesValues(widget, recordedAt, seriesValues) {
        this.clearRealtimeBufferForWidget(widget.id, true);

        widget.seriesData = widget.seriesData.map((series) => {
            if (!Object.prototype.hasOwnProperty.call(seriesValues, series.key)) {
                return series;
            }

            const nextPoints = [...series.points, {
                timestamp: recordedAt,
                value: seriesValues[series.key],
            }];

            const minPoints = widget?.type === WIDGET_TYPES.gaugeChart ? 1 : 20;
            const maxPoints = Math.max(minPoints, Number(widget.max_points || 240));

            if (nextPoints.length > maxPoints) {
                nextPoints.splice(0, nextPoints.length - maxPoints);
            }

            return {
                ...series,
                points: nextPoints,
            };
        });

        this.lastRealtimeAppliedAt.set(widget.id, Date.now());
        this.renderWidget(widget);
    }

    handleRealtimeSeriesValues(widget, recordedAt, seriesValues) {
        const sampleWindowMilliseconds = this.resolveRealtimeSampleWindowMilliseconds(widget);

        if (sampleWindowMilliseconds <= 0) {
            this.applyRealtimeSeriesValues(widget, recordedAt, seriesValues);

            return;
        }

        const now = Date.now();
        const lastAppliedAt = this.lastRealtimeAppliedAt.get(widget.id) ?? 0;

        if (lastAppliedAt === 0 || now - lastAppliedAt >= sampleWindowMilliseconds) {
            this.applyRealtimeSeriesValues(widget, recordedAt, seriesValues);

            return;
        }

        this.scheduleRealtimePayload(
            widget,
            recordedAt,
            seriesValues,
            Math.max(0, sampleWindowMilliseconds - (now - lastAppliedAt)),
        );
    }

    appendRealtimePayload(payload) {
        const topicId = Number(payload?.schema_version_topic_id ?? 0);

        if (!Number.isInteger(topicId) || topicId <= 0) {
            return;
        }

        const deviceUuid = typeof payload?.device_uuid === 'string'
            ? payload.device_uuid
            : null;

        if (typeof deviceUuid !== 'string' || deviceUuid.trim() === '') {
            return;
        }

        const transformedValues = payload?.transformed_values;

        if (typeof transformedValues !== 'object' || transformedValues === null) {
            return;
        }

        const recordedAt = typeof payload.recorded_at === 'string'
            ? payload.recorded_at
            : new Date().toISOString();

        this.widgets.forEach((widget) => {
            if (!this.isMatchingRealtimeStream(widget, topicId, deviceUuid)) {
                return;
            }

            const seriesValues = this.extractRealtimeSeriesValues(widget, transformedValues);

            if (!seriesValues) {
                return;
            }

            this.handleRealtimeSeriesValues(widget, recordedAt, seriesValues);
        });
    }

    isMatchingRealtimeStream(widget, topicId, deviceUuid) {
        const widgetTopicId = Number(widget.topic?.id ?? 0);
        const widgetDeviceUuid = typeof widget.device?.uuid === 'string'
            ? widget.device.uuid
            : null;

        if (!widget.use_websocket || widgetTopicId !== topicId || widget?.type === WIDGET_TYPES.barChart) {
            return false;
        }

        if (typeof widgetDeviceUuid !== 'string' || widgetDeviceUuid.trim() === '') {
            return false;
        }

        return widgetDeviceUuid === deviceUuid;
    }
}

let runtime = null;

export function bootDashboardRuntime(config) {
    if (runtime) {
        runtime.destroy();
    }

    runtime = new DashboardRuntime(config);
    runtime.boot();

    return runtime;
}

export function updateDashboardRuntimeWidgets(widgets) {
    if (!runtime) {
        return;
    }

    runtime.updateWidgets(widgets);
}

export function destroyDashboardRuntime() {
    if (!runtime) {
        return;
    }

    runtime.destroy();
    runtime = null;
}
