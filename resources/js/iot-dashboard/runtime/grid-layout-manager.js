const GRID_STACK_COLUMNS = 24;
const GRID_STACK_CELL_HEIGHT = 96;
const GRID_STACK_MARGIN = '6px';
const MOBILE_BREAKPOINT = '(max-width: 768px)';

export class GridLayoutManager {
    constructor(onNodesResized, readOnly = false) {
        this.onNodesResized = onNodesResized;
        this.readOnly = readOnly;
        this.grid = null;
        this.widgets = new Map();
        this.pendingLayoutUpdates = new Map();
        this.layoutSaveTimer = null;
        this.resizeBound = false;
        this.isMobileLayout = false;
        this.isApplyingResponsiveLayout = false;
        this.desktopLayouts = new Map();
    }

    setWidgets(widgets) {
        this.widgets.clear();

        widgets.forEach((widget) => {
            this.widgets.set(widget.id, widget);
        });
    }

    mount(container, widgets) {
        this.setWidgets(widgets);

        if (!container || !window.GridStack) {
            this.destroyGrid();

            return;
        }

        this.destroyGrid();
        this.bindResizeListener();

        const isMobile = window.matchMedia(MOBILE_BREAKPOINT).matches;

        this.grid = window.GridStack.init({
            column: isMobile ? 1 : GRID_STACK_COLUMNS,
            margin: GRID_STACK_MARGIN,
            cellHeight: GRID_STACK_CELL_HEIGHT,
            float: false,
            animate: true,
            disableDrag: isMobile || this.readOnly,
            disableResize: isMobile || this.readOnly,
        }, container);

        this.grid.on('change', (_event, changedItems) => {
            this.resizeChartsForNodes(changedItems);

            if (this.isApplyingResponsiveLayout || this.isMobileLayout) {
                return;
            }

            this.queueLayoutPersistence(changedItems);
        });

        this.grid.on('resizestop', (_event, element) => {
            this.resizeChartsForNodes([{ el: element }]);
        });

        this.isMobileLayout = window.matchMedia(MOBILE_BREAKPOINT).matches;
        this.applyResponsiveGridMode(true);
    }

    destroy() {
        if (this.layoutSaveTimer) {
            clearTimeout(this.layoutSaveTimer);
            this.layoutSaveTimer = null;
        }

        this.pendingLayoutUpdates.clear();
        this.desktopLayouts.clear();
        this.widgets.clear();
        this.destroyGrid();
    }

    destroyGrid() {
        if (!this.grid) {
            return;
        }

        try {
            this.grid.destroy(false);
        } catch (error) {
            const isKnownDestroyIssue = error instanceof Error && error.name === 'NotFoundError';

            if (!isKnownDestroyIssue) {
                console.warn('GridStack destroy failed', error);
            }
        }

        this.grid = null;
    }

    isMountedOn(container) {
        return Boolean(this.grid && this.grid.el === container);
    }

    bindResizeListener() {
        if (this.resizeBound) {
            return;
        }

        this.resizeBound = true;

        window.addEventListener('resize', () => {
            this.applyResponsiveGridMode();

            if (this.grid && typeof this.grid.onParentResize === 'function') {
                this.grid.onParentResize();
            }

            window.requestAnimationFrame(() => {
                this.resizeChartsForNodes(this.grid?.engine?.nodes ?? []);
            });
        });
    }

    applyResponsiveGridMode(force = false) {
        if (!this.grid) {
            return;
        }

        const shouldUseMobileLayout = window.matchMedia(MOBILE_BREAKPOINT).matches;
        const currentColumnCount = typeof this.grid.getColumn === 'function'
            ? Number(this.grid.getColumn())
            : (shouldUseMobileLayout ? GRID_STACK_COLUMNS : 1);

        if (!force && this.isMobileLayout === shouldUseMobileLayout) {
            return;
        }

        this.isApplyingResponsiveLayout = true;

        if (shouldUseMobileLayout) {
            this.cacheDesktopLayout();
            if (currentColumnCount !== 1) {
                this.grid.column(1, 'list');
            }
            this.grid.enableMove(false);
            this.grid.enableResize(false);
        } else {
            if (currentColumnCount !== GRID_STACK_COLUMNS) {
                this.grid.column(GRID_STACK_COLUMNS, 'moveScale');
            }
            this.restoreDesktopLayout();
            this.grid.enableMove(!this.readOnly);
            this.grid.enableResize(!this.readOnly);
        }

        this.isMobileLayout = shouldUseMobileLayout;
        this.isApplyingResponsiveLayout = false;
    }

    cacheDesktopLayout() {
        if (!this.grid) {
            return;
        }

        this.desktopLayouts.clear();

        this.grid.engine.nodes.forEach((node) => {
            const widgetId = this.parseWidgetIdFromNode(node);

            if (widgetId === null) {
                return;
            }

            this.desktopLayouts.set(widgetId, {
                x: Math.max(0, Number(node.x ?? 0)),
                y: Math.max(0, Number(node.y ?? 0)),
                w: Math.max(1, Math.min(GRID_STACK_COLUMNS, Number(node.w ?? 1))),
                h: Math.max(2, Math.min(12, Number(node.h ?? 4))),
            });
        });
    }

    restoreDesktopLayout() {
        if (!this.grid || this.desktopLayouts.size === 0) {
            return;
        }

        this.desktopLayouts.forEach((layout, widgetId) => {
            const element = document.querySelector(`#iot-dashboard-grid .grid-stack-item[gs-id="${widgetId}"]`);

            if (!element) {
                return;
            }

            this.grid.update(element, layout);

            const widget = this.widgets.get(widgetId);

            if (widget) {
                widget.layout = layout;
            }
        });
    }

    resizeChartsForNodes(nodes) {
        if (!Array.isArray(nodes) || typeof this.onNodesResized !== 'function') {
            return;
        }

        this.onNodesResized(nodes);
    }

    queueLayoutPersistence(nodes) {
        if (this.readOnly || !Array.isArray(nodes) || nodes.length === 0 || !window.axios) {
            return;
        }

        nodes.forEach((node) => {
            const widgetId = this.parseWidgetIdFromNode(node);

            if (widgetId === null) {
                return;
            }

            const widget = this.widgets.get(widgetId);

            if (!widget || typeof widget.layout_url !== 'string' || widget.layout_url.trim() === '') {
                return;
            }

            const x = Math.max(0, Number(node.x ?? 0));
            const y = Math.max(0, Number(node.y ?? 0));
            const w = Math.max(1, Math.min(GRID_STACK_COLUMNS, Number(node.w ?? 1)));
            const h = Math.max(2, Math.min(12, Number(node.h ?? 4)));

            if (!Number.isFinite(x) || !Number.isFinite(y) || !Number.isFinite(w) || !Number.isFinite(h)) {
                return;
            }

            this.pendingLayoutUpdates.set(widgetId, {
                widget,
                layout: {
                    x,
                    y,
                    w,
                    h,
                },
            });
        });

        if (this.layoutSaveTimer) {
            clearTimeout(this.layoutSaveTimer);
        }

        this.layoutSaveTimer = window.setTimeout(() => {
            const updates = Array.from(this.pendingLayoutUpdates.values());
            this.pendingLayoutUpdates.clear();

            updates.forEach(({ widget, layout }) => {
                window.axios.post(widget.layout_url, layout).catch((error) => {
                    console.error('Failed to persist dashboard widget layout', error);
                });

                widget.layout = {
                    ...widget.layout,
                    ...layout,
                    columns: GRID_STACK_COLUMNS,
                    card_height_px: Math.max(2, Math.min(12, Number(layout.h ?? 4))) * GRID_STACK_CELL_HEIGHT,
                };
            });
        }, 250);
    }

    parseWidgetIdFromNode(node) {
        const idValue = node?.id ?? node?.el?.getAttribute('gs-id') ?? null;
        const numericId = Number(idValue);

        if (!Number.isInteger(numericId) || numericId <= 0) {
            return null;
        }

        return numericId;
    }
}
