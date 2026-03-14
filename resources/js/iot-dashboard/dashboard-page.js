import { initializeEcho } from '../bootstrap';
import {
    bootDashboardRuntime,
    destroyDashboardRuntime,
    updateDashboardRuntimeWidgets,
} from './runtime/dashboard-runtime';

function normalizeDashboardConfig(rawConfig) {
    const dashboardId = Number(rawConfig?.dashboard_id ?? 0);

    if (!Number.isInteger(dashboardId) || dashboardId <= 0) {
        return null;
    }

    const organizationId = Number(rawConfig?.organization_id ?? 0);

    return {
        dashboard_id: dashboardId,
        organization_id: Number.isInteger(organizationId) && organizationId > 0 ? organizationId : null,
        snapshot_url: typeof rawConfig?.snapshot_url === 'string' && rawConfig.snapshot_url.trim() !== ''
            ? rawConfig.snapshot_url
            : null,
        widgets: Array.isArray(rawConfig?.widgets) ? rawConfig.widgets : [],
    };
}

function bootDashboardPage() {
    const config = normalizeDashboardConfig(window.iotDashboardConfig);

    if (!config) {
        destroyDashboardRuntime();

        return;
    }

    initializeEcho(true);
    bootDashboardRuntime(config);
}

function handleWidgetsUpdated(event) {
    const widgets = Array.isArray(event?.detail?.widgets)
        ? event.detail.widgets
        : [];

    if (!window.iotDashboardConfig || typeof window.iotDashboardConfig !== 'object') {
        window.iotDashboardConfig = {
            dashboard_id: null,
            organization_id: null,
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
        destroyDashboardRuntime();
    });
    window.addEventListener('iot-dashboard-widgets-updated', handleWidgetsUpdated);
}
