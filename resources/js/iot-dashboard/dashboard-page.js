import { initializeEcho } from '../bootstrap';
import { DashboardHistoryRangeController } from './history-range-controller';
import { historySelectionFromLocation } from './history-range-state';
import {
    bootDashboardRuntime,
    destroyDashboardRuntime,
    updateDashboardRuntimeHistoryRange,
    updateDashboardRuntimeWidgets,
} from './runtime/dashboard-runtime';

let historyRangeController = null;

function normalizeDashboardConfig(rawConfig) {
    const dashboardId = Number(rawConfig?.dashboard_id ?? 0);

    if (!Number.isInteger(dashboardId) || dashboardId <= 0) {
        return null;
    }

    const organizationId = Number(rawConfig?.organization_id ?? 0);

    return {
        dashboard_id: dashboardId,
        organization_id: Number.isInteger(organizationId) && organizationId > 0 ? organizationId : null,
        default_history_preset: typeof rawConfig?.default_history_preset === 'string' && rawConfig.default_history_preset.trim() !== ''
            ? rawConfig.default_history_preset
            : '6h',
        snapshot_url: typeof rawConfig?.snapshot_url === 'string' && rawConfig.snapshot_url.trim() !== ''
            ? rawConfig.snapshot_url
            : null,
        widgets: Array.isArray(rawConfig?.widgets) ? rawConfig.widgets : [],
    };
}

function bootDashboardPage() {
    const config = normalizeDashboardConfig(window.iotDashboardConfig);

    if (!config) {
        if (historyRangeController) {
            historyRangeController.destroy();
            historyRangeController = null;
        }

        destroyDashboardRuntime();

        return;
    }

    config.history_range = historySelectionFromLocation(config.default_history_preset);
    initializeEcho(true);
    bootDashboardRuntime(config);

    if (historyRangeController) {
        historyRangeController.destroy();
    }

    historyRangeController = new DashboardHistoryRangeController(config, (historySelection) => {
        if (window.iotDashboardConfig && typeof window.iotDashboardConfig === 'object') {
            window.iotDashboardConfig.history_range = historySelection;
        }

        updateDashboardRuntimeHistoryRange(historySelection);
    });
    historyRangeController.boot();
}

function handleWidgetsUpdated(event) {
    const widgets = Array.isArray(event?.detail?.widgets)
        ? event.detail.widgets
        : [];

    if (!window.iotDashboardConfig || typeof window.iotDashboardConfig !== 'object') {
        window.iotDashboardConfig = {
            dashboard_id: null,
            organization_id: null,
            default_history_preset: '6h',
            snapshot_url: null,
            widgets,
        };
    } else {
        window.iotDashboardConfig.widgets = widgets;
    }

    updateDashboardRuntimeWidgets(widgets);
}

if (!window.__iotDashboardPageBooted) {
    window.__iotDashboardPageBooted = true;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootDashboardPage);
    } else {
        bootDashboardPage();
    }

    document.addEventListener('livewire:navigated', bootDashboardPage);
    window.addEventListener('beforeunload', () => {
        if (historyRangeController) {
            historyRangeController.destroy();
            historyRangeController = null;
        }

        destroyDashboardRuntime();
    });
    window.addEventListener('iot-dashboard-widgets-updated', handleWidgetsUpdated);
}
