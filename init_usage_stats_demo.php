<?php

/**
 * Local demo: mock settings + capture init/usage-stats payloads (no real HTTP).
 * Run: php init_usage_stats_demo.php
 */

require __DIR__ . '/vendor/autoload.php';

use wingify\Wingify;
use wingify\Enums\EventEnum;
use wingify\Packages\NetworkLayer\Client\NetworkClientInterface;
use wingify\Packages\NetworkLayer\Models\ResponseModel;

class LoggingNetworkClient implements NetworkClientInterface
{
    /** @var array<int, array<string, mixed>> */
    public static $requests = [];

    public function GET($request)
    {
        self::record('GET', $request->getQuery(), $request->getBody());
        return self::okResponse();
    }

    public function POST($request)
    {
        self::record('POST', $request->getQuery(), $request->getBody());
        return self::okResponse();
    }

    private static function okResponse()
    {
        $response = new ResponseModel();
        $response->setStatusCode(200);
        $response->setTotalAttempts(0);
        return $response;
    }

    private static function record($method, $query, $body)
    {
        self::$requests[] = [
            'method' => $method,
            'eventName' => isset($query['en']) ? $query['en'] : 'unknown',
            'query' => $query,
            'payload' => $body,
        ];
    }
}

function printSection($title)
{
    echo "\n" . str_repeat('=', 72) . "\n{$title}\n" . str_repeat('=', 72) . "\n";
}

function printJsonBlock($label, $data)
{
    echo "\n--- {$label} ---\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function printInternalEvent($request)
{
    $props = isset($request['payload']['d']['event']['props']) ? $request['payload']['d']['event']['props'] : [];
    $data = isset($props['data']) ? $props['data'] : null;

    echo "\n[" . strtoupper($request['eventName']) . "]\n";
    printJsonBlock('query params', $request['query']);
    printJsonBlock('event props.data', $data !== null ? $data : '(absent)');
    printJsonBlock('full payload', $request['payload']);
}

$accountId = '17001252';
$sdkKey = '9a1aebea44a20d648be8c3508d9df786';
$usageStatsAccountId = 99999;

// Minimal valid settings — only what init + usage-stats need
$mockSettings = [
    'accountId' => (int) $accountId,
    'sdkKey' => $sdkKey,
    'version' => 1,
    'usageStatsAccountId' => $usageStatsAccountId,
    'sdkMetaInfo' => ['wasInitializedEarlier' => false],
    'features' => [
        [
            'id' => 1,
            'key' => 'featureFlag1',
            'name' => 'featureFlag1',
            'status' => 'ON',
            'type' => 'FEATURE_FLAG',
            'metrics' => [],
            'rules' => [['campaignId' => 1, 'type' => 'FLAG_TESTING', 'ruleKey' => 'rule1']],
        ],
    ],
    'campaigns' => [
        [
            'id' => 1,
            'key' => 'featureFlag1_rule1',
            'name' => 'featureFlag1 : Testing',
            'type' => 'FLAG_TESTING',
            'status' => 'RUNNING',
            'percentTraffic' => 100,
            'segments' => [],
            'variations' => [
                ['id' => 1, 'name' => 'Control', 'weight' => 50, 'variables' => []],
                ['id' => 2, 'name' => 'Variation-1', 'weight' => 50, 'variables' => []],
            ],
        ],
    ],
];

printSection('Init + Usage Stats payload demo');

// Keep init options minimal — only required fields + mock settings + network capture
$initOptions = [
    'accountId' => $accountId,
    'sdkKey' => $sdkKey,
    'logger' => ['level' => 'ERROR', 'prefix' => '[INIT-USAGE-DEMO]'],
    'settings' => json_encode($mockSettings),
    'network' => ['client' => new LoggingNetworkClient()],
];

LoggingNetworkClient::$requests = [];

$client = Wingify::init($initOptions);
if (!$client) {
    fwrite(STDERR, "SDK init failed\n");
    exit(1);
}

printSection('Captured requests: ' . count(LoggingNetworkClient::$requests));

$countsByEvent = [];
foreach (LoggingNetworkClient::$requests as $request) {
    $eventName = $request['eventName'];
    $countsByEvent[$eventName] = isset($countsByEvent[$eventName]) ? $countsByEvent[$eventName] + 1 : 1;
}
printJsonBlock('counts by event', $countsByEvent);

foreach (LoggingNetworkClient::$requests as $request) {
    if (!in_array($request['eventName'], [EventEnum::SDK_INIT, EventEnum::USAGE_STATS], true)) {
        continue;
    }
    printInternalEvent($request);
}

printSection('Quick checks');
$initRequest = null;
$usageStatsRequest = null;

foreach (LoggingNetworkClient::$requests as $request) {
    if ($request['eventName'] === EventEnum::SDK_INIT) {
        $initRequest = $request;
    }
    if ($request['eventName'] === EventEnum::USAGE_STATS) {
        $usageStatsRequest = $request;
    }
}

if ($initRequest === null) {
    echo "MISSING: " . EventEnum::SDK_INIT . "\n";
} else {
    $data = $initRequest['payload']['d']['event']['props']['data'];
    echo EventEnum::SDK_INIT . " → isSDKInitialized=" . ($data['isSDKInitialized'] ? 'true' : 'false') . "\n";
}

if ($usageStatsRequest === null) {
    echo "MISSING: " . EventEnum::USAGE_STATS . "\n";
} else {
    $data = $usageStatsRequest['payload']['d']['event']['props']['data'];
    echo EventEnum::USAGE_STATS . " → settingsFetchTime={$data['settingsFetchTime']}, sdkInitTime={$data['sdkInitTime']}\n";
    echo "  initConfig.sdkKey = {$data['initConfig']['sdkKey']}\n";
    echo "  query env = {$usageStatsRequest['query']['env']}\n";
}

echo "\nDone.\n";
