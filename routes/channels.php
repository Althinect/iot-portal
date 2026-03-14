<?php

use App\Broadcasting\IoTDashboardDeviceTopicChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('App.Domain.Shared.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('iot-dashboard.device.{deviceUuid}.topic.{topicId}', IoTDashboardDeviceTopicChannel::class);
Broadcast::channel('private-iot-dashboard.device.{deviceUuid}.topic.{topicId}', IoTDashboardDeviceTopicChannel::class);
