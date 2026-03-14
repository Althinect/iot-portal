export class PollingManager {
    constructor() {
        this.groups = new Map();
    }

    destroy() {
        this.stopAll();
    }

    stopAll() {
        this.groups.forEach((entry) => {
            clearInterval(entry.timerId);
        });

        this.groups.clear();
    }

    sync(widgets, shouldPoll, fetchSnapshots) {
        const nextGroups = new Map();

        widgets.forEach((widget) => {
            if (!shouldPoll(widget)) {
                return;
            }

            const intervalSeconds = Math.max(2, Number(widget.polling_interval_seconds || 10));
            const groupKey = String(intervalSeconds);
            const existingGroup = nextGroups.get(groupKey) ?? {
                intervalSeconds,
                widgetIds: [],
            };

            existingGroup.widgetIds.push(widget.id);
            nextGroups.set(groupKey, existingGroup);
        });

        Array.from(this.groups.keys()).forEach((groupKey) => {
            if (!nextGroups.has(groupKey)) {
                this.stopForGroup(groupKey);
            }
        });

        nextGroups.forEach(({ intervalSeconds, widgetIds }, groupKey) => {
            this.startOrUpdateGroup(groupKey, intervalSeconds, widgetIds, fetchSnapshots);
        });
    }

    stopForGroup(groupKey) {
        const entry = this.groups.get(groupKey);

        if (!entry) {
            return;
        }

        clearInterval(entry.timerId);
        this.groups.delete(groupKey);
    }

    startOrUpdateGroup(groupKey, intervalSeconds, widgetIds, fetchSnapshots) {
        const existingGroup = this.groups.get(groupKey);

        if (existingGroup) {
            existingGroup.widgetIds = [...widgetIds];

            return;
        }

        const nextGroup = {
            intervalSeconds,
            widgetIds: [...widgetIds],
            timerId: null,
        };

        nextGroup.timerId = window.setInterval(() => {
            fetchSnapshots([...nextGroup.widgetIds]);
        }, intervalSeconds * 1000);

        this.groups.set(groupKey, nextGroup);
    }
}
