<?php
// Application events (docs/events.md).
//
// With EVENTS_TOPIC_ARN set, slow and background work leaves the web request: the app
// publishes an event to the SNS topic and answers at once, Lambda functions do the rest
// (timecapsule-dev-mailer writes notifications, timecapsule-dev-opener opens capsules).
// Without it (local docker-compose) the app does that work itself, as before.
//
// Like S3, SNS gets temporary credentials of the server role, there are no keys in .env.

use Aws\Sns\SnsClient;

function events_enabled(): bool
{
    return config('EVENTS_TOPIC_ARN') !== null;
}

function sns_client(): SnsClient
{
    static $client = null;
    if ($client === null) {
        $client = new SnsClient(['region' => config('AWS_REGION', 'eu-north-1')]);
    }
    return $client;
}

function publish_event(string $type, array $fields): void
{
    $event = ['type' => $type, 'version' => 1, 'event_id' => bin2hex(random_bytes(16)),
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z')] + $fields;

    sns_client()->publish([
        'TopicArn' => config('EVENTS_TOPIC_ARN'),
        'Message' => json_encode($event, JSON_THROW_ON_ERROR),
        'MessageAttributes' => [
            'type' => ['DataType' => 'String', 'StringValue' => $type],
        ],
    ]);
}
