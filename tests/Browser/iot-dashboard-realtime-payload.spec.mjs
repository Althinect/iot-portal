import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { expect, test } from '@playwright/test';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const payloadModuleUrl = pathToFileURL(
    path.join(projectRoot, 'resources/js/iot-dashboard/runtime/realtime-payload.js'),
).href;

test('resolves realtime payload channel ids from device profile telemetry events', async () => {
    const { resolveRealtimePayloadChannelId } = await import(payloadModuleUrl);

    expect(resolveRealtimePayloadChannelId({ device_channel_id: 42 })).toBe(42);
    expect(resolveRealtimePayloadChannelId({ device_channel_id: '42' })).toBe(42);
    expect(resolveRealtimePayloadChannelId({ schema_version_topic_id: 42 })).toBe(0);
    expect(resolveRealtimePayloadChannelId({ device_channel_id: 0 })).toBe(0);
});
