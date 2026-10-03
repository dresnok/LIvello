<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Warsaw');

$configPath = __DIR__ . '/fast-backup-config.json';
// Ścieżka historii jest rozwiązywana według aktualnego system_paths.backup_dir.

$defaultPathSchedule = [
    'mode' => 'daily_once',
    'daily_once_time' => '21:00',
    'times' => ['08:00', '12:00', '22:00'],
    'every_n_days' => 2,
    'start_date' => date('Y-m-d'),
    'month_day' => 1,
];

$defaultConfig = [
    'enabled' => true,
    'mode' => 'daily_once',
    'times' => ['08:00', '12:00', '22:00'],
    'daily_once_time' => '21:00',
    'every_n_days' => 2,
    'start_date' => date('Y-m-d'),
    'month_day' => 1,
    'theme' => 'paper',
    'backup_scope' => 'changed_only',
    'backup_output_mode' => 'normal',
    'file_filters' => [
        'include' => '*',
        'include_extensions' => '*',
        'exclude_extensions' => 'ini',
        'exclude_directories' => [],
    ],
    'system_paths' => [
        'backup_dir' => 'api/backup',
        'php_dir' => 'api/backup',
    ],
    'paths' => [
        'scan' => [],
    ],
];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = (string)($_GET['action'] ?? '');

        if ($action === 'history') {
            $stored = readJson($configPath, $defaultConfig);
            $historyPath = resolveHistoryPath(is_array($stored) ? $stored : $defaultConfig);
            respond([
                'status' => 'ok',
                'history' => readJson($historyPath, []),
            ]);
        }

        $saved = readJson($configPath, $defaultConfig);

        $config = normalizeConfig(
            is_array($saved) ? $saved : [],
            $defaultConfig,
            $defaultPathSchedule
        );

        writeJsonAtomic($configPath, $config);

        respond([
            'status' => 'ok',
            'config' => $config,
        ]);
    }
	
	
	function validatePaths(array $data): never
{
    $paths = $data['paths'] ?? [];

    if (!is_array($paths)) {
        respond([
            'valid' => false,
            'message' => 'Nieprawidłowa lista ścieżek.',
        ], 400);
    }

    foreach ($paths as $item) {
        if (!is_array($item)) {
            continue;
        }

        $path = trim((string)($item['path'] ?? ''));

        if ($path === '') {
            respond([
                'valid' => false,
                'message' => 'Nie podano ścieżki skanowania.',
            ], 400);
        }

        $normalized = normalizeConfigPath($path);
        $projectRoot = dirname(__DIR__, 2);
        $projectRoot = rtrim(
            str_replace('\\', '/', realpath($projectRoot) ?: $projectRoot),
            '/'
        );

        if (
            !preg_match('/^[A-Za-z]:\//', $normalized) &&
            !str_starts_with($normalized, '/')
        ) {
            $normalized = $projectRoot . '/' . ltrim($normalized, '/');
        }

        if (!is_dir($normalized) || !is_readable($normalized)) {
            respond([
                'valid' => false,
                'message' => 'Nie znaleziono katalogu skanowania: ' . $path,
            ], 400);
        }
    }

    respond([
        'valid' => true,
        'message' => '',
    ]);
}

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (
            isset($_POST['action']) &&
            $_POST['action'] === 'upload_background'
        ) {
            uploadBackground();
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);

        if (!is_array($data)) {
            throw new RuntimeException('Nieprawidłowe dane JSON.');
        }

        if (($data['action'] ?? '') === 'cleanup_history') {
            $stored = readJson($configPath, $defaultConfig);
            $historyPath = resolveHistoryPath(is_array($stored) ? $stored : $defaultConfig);
            cleanupHistory($data, $historyPath);
        }

if (($data['action'] ?? '') === 'validate_paths') {
    validatePaths($data);
}


if (($data['action'] ?? '') === 'delete_backup_data') {
    deleteBackupData($data, $configPath, $defaultConfig);
}

function deleteBackupData(
    array $data,
    string $configPath,
    array $defaultConfig
): never {
    $targets = $data['targets'] ?? [];

    if (!is_array($targets)) {
        throw new RuntimeException('Nieprawidłowa lista danych do usunięcia.');
    }

    $stored = readJson($configPath, $defaultConfig);
    $config = is_array($stored) ? $stored : $defaultConfig;

    $backupDir = dirname(
        resolveHistoryPath($config)
    );

    $allowed = [
        'locks',
        'manifests',
        'zips',
        'history',
    ];

    foreach ($targets as $target) {
        if (!in_array($target, $allowed, true)) {
            continue;
        }

        if ($target === 'history') {
            $historyPath = $backupDir . '/backup_history.json';

            if (is_file($historyPath)) {
                @unlink($historyPath);
            }

            continue;
        }

        $path = $backupDir . '/' . $target;

        if (is_dir($path)) {
            deleteDirectoryContents($path);
        }
    }

    respond([
        'status' => 'saved',
        'message' => 'Wybrane dane backupu zostały usunięte.',
    ]);
}


function deleteDirectoryContents(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = $directory . '/' . $name;

        if (is_dir($path)) {
            deleteDirectoryContents($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}

        $config = normalizeConfig(
            $data,
            $defaultConfig,
            $defaultPathSchedule
        );

        writeJsonAtomic($configPath, $config);

        respond([
            'status' => 'saved',
            'message' => 'Konfiguracja została zapisana.',
            'config' => $config,
        ]);
    }

    respond([
        'status' => 'error',
        'message' => 'Niedozwolona metoda.',
    ], 405);

} catch (Throwable $e) {
    respond([
        'status' => 'error',
        'message' => $e->getMessage(),
    ], 500);
}

/** Wspólna z fast-backup.php lokalizacja historii po zmianie katalogu wynikowego. */
function resolveHistoryPath(array $config): string
{
    $systemPaths = is_array($config['system_paths'] ?? null) ? $config['system_paths'] : [];
    $directory = trim(str_replace('\\', '/', (string)($systemPaths['backup_dir'] ?? 'api/backup')));
    if ($directory === '') $directory = 'api/backup';

    $projectRoot = dirname(__DIR__, 2);
    $projectRoot = rtrim(str_replace('\\', '/', realpath($projectRoot) ?: $projectRoot), '/');
    if (preg_match('/^[A-Za-z]:\//', $directory) || str_starts_with($directory, '/')) {
        $backupDir = rtrim($directory, '/');
    } else {
        $backupDir = $projectRoot . '/' . trim($directory, '/');
    }
    return $backupDir . '/backup_history.json';
}

function normalizeConfigPath(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    $path = str_replace('\\', '/', $path);

    $path = preg_replace(
        '#/+#',
        '/',
        $path
    ) ?? $path;

    if (
        preg_match(
            '/^([a-zA-Z]):(\/.*)?$/',
            $path,
            $match
        )
    ) {
        $drive = strtoupper($match[1]);
        $rest = $match[2] ?? '';
        $path = $drive . ':' . $rest;
    }

    if (preg_match('/^[A-Za-z]:\/$/', $path)) {
        return $path;
    }

    if ($path === '/') {
        return '/';
    }

    return rtrim($path, '/');
}

function normalizeConfig(
    array $data,
    array $default,
    array $pathDefaults
): array {
    $globalMode = normalizeMode(
        (string)($data['mode'] ?? $default['mode'])
    );

    $globalTimes = normalizeTimes(
        $data['times'] ?? $default['times'],
        $default['times']
    );

    $globalDailyTime = normalizeOptionalTime(
        (string)($data['daily_once_time'] ?? $default['daily_once_time'])
    );

    $globalEveryNDays = clampInt(
        $data['every_n_days'] ?? $default['every_n_days'],
        1,
        365
    );

    $globalStartDate = normalizeDate(
        (string)($data['start_date'] ?? $default['start_date'])
    );

    $globalMonthDay = clampInt(
        $data['month_day'] ?? $default['month_day'],
        1,
        31
    );

    $migrationDefaults = [
        'mode' => $globalMode ?: $pathDefaults['mode'],
        'daily_once_time' => $globalDailyTime,
        'times' => $globalTimes,
        'every_n_days' => $globalEveryNDays,
        'start_date' => $globalStartDate,
        'month_day' => $globalMonthDay,
    ];

    $scanInput = $data['paths']['scan'] ?? [];

    if (!is_array($scanInput)) {
        $scanInput = [];
    }

    $normalizedScan = [];
    $usedIds = [];

    foreach ($scanInput as $item) {
        if (!is_array($item)) {
            continue;
        }

        $id = normalizePathId(
            (string)($item['id'] ?? '')
        );

        if (
            $id === '' ||
            isset($usedIds[$id])
        ) {
            $id = generateUniquePathId($usedIds);
        }

        $usedIds[$id] = true;

        $path = normalizeConfiguredPath(
            (string)($item['path'] ?? '')
        );


$label = trim(
    (string)($item['label'] ?? '')
);

if (mb_strlen($label) > 60) {
    $label = mb_substr($label, 0, 60);
}

        $mode = normalizeMode(
            (string)($item['mode'] ?? $migrationDefaults['mode'])
        );

        $times = normalizeTimes(
            $item['times'] ?? $migrationDefaults['times'],
            $migrationDefaults['times']
        );

        $dailyOnceTime = normalizeOptionalTime(
            (string)(
                $item['daily_once_time']
                ?? $migrationDefaults['daily_once_time']
            )
        );

        $everyNDays = clampInt(
            $item['every_n_days']
            ?? $migrationDefaults['every_n_days'],
            1,
            365
        );

        $startDate = normalizeDate(
            (string)(
                $item['start_date']
                ?? $migrationDefaults['start_date']
            )
        );

        $monthDay = clampInt(
            $item['month_day']
            ?? $migrationDefaults['month_day'],
            1,
            31
        );


        $normalizedScan[] = [
            'id' => $id,
            'path' => $path,
            'recursive' => (bool)($item['recursive'] ?? true),
			'label' => $label,
            'mode' => $mode,
            'daily_once_time' => $dailyOnceTime,
            'times' => $times,
            'every_n_days' => $everyNDays,
            'start_date' => $startDate,
            'month_day' => $monthDay,
        ];
    }

    $filters = is_array($data['file_filters'] ?? null)
        ? $data['file_filters']
        : [];

    $systemPaths = is_array($data['system_paths'] ?? null)
        ? $data['system_paths']
        : [];

    $backupDir = normalizeConfigPath(
        (string)(
            $systemPaths['backup_dir']
            ?? $default['system_paths']['backup_dir']
        )
    );

    if ($backupDir === '') {
        $backupDir = $default['system_paths']['backup_dir'];
    }

    $theme = (string)($data['theme'] ?? 'paper');

    if (!in_array($theme, ['paper', 'clean'], true)) {
        $theme = 'paper';
    }

    $backupScope = (string)(
        $data['backup_scope']
        ?? 'changed_only'
    );

    if (!in_array($backupScope, ['changed_only', 'full'], true)) {
        $backupScope = 'changed_only';
    }

    $backupOutputMode = (string)(
        $data['backup_output_mode']
        ?? 'normal'
    );

    if (!in_array(
        $backupOutputMode,
        ['normal', 'light', 'heavy'],
        true
    )) {
        $backupOutputMode = 'normal';
    }

    $includeExtensions = trim(
        (string)(
            $filters['include_extensions']
            ?? $filters['include']
            ?? '*'
        )
    );

    // Puste pole oraz "*" oznaczają wszystkie pliki.
    if ($includeExtensions === '') {
        $includeExtensions = '*';
    }

    $excludeExtensions = trim(
        (string)(
            $filters['exclude_extensions']
            ?? 'ini'
        )
    );

    $excludeDirectories = $filters['exclude_directories'] ?? [];
    if (is_string($excludeDirectories)) {
        $excludeDirectories = preg_split('/\r\n|\r|\n/', $excludeDirectories) ?: [];
    }
    if (!is_array($excludeDirectories)) $excludeDirectories = [];
    $excludeDirectories = array_values(array_unique(array_filter(array_map(
        static fn($directory) => is_string($directory)
            ? normalizeConfigPath($directory)
            : '',
        $excludeDirectories
    ), static fn($directory) => $directory !== '')));

    return [
        'enabled' => (bool)($data['enabled'] ?? true),
        'mode' => $globalMode,
        'times' => $globalTimes,
        'daily_once_time' => $globalDailyTime,
        'every_n_days' => $globalEveryNDays,
        'start_date' => $globalStartDate,
        'month_day' => $globalMonthDay,
        'theme' => $theme,
        'backup_scope' => $backupScope,
        'backup_output_mode' => $backupOutputMode,

        'file_filters' => [
            // "include" zostaje dla zgodności z obecnym App.vue.
            'include' => $includeExtensions,
            'include_extensions' => $includeExtensions,
            'exclude_extensions' => $excludeExtensions,
            'exclude_directories' => $excludeDirectories,
        ],

        'system_paths' => [
            'backup_dir' => $backupDir,
            'php_dir' => 'api/backup',
        ],

        'paths' => [
            'scan' => $normalizedScan,
        ],
    ];
}

function normalizeMode(string $mode): string
{
    return in_array(
        $mode,
        [
            'daily_once',
            'daily_times',
            'every_n_days',
            'monthly',
        ],
        true
    )
        ? $mode
        : 'daily_once';
}

function normalizeTimes(mixed $times, array $fallback): array
{
    if (!is_array($times)) {
        $times = $fallback;
    }

    $result = [];

    foreach ($times as $time) {
        $normalized = normalizeOptionalTime(
            (string)$time
        );

        if (
            $normalized !== '' &&
            !in_array($normalized, $result, true)
        ) {
            $result[] = $normalized;
        }
    }

    if ($result === []) {
        $result = $fallback;
    }

    sort($result);

    return array_values($result);
}

function normalizeOptionalTime(string $time): string
{
    $time = trim($time);

    if ($time === '') {
        return '';
    }

    if (
        !preg_match(
            '/^(\d{2}):(\d{2})$/',
            $time,
            $match
        )
    ) {
        return '';
    }

    $hour = (int)$match[1];
    $minute = (int)$match[2];

    if (
        $hour > 23 ||
        $minute > 59
    ) {
        return '';
    }

    return sprintf(
        '%02d:%02d',
        $hour,
        $minute
    );
}

function normalizeDate(string $date): string
{
    $date = trim($date);

    $parsed = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $date
    );

    if (
        $parsed &&
        $parsed->format('Y-m-d') === $date
    ) {
        return $date;
    }

    return date('Y-m-d');
}

function clampInt(
    mixed $value,
    int $min,
    int $max
): int {
    return max(
        $min,
        min(
            $max,
            (int)$value
        )
    );
}

function normalizeConfiguredPath(string $path): string
{
    return normalizeConfigPath($path);
}

function normalizePathId(string $id): string
{
    $id = trim($id);

    return preg_match(
        '/^path_[a-zA-Z0-9_-]{6,64}$/',
        $id
    )
        ? $id
        : '';
}

function generateUniquePathId(array $usedIds): string
{
    do {
        $id = 'path_' . bin2hex(
            random_bytes(6)
        );
    } while (isset($usedIds[$id]));

    return $id;
}

function cleanupHistory(
    array $data,
    string $historyPath
): never {
    $enabled = (bool)(
        $data['enabled']
        ?? false
    );

    $value = max(
        0,
        (int)(
            $data['value']
            ?? 0
        )
    );

    $unit = (string)(
        $data['unit']
        ?? 'days'
    );

    $history = readJson(
        $historyPath,
        []
    );

    $history = is_array($history)
        ? $history
        : [];

    if (!$enabled) {
        respond([
            'status' => 'saved',
            'message' => 'Czyszczenie historii jest wyłączone.',
            'history' => $history,
        ]);
    }

    if (
        !in_array(
            $unit,
            ['days', 'months'],
            true
        )
    ) {
        throw new RuntimeException(
            'Nieprawidłowa jednostka czasu.'
        );
    }

    $limit = $value === 0
        ? strtotime(date('Y-m-d') . ' +1 day')
        : strtotime('-' . $value . ' ' . $unit);

    $history = array_values(
        array_filter(
            $history,
            static function ($item) use (
                $limit,
                $value
            ): bool {
                if (!is_array($item)) {
                    return false;
                }

                $timestamp = strtotime(
                    (string)(
                        $item['date']
                        ?? ''
                    )
                );

                if ($timestamp === false) {
                    return true;
                }

                if ($value === 0) {
                    return
                        $timestamp < $limit &&
                        date(
                            'Y-m-d',
                            $timestamp
                        ) !== date('Y-m-d');
                }

                return $timestamp >= $limit;
            }
        )
    );

    writeJsonAtomic(
        $historyPath,
        $history
    );

    respond([
        'status' => 'saved',
        'message' => 'Historia została wyczyszczona.',
        'history' => $history,
    ]);
}

function uploadBackground(): never
{
    if (
        !isset($_FILES['image']) ||
        $_FILES['image']['error'] !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException(
            'Nie udało się przesłać obrazu.'
        );
    }

    $tmp = $_FILES['image']['tmp_name'];

    if (
        mime_content_type($tmp)
        !== 'image/jpeg'
    ) {
        throw new RuntimeException(
            'Dozwolony jest obecnie tylko JPG.'
        );
    }

    $target =
        __DIR__
        . '/fast-backup-background.jpg';

    if (
        !move_uploaded_file(
            $tmp,
            $target
        )
    ) {
        throw new RuntimeException(
            'Nie udało się zapisać tła.'
        );
    }

    respond([
        'status' => 'saved',
        'message' => 'Tło zostało zmienione.',
        'url' =>
            'api/backup/fast-backup-background.jpg?t='
            . time(),
    ]);
}

function readJson(
    string $path,
    mixed $default
): mixed {
    if (!is_file($path)) {
        return $default;
    }

    $content = file_get_contents($path);

    if (
        $content === false ||
        trim($content) === ''
    ) {
        return $default;
    }

    $decoded = json_decode(
        $content,
        true
    );

    return is_array($decoded)
        ? $decoded
        : $default;
}

function writeJsonAtomic(
    string $path,
    mixed $data
): void {
    $dir = dirname($path);

    if (
        !is_dir($dir) &&
        !mkdir($dir, 0775, true) &&
        !is_dir($dir)
    ) {
        throw new RuntimeException(
            'Nie można utworzyć katalogu: '
            . $dir
        );
    }

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException(
            'Nie można zakodować JSON.'
        );
    }

    $tmp =
        $path
        . '.tmp-'
        . bin2hex(
            random_bytes(4)
        );

    if (
        file_put_contents(
            $tmp,
            $json,
            LOCK_EX
        ) === false
    ) {
        @unlink($tmp);

        throw new RuntimeException(
            'Nie można zapisać pliku: '
            . $path
        );
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);

        throw new RuntimeException(
            'Nie można podmienić pliku: '
            . $path
        );
    }
}

function respond(
    array $payload,
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}