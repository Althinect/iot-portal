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

function formatNumericValue(value) {
    if (typeof value !== 'number' || !Number.isFinite(value)) {
        return 'No data';
    }

    return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(value);
}

function normalizeDisplayUnit(unit) {
    if (typeof unit !== 'string' || unit.trim() === '') {
        return '';
    }

    const normalizedUnit = unit.trim();

    if (normalizedUnit.toLowerCase() === 'volts') {
        return 'V';
    }

    if (normalizedUnit.toLowerCase() === 'watts') {
        return 'W';
    }

    if (normalizedUnit.toLowerCase() === 'seconds') {
        return 's';
    }

    if (normalizedUnit.toLowerCase() === 'celsius') {
        return '°C';
    }

    if (normalizedUnit.toLowerCase() === 'percent') {
        return '%';
    }

    return normalizedUnit;
}

function resolveDisplayLabel(entry) {
    const key = typeof entry?.key === 'string' ? entry.key.trim() : '';
    const label = typeof entry?.label === 'string' ? entry.label.trim() : '';

    if (/^[VA]\d+$/i.test(key)) {
        return key.toUpperCase();
    }

    if (key.toLowerCase() === 'total_energy_kwh') {
        return 'Total kWh';
    }

    const strippedSuffix = label.replace(/\s*\([^)]*\)\s*$/, '');
    const strippedPrefix = strippedSuffix.replace(/^(voltage|current)\s+/i, '').trim();

    if (strippedPrefix !== '') {
        return strippedPrefix;
    }

    return label !== '' ? label : key;
}

function resolveLatestTimestamp(series) {
    const timestamps = Array.isArray(series)
        ? series
            .flatMap((entry) => Array.isArray(entry?.points) ? entry.points : [])
            .map((point) => typeof point?.timestamp === 'string' ? point.timestamp : null)
            .filter(Boolean)
        : [];

    return timestamps.length > 0 ? timestamps[timestamps.length - 1] : null;
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

function renderValueMarkup(entry, latestPoint) {
    const unit = normalizeDisplayUnit(entry?.unit);
    const value = formatNumericValue(latestPoint?.value ?? null);

    if (value === 'No data') {
        return `<div class="iot-status-summary__value">${escapeHtml(value)}</div>`;
    }

    return `
        <div class="iot-status-summary__value">
            <span class="iot-status-summary__value-number">${escapeHtml(value)}</span>
            ${unit !== '' ? `<span class="iot-status-summary__value-unit">${escapeHtml(unit)}</span>` : ''}
        </div>
    `;
}

function renderSeriesItemMarkup(entry) {
    const points = Array.isArray(entry?.points) ? entry.points : [];
    const latestPoint = points.length > 0 ? points[points.length - 1] : null;
    const label = resolveDisplayLabel(entry);
    const color = typeof entry?.color === 'string' ? entry.color.trim() : '';
    const normalizedColor = color.toLowerCase();
    const hasAccent = normalizedColor !== '' && normalizedColor !== '#000000' && normalizedColor !== '#000';

    return `
        <div class="iot-status-summary__item${hasAccent ? ' is-accented' : ''}"${hasAccent ? ` style="--summary-color: ${escapeHtml(color)};"` : ''}>
            <div class="iot-status-summary__label">${escapeHtml(label)}</div>
            ${renderValueMarkup(entry, latestPoint)}
        </div>
    `;
}

function resolveLayoutRows(widget, series) {
    const validKeys = new Set(
        (Array.isArray(series) ? series : [])
            .map((entry) => typeof entry?.key === 'string' ? entry.key.trim() : '')
            .filter((key) => key !== ''),
    );

    const configuredRows = Array.isArray(widget?.layout_rows) ? widget.layout_rows : [];
    const rows = [];
    const seen = new Set();

    configuredRows.forEach((row) => {
        const configuredTileKeys = Array.isArray(row?.tile_keys)
            ? row.tile_keys
            : Array.isArray(row?.parameter_keys)
                ? row.parameter_keys
                : [];
        const tileKeys = configuredTileKeys
            .map((tileKey) => typeof tileKey === 'string' ? tileKey.trim() : '')
            .filter((tileKey) => tileKey !== '' && validKeys.has(tileKey) && !seen.has(tileKey));

        tileKeys.forEach((tileKey) => {
            seen.add(tileKey);
        });

        if (tileKeys.length > 0) {
            rows.push(tileKeys);
        }
    });

    if (rows.length > 0) {
        return rows;
    }

    return [
        Array.from(validKeys),
    ];
}

function renderRowsMarkup(widget, series) {
    const seriesByKey = new Map(
        (Array.isArray(series) ? series : [])
            .filter((entry) => typeof entry?.key === 'string' && entry.key.trim() !== '')
            .map((entry) => [entry.key, entry]),
    );

    return resolveLayoutRows(widget, series)
        .map((row) => {
            const rowEntries = row
                .map((tileKey) => seriesByKey.get(tileKey))
                .filter(Boolean);

            if (rowEntries.length === 0) {
                return '';
            }

            return `
                <div class="iot-status-summary__row" style="--summary-columns: ${rowEntries.length};">
                    ${rowEntries.map((entry) => renderSeriesItemMarkup(entry)).join('')}
                </div>
            `;
        })
        .join('');
}

export function renderStatusSummaryMarkup(widget, series) {
    const presenceState = resolvePresenceState(widget);
    const latestTimestamp = resolveLatestTimestamp(series);
    const hasPoints = Array.isArray(series)
        && series.some((entry) => Array.isArray(entry?.points) && entry.points.length > 0);

    const metaMarkup = `
        <div class="iot-status-summary__meta">
            <div class="iot-status-summary__timestamp">${escapeHtml(formatTimestamp(latestTimestamp))}</div>
            <span
                class="iot-status-summary__presence-dot"
                style="--presence-color: ${escapeHtml(presenceState.color)};"
                title="${escapeHtml(presenceState.label)}"
                aria-label="${escapeHtml(presenceState.label)}"
            ></span>
        </div>
    `;

    if (!hasPoints) {
        return `
            <div class="iot-status-summary">
                ${metaMarkup}
                <div class="iot-status-summary__empty">NO DATA</div>
            </div>
        `;
    }

    return `
        <div class="iot-status-summary">
            ${metaMarkup}
            <div class="iot-status-summary__rows">
                ${renderRowsMarkup(widget, series)}
            </div>
        </div>
    `;
}
