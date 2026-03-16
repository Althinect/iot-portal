function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function formatTimestamp(value) {
    if (typeof value !== 'string' || value.trim() === '') {
        return 'No recent data';
    }

    const timestamp = new Date(value);

    if (Number.isNaN(timestamp.getTime())) {
        return 'No recent data';
    }

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(timestamp);
}

function resolveLatestPoint(series) {
    const primarySeries = Array.isArray(series) && series.length > 0 ? series[0] : null;
    const points = Array.isArray(primarySeries?.points) ? primarySeries.points : [];

    if (points.length === 0) {
        return null;
    }

    return points[points.length - 1];
}

function resolvePresenceState(widget) {
    const connectionState = typeof widget?.device_connection_state === 'string'
        ? widget.device_connection_state.trim().toLowerCase()
        : '';

    if (connectionState === 'online') {
        return {
            label: 'Online',
            color: '#22c55e',
        };
    }

    if (connectionState === 'offline') {
        return {
            label: 'Offline',
            color: '#ef4444',
        };
    }

    return {
        label: 'Unknown',
        color: '#64748b',
    };
}

export function renderStateCardMarkup(widget, series) {
    const latestPoint = resolveLatestPoint(series);
    const presenceState = resolvePresenceState(widget);
    const metaMarkup = `
        <div class="iot-state-card__meta">
            <div class="iot-state-card__timestamp">${escapeHtml(formatTimestamp(latestPoint?.timestamp ?? null))}</div>
            <span
                class="iot-state-card__presence-dot"
                style="--presence-color: ${escapeHtml(presenceState.color)};"
                title="${escapeHtml(presenceState.label)}"
                aria-label="${escapeHtml(presenceState.label)}"
            ></span>
        </div>
    `;

    if (!latestPoint) {
        return `
            <div class="iot-state-card">
                ${metaMarkup}
                <div class="iot-state-card__empty">NO DATA</div>
            </div>
        `;
    }

    const label = typeof latestPoint.stateLabel === 'string' && latestPoint.stateLabel.trim() !== ''
        ? latestPoint.stateLabel.trim()
        : (latestPoint.rawValue === null || latestPoint.rawValue === undefined ? 'Unknown' : String(latestPoint.rawValue));
    const color = typeof latestPoint.stateColor === 'string' && latestPoint.stateColor.trim() !== ''
        ? latestPoint.stateColor.trim()
        : '#64748b';
    const displayStyle = typeof widget?.display_style === 'string' ? widget.display_style : 'toggle';
    const safeLabel = escapeHtml(label);

    let indicatorMarkup = '';

    if (displayStyle === 'pill') {
        indicatorMarkup = `
            <div class="iot-state-indicator iot-state-indicator--pill" style="--state-color: ${escapeHtml(color)};">
                ${safeLabel}
            </div>
        `;
    } else if (displayStyle === 'dot_label') {
        indicatorMarkup = `
            <div class="iot-state-indicator iot-state-indicator--dot-label" style="--state-color: ${escapeHtml(color)};">
                <span class="iot-state-indicator__dot"></span>
                <span class="iot-state-indicator__label">${safeLabel}</span>
            </div>
        `;
    } else {
        indicatorMarkup = `
            <div class="iot-state-indicator iot-state-indicator--toggle" style="--state-color: ${escapeHtml(color)};">
                <span class="iot-state-indicator__thumb"></span>
                <span class="iot-state-indicator__label">${safeLabel}</span>
            </div>
        `;
    }

    return `
        <div class="iot-state-card">
            ${metaMarkup}
            <div class="iot-state-card__body">
                ${indicatorMarkup}
            </div>
        </div>
    `;
}
