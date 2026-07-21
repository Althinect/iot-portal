function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function resolveDisplayMode(widget) {
    return typeof widget?.display_mode === 'string' && widget.display_mode.trim() !== ''
        ? widget.display_mode.trim()
        : 'standard';
}

function formatStandardTimestamp(value) {
    if (typeof value !== 'string' || value.trim() === '') {
        return 'No recent data';
    }

    const timestamp = new Date(value);

    if (Number.isNaN(timestamp.getTime())) {
        return 'No recent data';
    }

    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).format(timestamp);
}

function formatSriLankanTimestamp(value) {
    if (typeof value !== 'string' || value.trim() === '') {
        return 'No recent data';
    }

    const timestamp = new Date(value);

    if (Number.isNaN(timestamp.getTime())) {
        return 'No recent data';
    }

    const year = String(timestamp.getFullYear());
    const month = String(timestamp.getMonth() + 1).padStart(2, '0');
    const day = String(timestamp.getDate()).padStart(2, '0');
    const hours = String(timestamp.getHours()).padStart(2, '0');
    const minutes = String(timestamp.getMinutes()).padStart(2, '0');
    const seconds = String(timestamp.getSeconds()).padStart(2, '0');

    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

function formatTimestamp(value, displayMode) {
    return displayMode === 'sri_lankan_temperature'
        ? formatSriLankanTimestamp(value)
        : formatStandardTimestamp(value);
}

function resolveConnectionTone(connectionState) {
    const normalizedState = typeof connectionState === 'string'
        ? connectionState.trim().toLowerCase()
        : '';

    if (normalizedState === 'online') {
        return 'is-online';
    }

    if (normalizedState === 'offline') {
        return 'is-offline';
    }

    return 'is-unknown';
}

function resolveStatusTone(status) {
    const normalizedStatus = typeof status === 'string'
        ? status.trim().toLowerCase()
        : '';

    if (normalizedStatus === 'normal') {
        return 'is-normal';
    }

    if (normalizedStatus === 'alert') {
        return 'is-alert';
    }

    if (normalizedStatus === 'offline') {
        return 'is-offline';
    }

    return 'is-no-data';
}

function renderStandardCard(card, readOnly) {
    const deviceName = typeof card?.device_name === 'string' && card.device_name.trim() !== ''
        ? card.device_name.trim()
        : 'Unnamed device';
    const connectionState = typeof card?.connection_state === 'string' && card.connection_state.trim() !== ''
        ? card.connection_state.trim().toUpperCase()
        : 'UNKNOWN';
    const statusLabel = typeof card?.status_label === 'string' && card.status_label.trim() !== ''
        ? card.status_label.trim().toUpperCase()
        : 'NO DATA';
    const rangeLabel = typeof card?.range_label === 'string' && card.range_label.trim() !== ''
        ? card.range_label.trim()
        : 'No thresholds configured';
    const currentValueDisplay = typeof card?.current_value_display === 'string' && card.current_value_display.trim() !== ''
        ? card.current_value_display.trim()
        : '—';
    const editUrl = !readOnly && typeof card?.edit_url === 'string' && card.edit_url.trim() !== ''
        ? card.edit_url.trim()
        : null;
    const displayTimestamp = resolveCardTimestamp(card);

    return `
        <article class="iot-threshold-grid__card">
            <header class="iot-threshold-grid__card-header">
                <div class="iot-threshold-grid__device-name">${escapeHtml(deviceName)}</div>
                ${editUrl ? `<a class="iot-threshold-grid__edit-link" href="${escapeHtml(editUrl)}">Edit</a>` : ''}
            </header>
            <div class="iot-threshold-grid__badge ${resolveConnectionTone(connectionState)}">${escapeHtml(connectionState)}</div>
            <div class="iot-threshold-grid__timestamp">${escapeHtml(formatTimestamp(displayTimestamp, 'standard'))}</div>
            <div class="iot-threshold-grid__range">${escapeHtml(rangeLabel)}</div>
            <div class="iot-threshold-grid__badge ${resolveStatusTone(card?.status)}">${escapeHtml(statusLabel)}</div>
            <div class="iot-threshold-grid__value">${escapeHtml(currentValueDisplay)}</div>
        </article>
    `;
}

function renderSriLankanCard(card) {
    const deviceName = typeof card?.device_name === 'string' && card.device_name.trim() !== ''
        ? card.device_name.trim()
        : 'Unnamed device';
    const connectionState = typeof card?.connection_state === 'string' && card.connection_state.trim() !== ''
        ? card.connection_state.trim().toUpperCase()
        : 'UNKNOWN';
    const statusLabel = typeof card?.status_label === 'string' && card.status_label.trim() !== ''
        ? card.status_label.trim().toUpperCase()
        : 'NO DATA';
    const rangeLabel = typeof card?.range_label === 'string' && card.range_label.trim() !== ''
        ? card.range_label.trim()
        : 'No thresholds configured';
    const currentValueDisplay = typeof card?.current_value_display === 'string' && card.current_value_display.trim() !== ''
        ? card.current_value_display.trim()
        : '—';
    const displayTimestamp = resolveCardTimestamp(card);

    return `
        <article class="iot-threshold-grid__card iot-threshold-grid__card--sri-lankan">
            <div class="iot-threshold-grid__device-name iot-threshold-grid__device-name--sri-lankan">${escapeHtml(deviceName)}</div>
            <div class="iot-threshold-grid__badge iot-threshold-grid__badge--sri-lankan ${resolveConnectionTone(connectionState)}">${escapeHtml(connectionState)}</div>
            <div class="iot-threshold-grid__timestamp iot-threshold-grid__timestamp--sri-lankan">${escapeHtml(formatTimestamp(displayTimestamp, 'sri_lankan_temperature'))}</div>
            <div class="iot-threshold-grid__range iot-threshold-grid__range--sri-lankan">${escapeHtml(rangeLabel)}</div>
            <div class="iot-threshold-grid__badge iot-threshold-grid__badge--sri-lankan ${resolveStatusTone(card?.status)}">${escapeHtml(statusLabel)}</div>
            <div class="iot-threshold-grid__value iot-threshold-grid__value--sri-lankan">${escapeHtml(currentValueDisplay)}</div>
        </article>
    `;
}

function resolveCardTimestamp(card) {
    const normalizedStatus = typeof card?.status === 'string'
        ? card.status.trim().toLowerCase()
        : '';

    if (normalizedStatus === 'alert') {
        return card?.alert_triggered_at ?? card?.display_timestamp ?? card?.last_telemetry_at ?? null;
    }

    if (normalizedStatus === 'offline') {
        return card?.last_online_at ?? card?.display_timestamp ?? card?.last_telemetry_at ?? null;
    }

    return card?.display_timestamp ?? card?.last_telemetry_at ?? null;
}

export function renderThresholdStatusGridMarkup(widget) {
    const cards = Array.isArray(widget?.cards) ? widget.cards : [];
    const displayMode = resolveDisplayMode(widget);

    if (cards.length === 0) {
        return `
            <div class="iot-threshold-grid iot-threshold-grid--empty">
                <div class="iot-threshold-grid__empty">No threshold cards are available for this dashboard.</div>
            </div>
        `;
    }

    const cardMarkup = displayMode === 'sri_lankan_temperature'
        ? cards.map((card) => renderSriLankanCard(card)).join('')
        : cards.map((card) => renderStandardCard(card, widget?.read_only === true)).join('');

    return `
        <div class="iot-threshold-grid ${displayMode === 'sri_lankan_temperature' ? 'iot-threshold-grid--sri-lankan' : ''}">
            ${cardMarkup}
        </div>
    `;
}
