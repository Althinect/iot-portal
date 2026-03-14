export class RealtimeManager {
    constructor(onPayload, onSubscriptionStatusChanged = null) {
        this.onPayload = onPayload;
        this.onSubscriptionStatusChanged = onSubscriptionStatusChanged;
        this.widgets = new Map();
        this.channels = new Map();
        this.subscriptionStates = new Map();
        this.isSubscribed = false;
        this.connectionStatusUnsubscribe = null;
    }

    setWidgets(widgets) {
        this.widgets.clear();

        widgets.forEach((widget) => {
            this.widgets.set(widget.id, widget);
        });
    }

    update(widgets) {
        this.setWidgets(widgets);

        const nextChannelNames = this.collectChannelNames();
        const canUseRealtime = nextChannelNames.length > 0;

        if (!canUseRealtime) {
            this.leave();

            return;
        }

        if (!window.Echo) {
            this.leave();

            return;
        }

        this.syncConnectionStatusListener();
        this.syncChannels(nextChannelNames);
    }

    shouldPollWidget(widget) {
        if (!widget.use_polling) {
            return false;
        }

        if (!widget.use_websocket) {
            return true;
        }

        const channelName = this.resolveRealtimeChannel(widget);

        if (typeof channelName !== 'string') {
            return true;
        }

        return this.subscriptionStates.get(channelName) !== true;
    }

    collectChannelNames() {
        return Array.from(new Set(
            Array.from(this.widgets.values())
                .filter((widget) => this.canUseRealtime(widget))
                .map((widget) => this.resolveRealtimeChannel(widget))
                .filter((channelName) => typeof channelName === 'string'),
        ));
    }

    canUseRealtime(widget) {
        return Boolean(widget?.use_websocket)
            && widget?.type !== 'bar_chart'
            && typeof this.resolveRealtimeChannel(widget) === 'string';
    }

    resolveRealtimeChannel(widget) {
        const channelName = widget?.realtime?.channel;

        if (typeof channelName !== 'string' || channelName.trim() === '') {
            return null;
        }

        return channelName;
    }

    syncChannels(nextChannelNames) {
        const nextChannelNameSet = new Set(nextChannelNames);

        Array.from(this.channels.keys()).forEach((channelName) => {
            if (nextChannelNameSet.has(channelName)) {
                return;
            }

            this.leaveChannel(channelName);
        });

        nextChannelNames.forEach((channelName) => {
            if (this.channels.has(channelName)) {
                return;
            }

            const channel = window.Echo.private(channelName);

            this.channels.set(channelName, channel);
            this.subscriptionStates.set(channelName, false);
            channel.listen('.telemetry.received', this.onPayload);

            if (typeof channel.subscribed === 'function') {
                channel.subscribed(() => {
                    this.setChannelSubscribed(channelName, true);
                });
            }

            if (typeof channel.error === 'function') {
                channel.error((error) => {
                    console.warn('IoT dashboard websocket subscription failed', error);
                    this.setChannelSubscribed(channelName, false);
                });
            }
        });

        this.syncSubscribedState();
    }

    syncConnectionStatusListener() {
        if (typeof this.connectionStatusUnsubscribe === 'function') {
            return;
        }

        const connector = window.Echo?.connector;

        if (!connector || typeof connector.onConnectionChange !== 'function') {
            return;
        }

        this.connectionStatusUnsubscribe = connector.onConnectionChange((status) => {
            if (status === 'connected') {
                return;
            }

            Array.from(this.subscriptionStates.keys()).forEach((channelName) => {
                this.subscriptionStates.set(channelName, false);
            });

            this.syncSubscribedState();
        });
    }

    leaveChannel(channelName) {
        const channel = this.channels.get(channelName);

        if (channel && typeof channel.stopListening === 'function') {
            channel.stopListening('.telemetry.received');
        }

        if (window.Echo) {
            window.Echo.leave(channelName);
        }

        this.channels.delete(channelName);
        this.subscriptionStates.delete(channelName);
    }

    setChannelSubscribed(channelName, nextState) {
        if (!this.channels.has(channelName)) {
            return;
        }

        const normalizedState = Boolean(nextState);

        if (this.subscriptionStates.get(channelName) === normalizedState) {
            return;
        }

        this.subscriptionStates.set(channelName, normalizedState);
        this.syncSubscribedState();
    }

    syncSubscribedState() {
        this.setSubscribed(Array.from(this.subscriptionStates.values()).some(Boolean));
    }

    leave() {
        Array.from(this.channels.keys()).forEach((channelName) => {
            this.leaveChannel(channelName);
        });

        if (typeof this.connectionStatusUnsubscribe === 'function') {
            this.connectionStatusUnsubscribe();
            this.connectionStatusUnsubscribe = null;
        }

        this.setSubscribed(false);
    }

    destroy() {
        this.leave();
        this.widgets.clear();
    }

    setSubscribed(nextState) {
        const normalizedState = Boolean(nextState);

        if (this.isSubscribed === normalizedState) {
            return;
        }

        this.isSubscribed = normalizedState;

        if (typeof this.onSubscriptionStatusChanged === 'function') {
            this.onSubscriptionStatusChanged(this.isSubscribed);
        }
    }
}
