import { escapeHtml, formatCompactTimestamp, resolvePresenceState } from '../shared/meta';

function resolveStatusTone(status) {
    const normalizedStatus = typeof status === 'string'
        ? status.trim().toLowerCase()
        : '';

    if (normalizedStatus === 'alert') {
        return 'is-alert';
    }

    if (normalizedStatus === 'normal') {
        return 'is-normal';
    }

    if (normalizedStatus === 'offline') {
        return 'is-offline';
    }

    if (normalizedStatus === 'inactive') {
        return 'is-inactive';
    }

    return 'is-no-data';
}

export function renderThresholdStatusCardMarkup(widget) {
    const card = widget?.card && typeof widget.card === 'object' ? widget.card : null;

    if (!card) {
        return `
            <div class="iot-threshold-status-card iot-threshold-status-card--empty">
                <div class="iot-threshold-status-card__empty">No threshold policy is connected to this widget.</div>
            </div>
        `;
    }

    const ruleLabel = typeof card.rule_label === 'string' && card.rule_label.trim() !== ''
        ? card.rule_label.trim()
        : 'Custom rule';
    const policyId = Number(card?.policy_id ?? 0);
    const currentValueDisplay = typeof card.current_value_display === 'string' && card.current_value_display.trim() !== ''
        ? card.current_value_display.trim()
        : '—';
    const meta = resolveMeta(card);
    const presenceState = resolvePresenceState(card.connection_state);
    const prominentStatusMarkup = renderProminentStatusMarkup(card);
    const valueToneClass = resolveValueToneClass(card);

    return `
        <article class="iot-threshold-status-card ${resolveStatusTone(card.status)}">
            <header class="iot-threshold-status-card__header">
                <div class="iot-threshold-status-card__meta-label">${escapeHtml(meta.label)}</div>
                <div class="iot-threshold-status-card__meta-row">
                    <div class="iot-threshold-status-card__meta-value">${escapeHtml(formatCompactTimestamp(meta.timestamp))}</div>
                    <span
                        class="iot-threshold-status-card__presence-dot"
                        style="--presence-color: ${escapeHtml(presenceState.color)};"
                        title="${escapeHtml(presenceState.label)}"
                        aria-label="${escapeHtml(presenceState.label)}"
                    ></span>
                </div>
            </header>
            ${renderRuleMarkup(ruleLabel, policyId, widget?.read_only === true)}
            ${prominentStatusMarkup}
            <div class="iot-threshold-status-card__value ${valueToneClass}">${escapeHtml(currentValueDisplay)}</div>
        </article>
    `;
}

function renderRuleMarkup(ruleLabel, policyId, readOnly) {
    if (readOnly || !Number.isInteger(policyId) || policyId <= 0) {
        return `<div class="iot-threshold-status-card__rule">${escapeHtml(ruleLabel)}</div>`;
    }

    return `
        <button
            type="button"
            class="iot-threshold-status-card__rule iot-threshold-status-card__rule-action"
            data-iot-threshold-policy-edit="${escapeHtml(String(policyId))}"
        >
            ${escapeHtml(ruleLabel)}
        </button>
    `;
}

function renderProminentStatusMarkup(card) {
    const thresholdState = typeof card?.threshold_state === 'string'
        ? card.threshold_state.trim().toLowerCase()
        : '';

    if (thresholdState !== 'normal' && thresholdState !== 'alert') {
        return '';
    }

    if (thresholdState === 'alert') {
        const breachedAt = typeof card?.threshold_breached_at === 'string' && card.threshold_breached_at.trim() !== ''
            ? card.threshold_breached_at.trim()
            : null;
        const label = breachedAt ? formatCompactTimestamp(breachedAt) : 'Alert';

        return `<div class="iot-threshold-status-card__status is-alert">${escapeHtml(label)}</div>`;
    }

    return '<div class="iot-threshold-status-card__status is-normal">Normal</div>';
}

function resolveValueToneClass(card) {
    const thresholdState = typeof card?.threshold_state === 'string'
        ? card.threshold_state.trim().toLowerCase()
        : '';

    if (thresholdState === 'alert') {
        return 'is-alert';
    }

    if (thresholdState === 'normal') {
        return 'is-normal';
    }

    return 'is-neutral';
}

function resolveMeta(card) {
    const normalizedStatus = typeof card?.status === 'string'
        ? card.status.trim().toLowerCase()
        : '';

    if (normalizedStatus === 'alert') {
        return {
            label: 'Alerted',
            timestamp: card?.alert_triggered_at ?? card?.display_timestamp ?? card?.last_telemetry_at ?? null,
        };
    }

    if (normalizedStatus === 'offline') {
        return {
            label: 'Last online',
            timestamp: card?.last_online_at ?? card?.display_timestamp ?? card?.last_telemetry_at ?? null,
        };
    }

    if (normalizedStatus === 'normal') {
        return {
            label: 'Updated',
            timestamp: card?.display_timestamp ?? card?.last_telemetry_at ?? null,
        };
    }

    return {
        label: 'Last update',
        timestamp: card?.display_timestamp ?? card?.last_telemetry_at ?? null,
    };
}
