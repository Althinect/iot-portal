export function resolveRealtimePayloadChannelId(payload) {
    const channelId = Number(payload?.device_channel_id ?? 0);

    return Number.isInteger(channelId) && channelId > 0 ? channelId : 0;
}
