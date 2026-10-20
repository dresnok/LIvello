<?php

declare(strict_types=1);

//ini_set('memory_limit', '512M');
// fast-backup.php



class BackupCancelledException extends RuntimeException
{
}

$backupCancelPath = __DIR__ . '/backup.cancel';

function checkBackupCancelled(): void
{
    global $backupCancelPath;

    if (!is_file($backupCancelPath)) {
        return;
    }

    @unlink($backupCancelPath);

    throw new BackupCancelledException(
        'Backup został anulowany przez użytkownika.'
    );
}
header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Europe/Warsaw');

class SimpleZip
{
    private $handle;
    private array $centralDirectory = [];
    private int $offset = 0;
    private string $filePath;
    private bool $aborted = false;

public function __construct(string $filePath)
{
    $this->filePath = $filePath;

    ensureDir(dirname($filePath));

    $this->handle = fopen($filePath, 'wb');

    if (!$this->handle) {
        throw new RuntimeException(
            'Nie można utworzyć pliku ZIP: ' . $filePath
        );
    }
}

public function abort(): void
{
    $this->aborted = true;

    if (is_resource($this->handle)) {
        fclose($this->handle);
    }

    $this->handle = null;

    if (is_file($this->filePath)) {
        @unlink($this->filePath);
    }
}


public function addFile(string $sourcePath, string $zipPath): void
{
    if ($this->aborted || !is_resource($this->handle)) {
        return;
    }

    if (!is_file($sourcePath) || !is_readable($sourcePath)) {
        return;
    }

    $zipPath = str_replace('\\', '/', ltrim($zipPath, '/'));

    $size = filesize($sourcePath);

    if ($size === false) {
        return;
    }

    // Obecny SimpleZip korzysta z klasycznego ZIP32.
    if ($size > 0xFFFFFFFF) {
        throw new RuntimeException(
            'Plik jest zbyt duży dla obecnego formatu ZIP: ' . $sourcePath
        );
    }

    /*
     * CRC obliczamy bez wczytywania całego pliku do pamięci.
     * hash_file() przetwarza plik strumieniowo.
     */
    $crcHex = hash_file('crc32b', $sourcePath);

    if ($crcHex === false) {
        throw new RuntimeException(
            'Nie można obliczyć CRC pliku: ' . $sourcePath
        );
    }

    $crc = (int) hexdec($crcHex);

    $nameLength = strlen($zipPath);
    $localHeaderOffset = $this->offset;

    $localHeader =
        pack('V', 0x04034b50) .
        pack('v', 20) .
        pack('v', 0) .
        pack('v', 0) .
        pack('v', 0) .
        pack('v', 0) .
        pack('V', $crc) .
        pack('V', $size) .
        pack('V', $size) .
        pack('v', $nameLength) .
        pack('v', 0) .
        $zipPath;

    fwrite($this->handle, $localHeader);

    $source = fopen($sourcePath, 'rb');

    if (!$source) {
        throw new RuntimeException(
            'Nie można otworzyć pliku do backupu: ' . $sourcePath
        );
    }

    try {
        while (!feof($source)) {
			checkBackupCancelled();
			
            // Tylko 1 MB w pamięci jednocześnie
            $chunk = fread($source, 1024 * 1024);

            if ($chunk === false) {
                throw new RuntimeException(
                    'Błąd odczytu pliku: ' . $sourcePath
                );
            }

            if ($chunk === '') {
                break;
            }

            $length = strlen($chunk);
            $written = 0;

            while ($written < $length) {
                $result = fwrite(
                    $this->handle,
                    substr($chunk, $written)
                );

                if ($result === false || $result === 0) {
                    throw new RuntimeException(
                        'Błąd zapisu pliku do ZIP: ' . $sourcePath
                    );
                }

                $written += $result;
            }
        }
    } finally {
        fclose($source);
    }

    $this->offset += strlen($localHeader) + $size;

    $this->centralDirectory[] = [
        'name' => $zipPath,
        'crc' => $crc,
        'size' => $size,
        'offset' => $localHeaderOffset,
    ];
}

    public function addString(string $zipPath, string $data): void
    {
        if ($this->aborted || !is_resource($this->handle)) {
            return;
        }

        $zipPath = str_replace('\\', '/', ltrim($zipPath, '/'));

        $crc = crc32($data);
        $size = strlen($data);
        $nameLength = strlen($zipPath);

        $localHeaderOffset = $this->offset;

        $localHeader =
            pack('V', 0x04034b50) .
            pack('v', 20) .
            pack('v', 0) .
            pack('v', 0) .
            pack('v', 0) .
            pack('v', 0) .
            pack('V', $crc) .
            pack('V', $size) .
            pack('V', $size) .
            pack('v', $nameLength) .
            pack('v', 0) .
            $zipPath;

        fwrite($this->handle, $localHeader);
        fwrite($this->handle, $data);

        $this->offset += strlen($localHeader) + $size;

        $this->centralDirectory[] = [
            'name' => $zipPath,
            'crc' => $crc,
            'size' => $size,
            'offset' => $localHeaderOffset,
        ];
    }

    public function close(): void
    {
        if ($this->aborted || !is_resource($this->handle)) {
            return;
        }

        $centralDirectoryStart = $this->offset;
        $centralDirectorySize = 0;

        foreach ($this->centralDirectory as $file) {
            $name = $file['name'];
            $nameLength = strlen($name);

            $centralHeader =
                pack('V', 0x02014b50) .
                pack('v', 20) .
                pack('v', 20) .
                pack('v', 0) .
                pack('v', 0) .
                pack('v', 0) .
                pack('v', 0) .
                pack('V', $file['crc']) .
                pack('V', $file['size']) .
                pack('V', $file['size']) .
                pack('v', $nameLength) .
                pack('v', 0) .
                pack('v', 0) .
                pack('v', 0) .
                pack('v', 0) .
                pack('V', 32) .
                pack('V', $file['offset']) .
                $name;

            fwrite($this->handle, $centralHeader);

            $centralDirectorySize += strlen($centralHeader);
            $this->offset += strlen($centralHeader);
        }

        $fileCount = count($this->centralDirectory);

        $endRecord =
            pack('V', 0x06054b50) .
            pack('v', 0) .
            pack('v', 0) .
            pack('v', $fileCount) .
            pack('v', $fileCount) .
            pack('V', $centralDirectorySize) .
            pack('V', $centralDirectoryStart) .
            pack('v', 0);

        fwrite($this->handle, $endRecord);
        fclose($this->handle);
    }
}



$backupRoot = __DIR__;
$projectRoot = dirname(__DIR__, 2);
$projectRoot = rtrim(str_replace('\\', '/', realpath($projectRoot) ?: $projectRoot), '/');
$configPath = $backupRoot . '/fast-backup-config.json';
$historyJsonPath = __DIR__ . '/backup_history.json'; // api/data/backup_history.json
$historyHtmlPath = dirname(__DIR__) . '/backup_history.html';

$response = [
    'status' => 'pending',
    'date' => date('Y-m-d H:i:s'),
    'force' => isset($_GET['force']) && $_GET['force'] === '1',
    'results' => [],
    'created_count' => 0,
    'skipped_count' => 0,
    'not_due_count' => 0,
    'error_count' => 0,
    'checked_files' => 0,
    'new_files' => 0,
    'changed_files' => 0,
    'deleted_files' => 0,
    'zips' => [],
    'message' => '',
];

$forceBackup = $response['force'];
$forceScope = (string)($_GET['scope'] ?? '');
$forceRecursive = (string)($_GET['recursive'] ?? 'default');

if (!in_array($forceScope, ['changed_only', 'full'], true)) $forceScope = '';
if (!in_array($forceRecursive, ['default', 'recursive', 'flat'], true)) $forceRecursive = 'default';

try {
    $config = readJson($configPath, []);
    if (!is_array($config)) {
        throw new RuntimeException('Nieprawidłowy plik konfiguracji.');
    }

    $systemPaths = is_array($config['system_paths'] ?? null) ? $config['system_paths'] : [];
$backupDirConfig = trim((string)($systemPaths['backup_dir'] ?? 'backup'), '/');
$backupSystemDir = resolveProjectPath($projectRoot, $backupDirConfig ?: 'backup');

    $zipDir = $backupSystemDir . '/zips';
    $manifestDir = $backupSystemDir . '/manifests';
    $lockDir = $backupSystemDir . '/locks';
    // HTML pozostaje w api/, historia JSON w api/data/.
    $historyHtmlPath = dirname(__DIR__) . '/backup_history.html';

    // Filtry są przypisane osobno do każdej ścieżki w paths.scan[].
    $response['exclusions'] = [
        'included_extensions' => '',
        'extensions' => '',
        'directories' => [],
    ];

    if (($config['enabled'] ?? true) !== true) {
        $response['status'] = 'disabled';
        $response['message'] = 'Backup jest wyłączony w konfiguracji.';
        outputAndExit($response, $historyJsonPath, $historyHtmlPath);
    }

    $scanPaths = $config['paths']['scan'] ?? [];

    if (!is_array($scanPaths) || $scanPaths === []) {
        $response['status'] = 'no_paths';
        $response['message'] = 'Nie podano żadnej ścieżki skanowania.';
        outputAndExit($response, $historyJsonPath, $historyHtmlPath);
    }

    /*
     * Najpierw sprawdzamy wszystkie ścieżki źródłowe.
     * Jeśli choć jedna jest błędna, nie tworzymy jeszcze
     * katalogów backupu ani żadnych plików pomocniczych.
     */
foreach ($scanPaths as $scanItem) {
    if (!is_array($scanItem)) {
        continue;
    }

    if (($scanItem['enabled'] ?? true) !== true) {
        continue;
    }

        $configuredPath = trim(
            str_replace(
                '\\',
                '/',
                (string)($scanItem['path'] ?? '')
            )
        );

        if ($configuredPath === '') continue;

        $absolutePath = resolveProjectPath(
            $projectRoot,
            $configuredPath
        );

        if (!is_dir($absolutePath) || !is_readable($absolutePath)) {
            $response['status'] = 'missing_files';
            $response['message'] =
                'Nie znaleziono katalogu skanowania: '
                . $configuredPath;

            outputAndExit($response, $historyJsonPath, $historyHtmlPath, 400);
        }
    }

    $backupScope = (string)($config['backup_scope'] ?? 'changed_only');
    if (!in_array($backupScope, ['changed_only', 'full'], true)) $backupScope = 'changed_only';
    if ($forceBackup && $forceScope !== '') $backupScope = $forceScope;

    $outputMode = (string)($config['backup_output_mode'] ?? 'normal');
    if (!in_array($outputMode, ['normal', 'light', 'heavy'], true)) $outputMode = 'normal';
    if ($outputMode === 'heavy') $backupScope = 'full';

    foreach ($scanPaths as $scanItem) {
        if (!is_array($scanItem)) continue;
        if (trim((string)($scanItem['path'] ?? '')) === '') continue;

        $pathResult = processScanPath(
            $scanItem,
            $config,
            $projectRoot,
            $backupSystemDir,
            $zipDir,
            $manifestDir,
            $lockDir,
            $backupScope,
            $outputMode,
            $forceBackup,
            $forceRecursive
        );

        $response['results'][] = $pathResult;
        $response['checked_files'] += (int)($pathResult['checked_files'] ?? 0);
        $response['new_files'] += (int)($pathResult['new_files'] ?? 0);
        $response['changed_files'] += (int)($pathResult['changed_files'] ?? 0);
        $response['deleted_files'] += (int)($pathResult['deleted_files'] ?? 0);

        if (!empty($pathResult['zip'])) $response['zips'][] = $pathResult['zip'];
        if (in_array($pathResult['status'], ['created', 'logged'], true)) $response['created_count']++;
        elseif ($pathResult['status'] === 'skipped') $response['skipped_count']++;
        elseif ($pathResult['status'] === 'not_due') $response['not_due_count']++;
        elseif (in_array($pathResult['status'], ['error', 'missing_path'], true)) $response['error_count']++;
    }

    if (count($response['results']) === 1) {
        $onlyPath = $response['results'][0];
        $response['mode'] = $onlyPath['mode'] ?? null;
        $response['slot'] = $onlyPath['slot'] ?? null;
        $response['zip'] = $onlyPath['zip'] ?? null;
    }

    if ($response['results'] === []) {
        $response['status'] = 'config_error';
        $response['message'] = 'Nie podano żadnej niepustej ścieżki skanowania.';
    } elseif ($response['error_count'] > 0) {
        $response['status'] = $response['created_count'] > 0 ? 'partial' : 'missing_files';
        $response['message'] = 'Część ścieżek zakończyła się błędem.';
    } elseif ($response['created_count'] > 0) {
        $response['status'] = 'created';
        $response['message'] = 'Wykonano backup dla ' . $response['created_count'] . ' ścieżek.';
    } elseif ($response['skipped_count'] > 0) {
        $response['status'] = 'skipped';
        $response['message'] = 'Backup dla aktywnych slotów został już wykonany.';
    } else {
        $response['status'] = 'not_due';
        $response['message'] = 'Żadna ścieżka nie ma teraz aktywnego slotu.';
    }

    outputAndExit($response, $historyJsonPath, $historyHtmlPath);
} catch (BackupCancelledException $e) {

    $response['status'] = 'cancelled';
    $response['message'] =
        'Backup został anulowany przez użytkownika.';

    outputAndExit(
        $response,
        $historyJsonPath,
        $historyHtmlPath
    );

} catch (Throwable $e) {

    $response['status'] = 'error';
    $response['message'] = $e->getMessage();

    outputAndExit(
        $response,
        $historyJsonPath,
        $historyHtmlPath,
        500
    );
}

function processScanPath(
    array $item,
    array $config,
    string $projectRoot,
    string $backupSystemDir,
    string $zipDir,
    string $manifestDir,
    string $lockDir,
    string $backupScope,
    string $outputMode,
    bool $forceBackup,
    string $forceRecursive
): array {
    $id = (string)($item['id'] ?? '');
	
    if (!preg_match('/^path_[a-zA-Z0-9_-]{6,64}$/', $id)) {
        return pathResult($item, 'error', 'Ścieżka nie ma poprawnego trwałego identyfikatora id.');
    }

$configuredPath = trim(
    str_replace(
        '\\',
        '/',
        (string)($item['path'] ?? '')
    )
);


$createdDate = str_replace(
    '-',
    '',
    (string)($item['created_date'] ?? date('Y-m-d'))
);

$folderName = safeToken(
    basename(rtrim($configuredPath, '/'))
);

$pathBackupDir =
    $backupSystemDir .
    '/' .
    $createdDate .
    '-' .
    $folderName;

$zipDir = $pathBackupDir . '/zips';
$manifestDir = $pathBackupDir . '/manifest';
$lockDir = $pathBackupDir . '/locks';


$result = pathResult($item, 'pending', '');
$result['history_path'] = $pathBackupDir . '/history.html';

if (($item['enabled'] ?? true) !== true) {
    $result['status'] = 'not_due';
    $result['message'] =
        'Backup dla tej ścieżki jest wyłączony.';
    return $result;
}

$slot = $forceBackup
    ? 'force'
    : resolveBackupSlot($item);

$result['slot'] = $slot;

    if ($slot === null) {
        $result['status'] = 'not_due';
        $result['message'] = 'Ścieżka nie ma teraz aktywnego slotu.';
        return $result;
    }

    $date = date('Y-m-d');
    $lockPath = buildLockPath($lockDir, $date, $id, $slot);

    if (!$forceBackup && is_file($lockPath)) {
        $result['status'] = 'skipped';
        $result['message'] = 'Blokada dla kombinacji data + id ścieżki + slot już istnieje.';
        $result['lock'] = normalizePath($lockPath);
        return $result;
    }

    $absolutePath = resolveProjectPath($projectRoot, $configuredPath);
    if (!is_dir($absolutePath) || !is_readable($absolutePath)) {
        $result['status'] = 'missing_path';
        $result['message'] = 'Katalog skanowania nie istnieje albo nie jest czytelny.';
        $result['resolved_path'] = $absolutePath;
        return $result;
    }

    $recursive = (bool)($item['recursive'] ?? true);
    if ($forceBackup && $forceRecursive === 'recursive') $recursive = true;
    if ($forceBackup && $forceRecursive === 'flat') $recursive = false;

    $manifestPath = $manifestDir . '/manifest.json';
    $manifestHtmlPath = $pathBackupDir . '/manifest.html';
    $oldManifest = $outputMode === 'heavy' ? [] : readJson($manifestPath, []);
    $oldManifest = is_array($oldManifest) ? $oldManifest : [];

    $currentManifest = [];
    $allFiles = [];
    $backupFiles = [];
    $scanBaseName = safeArchiveRoot($configuredPath, $id);
    $filters = is_array($item['file_filters'] ?? null)
        ? $item['file_filters']
        : [];

    // Katalogi wskazane ręcznie przez użytkownika.
    $excludedDirectories = resolveExcludedDirectories(
        $filters['exclude_directories'] ?? [],
        $absolutePath
    );

    // Katalog docelowy backupu jest zawsze pomijany,
    // jeśli znajduje się wewnątrz skanowanej ścieżki.
    $backupDirectory = normalizedDirectoryPath(
        realpath($backupSystemDir) ?: $backupSystemDir
    );

    if ($backupDirectory !== '') {
        $excludedDirectories[] = $backupDirectory;
        $excludedDirectories = array_values(
            array_unique($excludedDirectories)
        );
    }

    foreach (collectFiles($absolutePath, $recursive, $excludedDirectories) as $fullPath) {
		checkBackupCancelled();
		
        $inside = ltrim(str_replace('\\', '/', substr($fullPath, strlen(rtrim($absolutePath, '/\\')))), '/');
        $relative = $scanBaseName . '/' . $inside;

        if (!fileAllowed($relative, $filters)) continue;

        $hash = sha1_file($fullPath);
        if ($hash === false) continue;

        $currentManifest[$relative] = [
            'hash' => $hash,
            'size' => filesize($fullPath),
            'mtime' => filemtime($fullPath),
            'modified_at' => date('Y-m-d H:i:s', (int)filemtime($fullPath)),
        ];
        $allFiles[$relative] = $fullPath;
        $result['checked_files']++;

        if (!isset($oldManifest[$relative])) {
            $result['new_files']++;
            $backupFiles[] = ['full_path' => $fullPath, 'relative_path' => $relative, 'type' => 'new'];
        } elseif (($oldManifest[$relative]['hash'] ?? '') !== $hash) {
            $result['changed_files']++;
            $backupFiles[] = ['full_path' => $fullPath, 'relative_path' => $relative, 'type' => 'changed'];
        }
    }


    foreach ($oldManifest as $relative => $_data) {
        if (!isset($currentManifest[$relative])) $result['deleted_files']++;
    }

    if ($backupScope === 'full') {
        $backupFiles = [];
        foreach ($allFiles as $relative => $fullPath) {
            $backupFiles[] = ['full_path' => $fullPath, 'relative_path' => $relative, 'type' => 'full'];
        }
        $result['new_files'] = count($backupFiles);
        $result['changed_files'] = 0;
    }

    $hasContent = $backupFiles !== [];
    $hasManifestChange = $hasContent || $result['deleted_files'] > 0;

    // Heavy zawsze tworzy osobny pełny ZIP, nawet dla pustego katalogu.
    if ($outputMode === 'heavy') $hasContent = true;

    if ($hasContent && $outputMode !== 'light') {
        $slotSafe = safeToken($slot);
        $zipName = 'backup_' . $date . '_path-' . $id . '_slot-' . $slotSafe . '_run-' . date('H-i-s') . '.zip';
        $zipPath = $zipDir . '/' . $zipName;
$zip = new SimpleZip($zipPath);

try {

    foreach ($backupFiles as $file) {

        checkBackupCancelled();

        $zip->addFile(
            $file['full_path'],
            $file['relative_path']
        );
    }

    checkBackupCancelled();

    $zip->addString(
        'backup_report.json',
        json_encode([
            'date' => date('Y-m-d H:i:s'),
            'path_id' => $id,
            'configured_path' => $configuredPath,
            'recursive' => $recursive,
            'mode' => $item['mode'] ?? null,
            'slot' => $slot,
            'force' => $forceBackup,
            'backup_scope' => $backupScope,
            'backup_output_mode' => $outputMode,
            'checked_files' => $result['checked_files'],
            'new_files' => $result['new_files'],
            'changed_files' => $result['changed_files'],
            'deleted_files' => $result['deleted_files'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $zip->close();

} catch (BackupCancelledException $e) {

    $zip->abort();

    throw $e;
}

        $result['zip'] = relativeToProject($projectRoot, $zipPath);
    }

    if ($outputMode !== 'heavy' && $hasManifestChange) {
		
		checkBackupCancelled();
		
        writeJson($manifestPath, $currentManifest);
        writeManifestHtml($manifestHtmlPath, $currentManifest, $configuredPath);
    }

    if ($outputMode === 'light') {
        $result['status'] = 'logged';
        $result['message'] = 'Tryb light: sprawdzono ścieżkę bez tworzenia ZIP.';
    } elseif ($hasContent) {
        $result['status'] = 'created';
        $result['message'] = 'Utworzono osobną paczkę ZIP dla ścieżki.';
    } else {
        $result['status'] = 'no_changes';
        $result['message'] = 'Brak nowych lub zmienionych plików.';
    }

    if (!$forceBackup) {
		checkBackupCancelled();
		
        writeJson($lockPath, [
            'date' => date('Y-m-d H:i:s'),
            'path_id' => $id,
            'path' => $configuredPath,
            'slot' => $slot,
            'status' => $result['status'],
            'zip' => $result['zip'],
        ]);
        $result['lock'] = relativeToProject($projectRoot, $lockPath);
    }

    $result['manifest'] = relativeToProject($projectRoot, $manifestPath);
    $result['manifest_html'] = relativeToProject($projectRoot, $manifestHtmlPath);
    return $result;
}

function pathResult(array $item, string $status, string $message): array
{
    return [
        'id' => (string)($item['id'] ?? ''),
        'path' => (string)($item['path'] ?? ''),
        'mode' => (string)($item['mode'] ?? ''),
        'slot' => null,
        'status' => $status,
        'message' => $message,
        'checked_files' => 0,
        'new_files' => 0,
        'changed_files' => 0,
        'deleted_files' => 0,
        'zip' => null,
        'manifest' => null,
        'lock' => null,
    ];
}

function resolveBackupSlot(array $item): ?string
{
    $mode = (string)($item['mode'] ?? 'daily_once');
    $now = date('H:i');

    if ($mode === 'daily_once') {
        $time = normalizeTime((string)($item['daily_once_time'] ?? ''));
        if ($time === null) return null;
        return $now >= $time ? 'daily-' . $time : null;
    }

    if ($mode === 'daily_times') {
        $times = is_array($item['times'] ?? null) ? $item['times'] : [];
        $active = null;
        foreach ($times as $raw) {
            $time = normalizeTime((string)$raw);
            if ($time !== null && $now >= $time) $active = $time;
        }
        return $active === null ? null : 'daily-' . $active;
    }

    if ($mode === 'every_n_days') {
        $every = max(1, (int)($item['every_n_days'] ?? 2));
        $diff = daysBetween((string)($item['start_date'] ?? date('Y-m-d')), date('Y-m-d'));
        if ($diff < 0 || $diff % $every !== 0) return null;
        $time = normalizeTime((string)($item['daily_once_time'] ?? ''));
        if ($time === null || $now < $time) return null;
        return 'every-' . $every . '-days-' . $time;
    }

    if ($mode === 'monthly') {
        $day = max(1, min(31, (int)($item['month_day'] ?? 1)));
        if ((int)date('j') !== $day) return null;
        $time = normalizeTime((string)($item['daily_once_time'] ?? ''));
        if ($time === null || $now < $time) return null;
        return 'monthly-day-' . $day . '-' . $time;
    }

    return null;
}

/**
 * Ścieżki bezwzględne są sprawdzane bezpośrednio, a względne
 * są liczone od katalogu skanowanego. Katalog docelowy backupu jest pomijany automatycznie.
 */
function resolveExcludedDirectories(mixed $configured, string $scanRoot): array
{
    if (is_string($configured)) {
        $configured = preg_split('/\r\n|\r|\n/', $configured) ?: [];
    }
    if (!is_array($configured)) return [];

    $result = [];
    foreach ($configured as $directory) {
        if (!is_string($directory)) continue;
        $directory = trim(str_replace('\\', '/', $directory));
        if ($directory === '') continue;

        if (!preg_match('/^[A-Za-z]:\//', $directory) && !str_starts_with($directory, '/')) {
            $directory = rtrim(normalizePath($scanRoot), '/') . '/' . ltrim($directory, '/');
        }
        $directory = normalizedDirectoryPath($directory);
        if ($directory === '') continue;

        // Gdy katalog istnieje, uwzględniamy jego faktyczną ścieżkę.
        $directory = normalizedDirectoryPath(realpath($directory) ?: $directory);
        $result[$directory] = $directory;
    }
    return array_values($result);
}

function normalizedDirectoryPath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    return $path === '/' ? '/' : rtrim($path, '/');
}

function directoryIsExcluded(string $path, array $excludedDirectories): bool
{
    $path = normalizedDirectoryPath(realpath($path) ?: $path);
    foreach ($excludedDirectories as $excluded) {
        // Na Windows porównujemy ścieżki bez rozróżniania wielkości liter.
        $windowsPath = (bool)preg_match('/^[A-Za-z]:\//', $path);
        $current = $windowsPath ? strtolower($path) : $path;
        $blocked = $windowsPath ? strtolower($excluded) : $excluded;
        if ($current === $blocked || str_starts_with($current, rtrim($blocked, '/') . '/')) {
            return true;
        }
    }
    return false;
}

function collectFiles(string $dir, bool $recursive, array $excludedDirectories = []): array
{
    if (directoryIsExcluded($dir, $excludedDirectories)) return [];

    $result = [];
    if ($recursive) {
        $directory = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static function (SplFileInfo $entry) use ($excludedDirectories): bool {
                // Odrzucenie katalogu PRZED wejściem do niego pomija całe poddrzewo.
                return !$entry->isDir() ||
                    !directoryIsExcluded($entry->getPathname(), $excludedDirectories);
            }
        );
        $iterator = new RecursiveIteratorIterator($filter);
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->isReadable()) $result[] = $file->getPathname();
        }
        return $result;
    }

    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = rtrim($dir, '/\\') . '/' . $name;
        if (is_file($path) && is_readable($path)) $result[] = $path;
    }
    return $result;
}

function fileAllowed(string $relativePath, array $filters): bool
{
    $extension = strtolower(
        pathinfo($relativePath, PATHINFO_EXTENSION)
    );

    $included = preg_split(
        '/[\s,;]+/',
        strtolower(
            (string)($filters['include_extensions'] ?? '')
        ),
        -1,
        PREG_SPLIT_NO_EMPTY
    ) ?: [];

    // Puste pole albo "*" = wszystkie pliki.
    $includeAll =
        $included === [] ||
        in_array('*', $included, true);

    // Konkretna lista rozszerzeń.
    // Wtedy wykluczenia rozszerzeń ignorujemy.
    if (!$includeAll) {
        return $extension !== '' &&
            in_array($extension, $included, true);
    }

    $excluded = preg_split(
        '/[\s,;]+/',
        strtolower(
            (string)($filters['exclude_extensions'] ?? '')
        ),
        -1,
        PREG_SPLIT_NO_EMPTY
    ) ?: [];

    // "*" / puste = wszystko poza wykluczonymi.
    return $extension === '' ||
        !in_array($extension, $excluded, true);
}

function buildLockPath(string $lockDir, string $date, string $id, string $slot): string
{
    return $lockDir . '/' . $date . '/' . $id . '/slot-' . safeToken($slot) . '.json';
}

function safeToken(string $value): string
{
    $value = str_replace(':', '-', $value);
    return trim((string)preg_replace('/[^a-zA-Z0-9_-]+/', '-', $value), '-');
}

function safeArchiveRoot(string $configuredPath, string $id): string
{
    $name = basename(rtrim(str_replace('\\', '/', $configuredPath), '/'));
    $name = safeToken($name);
    return $name !== '' ? $name : $id;
}

function daysBetween(string $startDate, string $endDate): int
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
    if (!$start || !$end) return -1;
    return (int)$start->diff($end)->format('%r%a');
}

function normalizeTime(string $time): ?string
{
    $time = trim($time);
    if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $m)) return null;
    $h = (int)$m[1]; $i = (int)$m[2];
    return ($h <= 23 && $i <= 59) ? sprintf('%02d:%02d', $h, $i) : null;
}

function ensureDir(string $dir, string $label = 'katalogu'): void
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nie można utworzyć ' . $label . ': ' . $dir);
    }
}

function resolveProjectPath(string $projectRoot, string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if (preg_match('/^[A-Za-z]:\//', $path) || str_starts_with($path, '/')) return rtrim($path, '/');
    return rtrim($projectRoot, '/') . '/' . trim($path, '/');
}

function relativeToProject(string $projectRoot, string $path): string
{
    $root = rtrim(normalizePath($projectRoot), '/');
    $path = normalizePath($path);
    return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
}

function readJson(string $path, mixed $default): mixed
{
    if (!is_file($path)) return $default;
    $content = file_get_contents($path);
    if ($content === false || trim($content) === '') return $default;
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : $default;
}

function writeJson(string $path, mixed $data): void
{
    ensureDir(dirname($path), 'katalogu dla JSON');
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Nie można zapisać pliku JSON: ' . $path);
    }
}


/** Czytelny podgląd manifestu dla użytkownika. JSON pozostaje źródłem technicznym. */
function writeManifestHtml(string $path, array $manifest, string $sourcePath): void
{
    ensureDir(dirname($path), 'katalogu manifestu HTML');

    $rows = '';
    foreach ($manifest as $file => $meta) {
        if (!is_array($meta)) continue;

        $size = (int)($meta['size'] ?? 0);
        if ($size >= 1024 * 1024) {
            $sizeLabel = number_format($size / (1024 * 1024), 2, ',', ' ') . ' MB';
        } elseif ($size >= 1024) {
            $sizeLabel = number_format($size / 1024, 1, ',', ' ') . ' KB';
        } else {
            $sizeLabel = $size . ' B';
        }

        $rows .= '<tr>'
            . '<td class="file">' . htmlspecialchars((string)$file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
            . '<td class="size">' . htmlspecialchars($sizeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
            . '<td class="date">' . htmlspecialchars((string)($meta['modified_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
            . '</tr>';
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="3" class="empty">Brak plików w manifeście.</td></tr>';
    }

    $html = '<!doctype html>
'
        . '<html lang="pl">
'
        . '<head>
'
        . '<meta charset="utf-8">
'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">
'
        . '<title>Manifest backupu</title>
'
        . '<style>
'
        . 'body{font-family:Arial,sans-serif;padding:30px;background:#181818;color:#eee;}
'
        . 'h2{margin:0 0 8px;}
'
        . '.source{margin:0 0 22px;color:#bbb;overflow-wrap:anywhere;}
'
        . 'table{width:100%;border-collapse:collapse;}
'
        . 'th,td{padding:10px 12px;border-bottom:1px solid #444;text-align:left;vertical-align:top;}
'
        . 'th{background:#252525;position:sticky;top:0;}
'
        . 'tr:hover{background:#222;}
'
        . 'td.file{overflow-wrap:anywhere;word-break:break-word;}
'
        . 'td.size,td.date{white-space:nowrap;}
'
        . '.empty{text-align:center;color:#aaa;}
'
        . '</style>
'
        . '</head>
'
        . '<body>
'
        . '<h2>Manifest backupu</h2>
'
        . '<p class="source">Ścieżka: ' . htmlspecialchars($sourcePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>
'
        . '<table>
'
        . '<thead><tr><th>Plik</th><th>Rozmiar</th><th>Modyfikacja</th></tr></thead>
'
        . '<tbody>' . $rows . '</tbody>
'
        . '</table>
'
        . '</body>
'
        . '</html>';

    if (file_put_contents($path, $html, LOCK_EX) === false) {
        throw new RuntimeException('Nie można zapisać manifestu HTML: ' . $path);
    }
}

/** Historia HTML jest raportem tylko do odczytu dla użytkownika. */
function appendHistoryEntries(string $path, array $entries): void
{
    if ($entries === []) return;

    $htmlPath = $path;

    $rows = '';

    foreach ($entries as $entry) {
        if (!is_array($entry)) continue;

        $rows .= '<tr>'
            . '<td class="date">' . htmlspecialchars((string)($entry['date'] ?? '')) . '</td>'
            . '<td>' . htmlspecialchars((string)($entry['status'] ?? '')) . '</td>'
            . '<td>' . (int)($entry['checked_files'] ?? 0) . '</td>'
            . '<td>' . (int)($entry['new_files'] ?? 0) . '</td>'
            . '<td>' . (int)($entry['changed_files'] ?? 0) . '</td>'
            . '<td>' . (int)($entry['deleted_files'] ?? 0) . '</td>'
            . '<td class="zip">' . htmlspecialchars((string)($entry['zip'] ?? '')) . '</td>'
            . '</tr>';
    }

    if ($rows === '') return;

    ensureDir(dirname($htmlPath), 'katalogu historii HTML');

    if (!is_file($htmlPath)) {
        $html = '<!doctype html>
'
            . '<html lang="pl">
'
            . '<head>
'
            . '<meta charset="utf-8">
'
            . '<title>Historia backupu</title>
'
            . '<style>
'
            . 'body{font-family:Arial,sans-serif;padding:30px;background:#181818;color:#eee;}
'
            . 'h2{margin-bottom:20px;}
'
            . 'table{width:100%;border-collapse:collapse;}
'
            . 'th,td{padding:10px 12px;border-bottom:1px solid #444;text-align:left;vertical-align:top;}
'
            . 'th{background:#252525;}
'
            . 'tr:hover{background:#222;}
'
            . 'td.date{white-space:nowrap;}
'
            . 'td.zip{white-space:normal;overflow-wrap:anywhere;word-break:break-word;}
'
            . '</style>
'
            . '</head>
'
            . '<body>
'
            . '<h2>Historia backupu</h2>
'
            . '<table>
'
            . '<thead><tr>'
            . '<th>Data</th>'
            . '<th>Status</th>'
            . '<th>Sprawdzone</th>'
            . '<th>Nowe</th>'
            . '<th>Zmienione</th>'
            . '<th>Usunięte</th>'
            . '<th>ZIP</th>'
            . '</tr></thead>
'
            . '<tbody>
'
            . $rows
            . '</tbody>
'
            . '</table>
'
            . '</body>
'
            . '</html>';

        if (file_put_contents($htmlPath, $html, LOCK_EX) === false) {
            throw new RuntimeException('Nie można zapisać historii HTML: ' . $htmlPath);
        }

        return;
    }

    $html = file_get_contents($htmlPath);
    if ($html === false) {
        throw new RuntimeException('Nie można odczytać historii HTML: ' . $htmlPath);
    }

    $marker = '</tbody>';
    $pos = strripos($html, $marker);

    if ($pos === false) {
        throw new RuntimeException('Nieprawidłowy format pliku historii HTML: ' . $htmlPath);
    }

    $html = substr($html, 0, $pos) . $rows . substr($html, $pos);

    if (file_put_contents($htmlPath, $html, LOCK_EX) === false) {
        throw new RuntimeException('Nie można zaktualizować historii HTML: ' . $htmlPath);
    }
}

function appendHistoryJson(
    string $path,
    array $entry
): void {
    $history = readJson($path, []);

    if (!is_array($history)) {
        $history = [];
    }

    array_unshift($history, $entry);

    writeJson($path, $history);
}


function outputAndExit(array $response, string $historyJsonPath, string $historyHtmlPath, int $httpCode = 200): never
{
    foreach (($response['results'] ?? []) as $result) {
        if (!is_array($result)) continue;

        $status = (string)($result['status'] ?? '');

        if (!in_array($status, ['created', 'logged', 'error', 'missing_path'], true)) {
            continue;
        }

        $entry = [
            'date' => $response['date'] ?? date('Y-m-d H:i:s'),
            'status' => $status,
            'path_id' => $result['id'] ?? '',
            'path' => $result['path'] ?? '',
            'mode' => $result['mode'] ?? '',
            'slot' => $result['slot'] ?? null,
            'checked_files' => (int)($result['checked_files'] ?? 0),
            'new_files' => (int)($result['new_files'] ?? 0),
            'changed_files' => (int)($result['changed_files'] ?? 0),
            'deleted_files' => (int)($result['deleted_files'] ?? 0),
            'zip' => $result['zip'] ?? null,
            'message' => $result['message'] ?? '',
        ];

        // Techniczna historia JSON dla aplikacji.
        appendHistoryJson(
            $historyJsonPath,
            $entry
        );

        // Wspólna historia HTML wszystkich ścieżek.
        appendHistoryEntries(
            $historyHtmlPath,
            [$entry]
        );

        // Dodatkowa historia HTML tylko dla tej konkretnej ścieżki.
        $localHistoryPath = (string)($result['history_path'] ?? '');

        if ($localHistoryPath !== '') {
            appendHistoryEntries(
                $localHistoryPath,
                [$entry]
            );
        }
    }

    http_response_code($httpCode);

    echo json_encode(
        $response,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function normalizePath(string $path): string
{
    return str_replace('\\', '/', $path);
}
