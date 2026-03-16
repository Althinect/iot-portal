import {
    absoluteSelectionFromDatetimeLocal,
    browserTimeZoneLabel,
    cloneHistorySelection,
    createRelativeHistorySelection,
    historySelectionFromLocation,
    historySelectionLabel,
    historySelectionRelativeExpressions,
    historySelectionSnapshotRange,
    isAbsoluteHistorySelection,
    isHistoryPreset,
    toDatetimeLocalValue,
    writeHistorySelectionToUrl,
} from './history-range-state';

export class DashboardHistoryRangeController {
    constructor(config, onApplySelection) {
        this.config = config;
        this.onApplySelection = onApplySelection;
        this.root = document.querySelector('[data-iot-history-range]');
        this.appliedSelection = cloneHistorySelection(config?.history_range, config?.default_history_preset);
        this.draftSelection = cloneHistorySelection(this.appliedSelection, config?.default_history_preset);
        this.handleDocumentClick = this.handleDocumentClick.bind(this);
        this.handleEscape = this.handleEscape.bind(this);
        this.handlePopState = this.handlePopState.bind(this);
        this.errorMessage = '';
        this.eventController = null;
    }

    boot() {
        if (!this.root) {
            return;
        }

        this.trigger = this.root.querySelector('[data-iot-history-trigger]');
        this.triggerLabel = this.root.querySelector('[data-iot-history-trigger-label]');
        this.popover = this.root.querySelector('[data-iot-history-popover]');
        this.relativePane = this.root.querySelector('[data-iot-history-relative-pane]');
        this.absolutePane = this.root.querySelector('[data-iot-history-absolute-pane]');
        this.relativeFrom = this.root.querySelector('[data-iot-history-relative-from]');
        this.relativeUntil = this.root.querySelector('[data-iot-history-relative-until]');
        this.absoluteFrom = this.root.querySelector('[data-iot-history-absolute-from]');
        this.absoluteUntil = this.root.querySelector('[data-iot-history-absolute-until]');
        this.errorElement = this.root.querySelector('[data-iot-history-error]');
        this.timeZoneLabel = this.root.querySelector('[data-iot-history-timezone]');
        this.applyButton = this.root.querySelector('[data-iot-history-apply]');
        this.resetButton = this.root.querySelector('[data-iot-history-reset]');
        this.customButton = this.root.querySelector('[data-iot-history-custom]');
        this.presetButtons = Array.from(this.root.querySelectorAll('[data-iot-history-preset]'));

        this.bindEvents();
        this.render();
        this.closePopover();
    }

    destroy() {
        if (!this.root) {
            return;
        }

        this.eventController?.abort();
        this.eventController = null;
    }

    bindEvents() {
        this.eventController?.abort();
        this.eventController = new AbortController();

        const listenerOptions = { signal: this.eventController.signal };

        this.trigger?.addEventListener('click', () => {
            if (this.isPopoverOpen()) {
                this.closePopover();

                return;
            }

            this.openPopover();
        }, listenerOptions);

        this.applyButton?.addEventListener('click', () => {
            this.commitDraftSelection();
        }, listenerOptions);

        this.resetButton?.addEventListener('click', () => {
            this.errorMessage = '';
            this.applySelection(createRelativeHistorySelection(this.config?.default_history_preset), { syncUrl: true });
            this.closePopover();
        }, listenerOptions);

        this.customButton?.addEventListener('click', () => {
            this.switchDraftToAbsoluteMode();
        }, listenerOptions);

        this.absoluteFrom?.addEventListener('input', () => {
            this.errorMessage = '';
            this.draftSelection = { mode: 'absolute' };
            this.renderErrorState();
        }, listenerOptions);

        this.absoluteUntil?.addEventListener('input', () => {
            this.errorMessage = '';
            this.draftSelection = { mode: 'absolute' };
            this.renderErrorState();
        }, listenerOptions);

        this.presetButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const preset = button.dataset.iotHistoryPreset;

                if (!isHistoryPreset(preset)) {
                    return;
                }

                this.errorMessage = '';
                this.draftSelection = createRelativeHistorySelection(preset);
                this.renderPopover();
            }, listenerOptions);
        });

        document.addEventListener('click', this.handleDocumentClick, listenerOptions);
        document.addEventListener('keydown', this.handleEscape, listenerOptions);
        window.addEventListener('popstate', this.handlePopState, listenerOptions);
    }

    handleDocumentClick(event) {
        if (!this.isPopoverOpen() || this.root?.contains(event.target)) {
            return;
        }

        this.closePopover();
    }

    handleEscape(event) {
        if (event.key !== 'Escape' || !this.isPopoverOpen()) {
            return;
        }

        this.closePopover();
    }

    handlePopState() {
        this.appliedSelection = historySelectionFromLocation(this.config?.default_history_preset);
        this.draftSelection = cloneHistorySelection(this.appliedSelection, this.config?.default_history_preset);
        this.applySelection(this.appliedSelection, { syncUrl: false });
    }

    isPopoverOpen() {
        return this.popover?.hidden === false;
    }

    openPopover() {
        if (!this.popover) {
            return;
        }

        this.errorMessage = '';
        this.draftSelection = cloneHistorySelection(this.appliedSelection, this.config?.default_history_preset);
        this.renderPopover();
        this.popover.hidden = false;
        this.trigger?.setAttribute('aria-expanded', 'true');
    }

    closePopover() {
        if (!this.popover) {
            return;
        }

        this.popover.hidden = true;
        this.trigger?.setAttribute('aria-expanded', 'false');
        this.draftSelection = cloneHistorySelection(this.appliedSelection, this.config?.default_history_preset);
        this.renderPopover();
    }

    switchDraftToAbsoluteMode(shouldSeedValues = true) {
        const nextAbsoluteRange = isAbsoluteHistorySelection(this.draftSelection)
            ? this.draftSelection
            : (
                isAbsoluteHistorySelection(this.appliedSelection)
                    ? this.appliedSelection
                    : historySelectionSnapshotRange(this.draftSelection ?? this.appliedSelection)
            );

        this.draftSelection = {
            mode: 'absolute',
            fromAtIso: shouldSeedValues ? nextAbsoluteRange?.history_from_at ?? nextAbsoluteRange?.fromAtIso ?? null : null,
            untilAtIso: shouldSeedValues ? nextAbsoluteRange?.history_until_at ?? nextAbsoluteRange?.untilAtIso ?? null : null,
        };

        this.renderPopover();
    }

    commitDraftSelection() {
        if (this.draftSelection?.mode === 'absolute') {
            const nextSelection = absoluteSelectionFromDatetimeLocal(
                this.absoluteFrom?.value,
                this.absoluteUntil?.value,
            );

            if (!nextSelection) {
                this.errorMessage = 'Choose a valid browser-local range where the end is after the start.';
                this.renderPopover();

                return;
            }

            this.errorMessage = '';
            this.applySelection(nextSelection, { syncUrl: true });
            this.closePopover();

            return;
        }

        this.errorMessage = '';
        this.applySelection(this.draftSelection, { syncUrl: true });
        this.closePopover();
    }

    applySelection(selection, { syncUrl }) {
        this.appliedSelection = cloneHistorySelection(selection, this.config?.default_history_preset);
        this.draftSelection = cloneHistorySelection(this.appliedSelection, this.config?.default_history_preset);

        if (syncUrl) {
            writeHistorySelectionToUrl(this.appliedSelection);
        }

        if (typeof this.onApplySelection === 'function') {
            this.onApplySelection(this.appliedSelection);
        }

        this.render();
    }

    render() {
        if (!this.root) {
            return;
        }

        if (this.triggerLabel) {
            this.triggerLabel.textContent = historySelectionLabel(this.appliedSelection);
        }

        this.renderPopover();
    }

    renderPopover() {
        if (!this.root) {
            return;
        }

        if (this.timeZoneLabel) {
            this.timeZoneLabel.textContent = browserTimeZoneLabel();
        }

        const isAbsoluteMode = this.draftSelection?.mode === 'absolute';

        if (this.relativePane) {
            this.relativePane.hidden = isAbsoluteMode;
        }

        if (this.absolutePane) {
            this.absolutePane.hidden = !isAbsoluteMode;
        }

        if (!isAbsoluteMode) {
            const expressions = historySelectionRelativeExpressions(this.draftSelection);

            if (this.relativeFrom) {
                this.relativeFrom.value = expressions.from;
            }

            if (this.relativeUntil) {
                this.relativeUntil.value = expressions.until;
            }
        } else {
            if (this.absoluteFrom) {
                this.absoluteFrom.value = toDatetimeLocalValue(this.draftSelection?.fromAtIso);
            }

            if (this.absoluteUntil) {
                this.absoluteUntil.value = toDatetimeLocalValue(this.draftSelection?.untilAtIso);
            }
        }

        this.presetButtons.forEach((button) => {
            const preset = button.dataset.iotHistoryPreset;

            button.classList.toggle(
                'is-active',
                !isAbsoluteMode && preset === this.draftSelection?.preset,
            );
        });

        if (this.customButton) {
            this.customButton.classList.toggle('is-active', isAbsoluteMode);
        }

        if (this.errorElement) {
            this.errorElement.hidden = this.errorMessage === '';
            this.errorElement.textContent = this.errorMessage;
        }
    }

    renderErrorState() {
        if (!this.errorElement) {
            return;
        }

        this.errorElement.hidden = this.errorMessage === '';
        this.errorElement.textContent = this.errorMessage;
    }
}
