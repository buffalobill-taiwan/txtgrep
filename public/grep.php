<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use TxtGrep\Config;
use TxtGrep\Paths;
use TxtGrep\Reader;
use TxtGrep\Search;
use TxtGrep\SearchValidationException;

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => '請使用 POST 搜尋。']);
}

$body = file_get_contents('php://input', false, null, 0, 8193);
if ($body === false || strlen($body) > 8192) {
    respond(413, ['error' => '搜尋請求過大。']);
}
$data = json_decode($body);
$source = $data->source ?? '*';
$keyword = $data->keyword ?? null;
if (!is_string($source) || !is_string($keyword) || mb_strlen($keyword, 'UTF-8') > 256) {
    respond(400, ['error' => '請輸入最多 256 字的關鍵字並選擇書目。']);
}
if (Search::splitTerms($keyword) === []) {
    respond(400, ['error' => '請輸入關鍵字。']);
}
$delimiter = chr(1);
if (str_contains($keyword, $delimiter) || str_contains($keyword, "\0")) {
    respond(400, ['error' => '正規表示式包含不支援的控制字元。']);
}

try {
    $sources = Search::normalizeSources($source);
    $found = Search::run($keyword, $sources, true, Config::get()->limit());
} catch (SearchValidationException $error) {
    respond(400, ['error' => $error->getMessage()]);
} catch (PDOException $error) {
    error_log('txtgrep search (DB): ' . $error->getMessage());
    respond(500, ['error' => '搜尋暫時無法使用，請稍後重試。']);
}

$results = [];
foreach ($found['rows'] as $row) {
    $path = (string) $row['file_path'];
    $fileId = is_numeric($row['file_id'] ?? null) ? (int) $row['file_id'] : null;
    $inode = $row['inode'] === null ? '' : (string) $row['inode'];
    $results[] = [
        'id' => null,
        'ref' => $path,
        'url' => Reader::url($path, $fileId, $inode),
        'book' => Paths::book($path),
        'file' => Paths::label($path, $sources === ['*']),
        'text' => $row['content'],
        'file_id' => $fileId,
        'inode' => $inode,
        'line' => (int) $row['line_number'],
        'matches' => Search::matchOffsets($row['content'], $found['terms'], true),
    ];
}

header('X-Search-Limit: ' . Config::get()->limit());
if ($found['truncated']) {
    header('X-Search-Truncated: 1');
}
respond(200, $results);
