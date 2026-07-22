#include <Arduino.h>
#include <ArduinoJson.h>
#include <ESP_I2S.h>
#include <PubSubClient.h>
#include <WiFi.h>
#include <Wire.h>
#include "esp32-hal-rmt.h"

#include "alarm_secrets.h"

namespace {

constexpr uint8_t I2C_SDA_PIN = 11;
constexpr uint8_t I2C_SCL_PIN = 10;
constexpr uint8_t I2S_MCLK_PIN = 12;
constexpr uint8_t I2S_BCLK_PIN = 13;
constexpr uint8_t I2S_LRCLK_PIN = 14;
constexpr uint8_t I2S_DATA_OUT_PIN = 16;
constexpr uint8_t RGB_DATA_PIN = 38;

constexpr uint8_t ES8311_ADDRESS = 0x18;
constexpr uint8_t ES8311_DAC_VOLUME = 0x78;
constexpr uint8_t TCA9555_ADDRESS = 0x20;
constexpr uint8_t TCA9555_OUTPUT_PORT_1 = 0x03;
constexpr uint8_t TCA9555_CONFIG_PORT_1 = 0x07;
constexpr uint8_t AMPLIFIER_ENABLE_MASK = 0x01;

constexpr uint8_t RGB_LED_COUNT = 7;
constexpr size_t RGB_SYMBOL_COUNT = RGB_LED_COUNT * 24;
constexpr uint8_t ALARM_RED_LEVEL = 48;

constexpr uint32_t AUDIO_SAMPLE_RATE = 16000;
constexpr uint16_t AUDIO_FRAMES_PER_CHUNK = 160;
constexpr int16_t AUDIO_AMPLITUDE = 6000;
constexpr uint16_t LOW_TONE_HZ = 660;
constexpr uint16_t HIGH_TONE_HZ = 880;
constexpr uint32_t TONE_INTERVAL_MS = 350;
constexpr uint32_t RGB_INTERVAL_MS = 250;
constexpr uint32_t WIFI_RETRY_INTERVAL_MS = 15000;
constexpr uint32_t MQTT_RETRY_INTERVAL_MS = 3000;

constexpr char DEVICE_ID[] = "alarm-demo-01";
constexpr char TOPIC_CONTROL[] = "devices/alarm-demo-01/control";
constexpr char TOPIC_STATE[] = "devices/alarm-demo-01/state";
constexpr char TOPIC_PRESENCE[] = "devices/alarm-demo-01/presence";

WiFiClient networkClient;
PubSubClient mqttClient(networkClient);
I2SClass i2s;

rmt_data_t rgbSymbols[RGB_SYMBOL_COUNT];
int16_t audioSamples[AUDIO_FRAMES_PER_CHUNK * 2];

bool alarmOn = false;
bool codecReady = false;
bool amplifierReady = false;
bool rgbReady = false;
bool mqttSessionActive = false;
bool rgbLit = false;

float audioPhase = 0.0F;
uint32_t lastWifiAttemptAt = 0;
uint32_t lastMqttAttemptAt = 0;
uint32_t lastRgbChangeAt = 0;
int32_t hotspotChannel = 0;

char serialCommand[16] = {};
size_t serialCommandLength = 0;

bool writeI2cRegister(uint8_t address, uint8_t registerAddress, uint8_t value)
{
    Wire.beginTransmission(address);
    Wire.write(registerAddress);
    Wire.write(value);

    return Wire.endTransmission(true) == 0;
}

bool readI2cRegister(uint8_t address, uint8_t registerAddress, uint8_t &value)
{
    Wire.beginTransmission(address);
    Wire.write(registerAddress);

    if (Wire.endTransmission(false) != 0) {
        return false;
    }

    if (Wire.requestFrom(address, static_cast<uint8_t>(1)) != 1) {
        return false;
    }

    value = Wire.read();

    return true;
}

bool updateI2cRegister(uint8_t address, uint8_t registerAddress, uint8_t clearMask, uint8_t setMask)
{
    uint8_t value = 0;

    if (!readI2cRegister(address, registerAddress, value)) {
        return false;
    }

    value = (value & static_cast<uint8_t>(~clearMask)) | setMask;

    return writeI2cRegister(address, registerAddress, value);
}

bool initializeCodec()
{
    const bool initialized =
        writeI2cRegister(ES8311_ADDRESS, 0x00, 0x1F)
        && (delay(20), true)
        && writeI2cRegister(ES8311_ADDRESS, 0x00, 0x00)
        && writeI2cRegister(ES8311_ADDRESS, 0x00, 0x80)
        && writeI2cRegister(ES8311_ADDRESS, 0x01, 0x3F)
        && updateI2cRegister(ES8311_ADDRESS, 0x02, 0xF8, 0x00)
        && writeI2cRegister(ES8311_ADDRESS, 0x03, 0x10)
        && writeI2cRegister(ES8311_ADDRESS, 0x04, 0x10)
        && writeI2cRegister(ES8311_ADDRESS, 0x05, 0x00)
        && updateI2cRegister(ES8311_ADDRESS, 0x06, 0x1F, 0x03)
        && updateI2cRegister(ES8311_ADDRESS, 0x07, 0x3F, 0x00)
        && writeI2cRegister(ES8311_ADDRESS, 0x08, 0xFF)
        && updateI2cRegister(ES8311_ADDRESS, 0x00, 0x40, 0x00)
        && writeI2cRegister(ES8311_ADDRESS, 0x09, 0x0C)
        && writeI2cRegister(ES8311_ADDRESS, 0x0A, 0x0C)
        && writeI2cRegister(ES8311_ADDRESS, 0x0D, 0x01)
        && writeI2cRegister(ES8311_ADDRESS, 0x0E, 0x02)
        && writeI2cRegister(ES8311_ADDRESS, 0x12, 0x00)
        && writeI2cRegister(ES8311_ADDRESS, 0x13, 0x10)
        && writeI2cRegister(ES8311_ADDRESS, 0x1C, 0x6A)
        && writeI2cRegister(ES8311_ADDRESS, 0x37, 0x08)
        && writeI2cRegister(ES8311_ADDRESS, 0x32, ES8311_DAC_VOLUME)
        && updateI2cRegister(ES8311_ADDRESS, 0x31, 0x00, 0x60);

    Serial.println(initialized ? "[Audio] ES8311 ready (muted)" : "[Audio] ES8311 initialization failed");

    return initialized;
}

bool initializeAmplifier()
{
    const bool initialized =
        updateI2cRegister(TCA9555_ADDRESS, TCA9555_CONFIG_PORT_1, AMPLIFIER_ENABLE_MASK, 0x00)
        && updateI2cRegister(TCA9555_ADDRESS, TCA9555_OUTPUT_PORT_1, AMPLIFIER_ENABLE_MASK, 0x00);

    Serial.println(initialized ? "[Audio] NS4150B amplifier ready (disabled)" : "[Audio] amplifier expander initialization failed");

    return initialized;
}

void setAmplifierEnabled(bool enabled)
{
    if (!amplifierReady) {
        return;
    }

    updateI2cRegister(
        TCA9555_ADDRESS,
        TCA9555_OUTPUT_PORT_1,
        AMPLIFIER_ENABLE_MASK,
        enabled ? AMPLIFIER_ENABLE_MASK : 0x00
    );

    if (codecReady) {
        updateI2cRegister(ES8311_ADDRESS, 0x31, 0x60, enabled ? 0x00 : 0x60);
    }

    if (enabled) {
        delay(20);
    }
}

void setRgbColor(uint8_t red, uint8_t green, uint8_t blue)
{
    if (!rgbReady) {
        return;
    }

    const uint8_t colors[] = {green, red, blue};
    size_t symbolIndex = 0;

    for (uint8_t ledIndex = 0; ledIndex < RGB_LED_COUNT; ++ledIndex) {
        for (uint8_t color : colors) {
            for (int8_t bit = 7; bit >= 0; --bit) {
                const bool one = (color & (1U << bit)) != 0;
                rgbSymbols[symbolIndex].level0 = 1;
                rgbSymbols[symbolIndex].duration0 = one ? 8 : 4;
                rgbSymbols[symbolIndex].level1 = 0;
                rgbSymbols[symbolIndex].duration1 = one ? 4 : 8;
                ++symbolIndex;
            }
        }
    }

    rmtWrite(RGB_DATA_PIN, rgbSymbols, RGB_SYMBOL_COUNT, RMT_WAIT_FOR_EVER);
    delayMicroseconds(80);
}

void writeSilence()
{
    memset(audioSamples, 0, sizeof(audioSamples));
    i2s.write(reinterpret_cast<uint8_t *>(audioSamples), sizeof(audioSamples));
}

void renderAlarmAudio()
{
    if (!alarmOn || !codecReady) {
        return;
    }

    const uint16_t frequency = ((millis() / TONE_INTERVAL_MS) % 2 == 0) ? LOW_TONE_HZ : HIGH_TONE_HZ;
    const float phaseStep = 2.0F * PI * static_cast<float>(frequency) / static_cast<float>(AUDIO_SAMPLE_RATE);

    for (uint16_t frame = 0; frame < AUDIO_FRAMES_PER_CHUNK; ++frame) {
        const int16_t sample = static_cast<int16_t>(sinf(audioPhase) * AUDIO_AMPLITUDE);
        audioSamples[frame * 2] = sample;
        audioSamples[frame * 2 + 1] = sample;
        audioPhase += phaseStep;

        if (audioPhase >= 2.0F * PI) {
            audioPhase -= 2.0F * PI;
        }
    }

    i2s.write(reinterpret_cast<uint8_t *>(audioSamples), sizeof(audioSamples));
}

void updateAlarmLights()
{
    if (!alarmOn || millis() - lastRgbChangeAt < RGB_INTERVAL_MS) {
        return;
    }

    lastRgbChangeAt = millis();
    rgbLit = !rgbLit;
    setRgbColor(rgbLit ? ALARM_RED_LEVEL : 0, 0, 0);
}

void publishState(const char *commandId = nullptr)
{
    if (!mqttClient.connected()) {
        return;
    }

    JsonDocument state;
    state["alarm_on"] = alarmOn;

    if (commandId != nullptr && commandId[0] != '\0') {
        state["_meta"]["command_id"] = commandId;
    }

    char payload[192] = {};
    serializeJson(state, payload, sizeof(payload));
    const bool published = mqttClient.publish(TOPIC_STATE, payload, true);

    Serial.printf("[MQTT] State alarm_on=%s%s\n", alarmOn ? "true" : "false", published ? "" : " (publish failed)");
}

void setAlarmEnabled(bool enabled, const char *commandId = nullptr, bool shouldPublishState = true)
{
    const bool changed = alarmOn != enabled;
    alarmOn = enabled;

    if (enabled) {
        setAmplifierEnabled(true);
        rgbLit = true;
        setRgbColor(ALARM_RED_LEVEL, 0, 0);
        lastRgbChangeAt = millis();
    } else {
        if (codecReady) {
            writeSilence();
        }

        setAmplifierEnabled(false);
        rgbLit = false;
        setRgbColor(0, 0, 0);
    }

    if (changed) {
        Serial.printf("[Alarm] %s\n", enabled ? "ON" : "OFF");
    }

    if (shouldPublishState) {
        publishState(commandId);
    }
}

void handleMqttMessage(char *topic, byte *payload, unsigned int length)
{
    if (strcmp(topic, TOPIC_CONTROL) != 0) {
        return;
    }

    JsonDocument command;
    const DeserializationError error = deserializeJson(command, payload, length);

    if (error || !command["alarm_on"].is<bool>()) {
        Serial.println("[MQTT] Ignored invalid alarm command");

        return;
    }

    const bool enabled = command["alarm_on"].as<bool>();
    const char *commandId = command["_meta"]["command_id"] | "";
    setAlarmEnabled(enabled, commandId, true);
}

void attemptWifiConnection()
{
    if (WiFi.status() == WL_CONNECTED || millis() - lastWifiAttemptAt < WIFI_RETRY_INTERVAL_MS) {
        return;
    }

    lastWifiAttemptAt = millis();
    Serial.printf("[WiFi] Connecting (previous status=%d)...\n", WiFi.status());
    WiFi.begin(ALARM_WIFI_SSID, ALARM_WIFI_PASSWORD, hotspotChannel);
}

void scanForHotspot()
{
    const int16_t networkCount = WiFi.scanNetworks(false, true);

    for (int16_t networkIndex = 0; networkIndex < networkCount; ++networkIndex) {
        if (WiFi.SSID(networkIndex) == ALARM_WIFI_SSID) {
            hotspotChannel = WiFi.channel(networkIndex);

            break;
        }
    }

    WiFi.scanDelete();
    Serial.printf("[WiFi] Target hotspot %s on channel %ld\n", hotspotChannel > 0 ? "found" : "not found", hotspotChannel);
}

void handleWifiDisconnect(WiFiEvent_t event, WiFiEventInfo_t info)
{
    Serial.printf("[WiFi] Disconnected (reason=%u)\n", info.wifi_sta_disconnected.reason);
}

void attemptMqttConnection()
{
    if (WiFi.status() != WL_CONNECTED || mqttClient.connected() || millis() - lastMqttAttemptAt < MQTT_RETRY_INTERVAL_MS) {
        return;
    }

    lastMqttAttemptAt = millis();
    Serial.printf("[MQTT] Connecting to %s:%u...\n", ALARM_MQTT_HOST, ALARM_MQTT_PORT);

    const bool connected = ALARM_MQTT_USERNAME[0] == '\0'
        ? mqttClient.connect(DEVICE_ID, nullptr, nullptr, TOPIC_PRESENCE, 1, true, "offline")
        : mqttClient.connect(
            DEVICE_ID,
            ALARM_MQTT_USERNAME,
            ALARM_MQTT_PASSWORD,
            TOPIC_PRESENCE,
            1,
            true,
            "offline"
        );

    if (!connected) {
        Serial.printf("[MQTT] Connection failed (rc=%d)\n", mqttClient.state());

        return;
    }

    mqttSessionActive = true;
    mqttClient.publish(TOPIC_PRESENCE, "online", true);
    mqttClient.subscribe(TOPIC_CONTROL, 1);
    publishState();
    Serial.printf("[MQTT] Online and subscribed to %s\n", TOPIC_CONTROL);
}

void maintainConnectivity()
{
    if (WiFi.status() != WL_CONNECTED) {
        if (mqttSessionActive) {
            mqttSessionActive = false;
            setAlarmEnabled(false, nullptr, false);
            Serial.println("[Safety] Network lost; alarm forced OFF");
        }

        attemptWifiConnection();

        return;
    }

    if (mqttSessionActive && !mqttClient.connected()) {
        mqttSessionActive = false;
        setAlarmEnabled(false, nullptr, false);
        Serial.println("[Safety] MQTT lost; alarm forced OFF");
    }

    attemptMqttConnection();

    if (mqttClient.connected()) {
        mqttClient.loop();
    }
}

void processSerialCommand()
{
    while (Serial.available() > 0) {
        const char input = static_cast<char>(Serial.read());

        if (input == '\r' || input == '\n') {
            if (serialCommandLength == 0) {
                continue;
            }

            serialCommand[serialCommandLength] = '\0';

            if (strcasecmp(serialCommand, "ON") == 0) {
                setAlarmEnabled(true);
            } else if (strcasecmp(serialCommand, "OFF") == 0) {
                setAlarmEnabled(false);
            } else {
                Serial.println("[Serial] Use ON or OFF");
            }

            serialCommandLength = 0;

            continue;
        }

        if (serialCommandLength < sizeof(serialCommand) - 1) {
            serialCommand[serialCommandLength++] = input;
        }
    }
}

} // namespace

void setup()
{
    Serial.begin(115200);
    delay(400);
    Serial.println("\nWaveshare ESP32-S3 Audio Alarm");

    Wire.begin(I2C_SDA_PIN, I2C_SCL_PIN);
    Wire.setClock(400000);

    rgbReady = rmtInit(RGB_DATA_PIN, RMT_TX_MODE, RMT_MEM_NUM_BLOCKS_1, 10000000);
    setRgbColor(0, 0, 0);

    i2s.setPins(I2S_BCLK_PIN, I2S_LRCLK_PIN, I2S_DATA_OUT_PIN, -1, I2S_MCLK_PIN);
    const bool i2sReady = i2s.begin(
        I2S_MODE_STD,
        AUDIO_SAMPLE_RATE,
        I2S_DATA_BIT_WIDTH_16BIT,
        I2S_SLOT_MODE_STEREO,
        I2S_STD_SLOT_BOTH
    );
    codecReady = i2sReady && initializeCodec();
    amplifierReady = initializeAmplifier();
    setAlarmEnabled(false, nullptr, false);

    Serial.printf("[Hardware] audio=%s rgb=%s\n", codecReady && amplifierReady ? "ready" : "unavailable", rgbReady ? "ready" : "unavailable");
    Serial.println("[Serial] Type ON or OFF for a local hardware test");

    WiFi.mode(WIFI_STA);
    WiFi.setAutoReconnect(true);
    WiFi.setSleep(false);
    WiFi.onEvent(handleWifiDisconnect, WiFiEvent_t::ARDUINO_EVENT_WIFI_STA_DISCONNECTED);
    scanForHotspot();
    mqttClient.setServer(ALARM_MQTT_HOST, ALARM_MQTT_PORT);
    mqttClient.setCallback(handleMqttMessage);
    mqttClient.setBufferSize(512);
    lastWifiAttemptAt = millis() - WIFI_RETRY_INTERVAL_MS;
    lastMqttAttemptAt = millis() - MQTT_RETRY_INTERVAL_MS;
}

void loop()
{
    processSerialCommand();
    maintainConnectivity();
    updateAlarmLights();
    renderAlarmAudio();
}
