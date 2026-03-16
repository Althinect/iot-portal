const HISTORY_PRESETS = Object.freeze({
    '6h': {
        label: 'Last 6 hours',
        durationMs: 6 * 60 * 60 * 1000,
        relativeFrom: 'now-6h',
    },
    '12h': {
        label: 'Last 12 hours',
        durationMs: 12 * 60 * 60 * 1000,
        relativeFrom: 'now-12h',
    },
    '24h': {
        label: 'Last 24 hours',
        durationMs: 24 * 60 * 60 * 1000,
        relativeFrom: 'now-24h',
    },
    '2d': {
        label: 'Last 2 days',
        durationMs: 2 * 24 * 60 * 60 * 1000,
        relativeFrom: 'now-2d',
    },
    '7d': {
        label: 'Last 7 days',
        durationMs: 7 * 24 * 60 * 60 * 1000,
        relativeFrom: 'now-7d',
    },
});

const DEFAULT_HISTORY_PRESET = '6h';

function isValidDate(value) {
    return value instanceof Date && !Number.isNaN(value.getTime());
}

function padNumber(value) {
    return String(value).padStart(2, '0');
}

export function browserTimeZoneLabel() {
    const timeZone = window.Intl?.DateTimeFormat?.().resolvedOptions?.().timeZone;

    return typeof timeZone === 'string' && timeZone.trim() !== ''
        ? timeZone
        : 'Browser time';
}

export function defaultHistoryPreset() {
    return DEFAULT_HISTORY_PRESET;
}

export function isHistoryPreset(value) {
    return typeof value === 'string' && Object.prototype.hasOwnProperty.call(HISTORY_PRESETS, value);
}

export function normalizeHistoryPreset(value) {
    return isHistoryPreset(value) ? value : DEFAULT_HISTORY_PRESET;
}

export function historyPresetLabel(preset) {
    return HISTORY_PRESETS[normalizeHistoryPreset(preset)].label;
}

export function historyPresetRelativeFrom(preset) {
    return HISTORY_PRESETS[normalizeHistoryPreset(preset)].relativeFrom;
}

export function createRelativeHistorySelection(preset) {
    return {
        mode: 'relative',
        preset: normalizeHistoryPreset(preset),
    };
}

export function createAbsoluteHistorySelection(fromAtIso, untilAtIso) {
    return {
        mode: 'absolute',
        fromAtIso,
        untilAtIso,
    };
}

export function cloneHistorySelection(selection, defaultPreset = DEFAULT_HISTORY_PRESET) {
    if (selection?.mode === 'absolute') {
        return createAbsoluteHistorySelection(selection.fromAtIso ?? null, selection.untilAtIso ?? null);
    }

    return createRelativeHistorySelection(selection?.preset ?? defaultPreset);
}

export function isAbsoluteHistorySelection(selection) {
    if (selection?.mode !== 'absolute') {
        return false;
    }

    const fromAt = new Date(selection.fromAtIso ?? '');
    const untilAt = new Date(selection.untilAtIso ?? '');

    return isValidDate(fromAt) && isValidDate(untilAt) && untilAt.getTime() > fromAt.getTime();
}

export function historySelectionFromLocation(defaultPreset = DEFAULT_HISTORY_PRESET) {
    const url = new URL(window.location.href);
    const preset = url.searchParams.get('history_preset');
    const fromAtIso = url.searchParams.get('history_from_at');
    const untilAtIso = url.searchParams.get('history_until_at');

    if (typeof fromAtIso === 'string' && typeof untilAtIso === 'string') {
        const absoluteSelection = createAbsoluteHistorySelection(fromAtIso, untilAtIso);

        if (isAbsoluteHistorySelection(absoluteSelection)) {
            return absoluteSelection;
        }
    }

    return createRelativeHistorySelection(preset ?? defaultPreset);
}

export function historySelectionLabel(selection) {
    if (selection?.mode === 'absolute' && isAbsoluteHistorySelection(selection)) {
        return formatAbsoluteHistoryRangeLabel(selection.fromAtIso, selection.untilAtIso);
    }

    return historyPresetLabel(selection?.preset ?? DEFAULT_HISTORY_PRESET);
}

export function historySelectionRelativeExpressions(selection) {
    const preset = selection?.preset ?? DEFAULT_HISTORY_PRESET;

    return {
        from: historyPresetRelativeFrom(preset),
        until: 'now',
    };
}

export function historySelectionSnapshotRange(selection, now = new Date()) {
    if (selection?.mode === 'absolute' && isAbsoluteHistorySelection(selection)) {
        return {
            history_from_at: selection.fromAtIso,
            history_until_at: selection.untilAtIso,
        };
    }

    const preset = HISTORY_PRESETS[normalizeHistoryPreset(selection?.preset)];
    const end = new Date(now);

    if (!isValidDate(end)) {
        return null;
    }

    const start = new Date(end.getTime() - preset.durationMs);

    return {
        history_from_at: start.toISOString(),
        history_until_at: end.toISOString(),
    };
}

export function writeHistorySelectionToUrl(selection) {
    const url = new URL(window.location.href);

    url.searchParams.delete('history_preset');
    url.searchParams.delete('history_from_at');
    url.searchParams.delete('history_until_at');

    if (selection?.mode === 'absolute' && isAbsoluteHistorySelection(selection)) {
        url.searchParams.set('history_from_at', selection.fromAtIso);
        url.searchParams.set('history_until_at', selection.untilAtIso);
    } else {
        url.searchParams.set('history_preset', normalizeHistoryPreset(selection?.preset));
    }

    window.history.replaceState({ historySelection: selection }, '', url);
}

export function formatAbsoluteHistoryRangeLabel(fromAtIso, untilAtIso) {
    const fromAt = new Date(fromAtIso ?? '');
    const untilAt = new Date(untilAtIso ?? '');

    if (!isValidDate(fromAt) || !isValidDate(untilAt)) {
        return historyPresetLabel(DEFAULT_HISTORY_PRESET);
    }

    const formatter = new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });

    return `${formatter.format(fromAt)} - ${formatter.format(untilAt)}`;
}

export function toDatetimeLocalValue(isoValue) {
    const date = new Date(isoValue ?? '');

    if (!isValidDate(date)) {
        return '';
    }

    const year = date.getFullYear();
    const month = padNumber(date.getMonth() + 1);
    const day = padNumber(date.getDate());
    const hours = padNumber(date.getHours());
    const minutes = padNumber(date.getMinutes());

    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

export function absoluteSelectionFromDatetimeLocal(fromValue, untilValue) {
    if (typeof fromValue !== 'string' || typeof untilValue !== 'string') {
        return null;
    }

    const fromAt = new Date(fromValue);
    const untilAt = new Date(untilValue);

    if (!isValidDate(fromAt) || !isValidDate(untilAt) || untilAt.getTime() <= fromAt.getTime()) {
        return null;
    }

    return createAbsoluteHistorySelection(fromAt.toISOString(), untilAt.toISOString());
}
