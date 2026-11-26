<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use TxtGrep\Paths;
use TxtGrep\Reader;

$fileParam = $_REQUEST['file'] ?? '';
$inodeParam = $_REQUEST['inode'] ?? '';
$idParam = $_REQUEST['id'] ?? '';
$logical = Reader::resolve(
    is_string($fileParam) ? $fileParam : '',
    is_string($inodeParam) ? $inodeParam : '',
    is_string($idParam) ? $idParam : ''
);
if ($logical === null) {
    http_response_code(404);
    exit('file not found');
}

$content = Reader::content($logical);
$title = basename($logical);
$chapters = Reader::chapterRows($logical);
$paths = array_column($chapters, 'path');
$index = array_search($logical, $paths, true);
$previous = $index !== false && array_key_exists($index - 1, $chapters) ? $chapters[$index - 1] : null;
$next = $index !== false && array_key_exists($index + 1, $chapters) ? $chapters[$index + 1] : null;
$first = array_shift($content);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?=htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></title>
    <link rel="stylesheet" href="css/reader.css?<?=filemtime(__DIR__ . '/css/reader.css') ?: 1 ?>">
    <script src="js/reader.js?<?=filemtime(__DIR__ . '/js/reader.js') ?: 1 ?>" defer></script>
</head>
<body>
    <main class="reader">
        <?php if ($first !== null): ?>
            <h1 class="reader-title"><?=htmlspecialchars($first, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></h1>
        <?php endif; ?>
        <?php foreach ($content as $line): ?>
            <p class="reader-paragraph"><?=htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></p>
        <?php endforeach; ?>
        <?php if ($previous !== null || $next !== null): ?>
            <nav class="reader-nav" aria-label="章節導覽">
                <?php if ($previous !== null): ?>
                    <a class="reader-nav-link" rel="prev"
                       aria-label="<?=htmlspecialchars('上一章：' . Paths::label($previous['path'], false), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"
                       title="<?=htmlspecialchars(Paths::label($previous['path'], false), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"
                       href="<?=htmlspecialchars(Reader::url($previous['path'], $previous['id']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
                        <span class="reader-nav-label" aria-hidden="true">↑</span>
                        <span class="reader-nav-title"><?=htmlspecialchars(Paths::label($previous['path'], false), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></span>
                    </a>
                <?php endif; ?>
                <?php if ($next !== null): ?>
                    <a class="reader-nav-link reader-nav-next" rel="next"
                       aria-label="<?=htmlspecialchars('下一章：' . Paths::label($next['path'], false), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"
                       title="<?=htmlspecialchars(Paths::label($next['path'], false), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"
                       href="<?=htmlspecialchars(Reader::url($next['path'], $next['id']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
                        <span class="reader-nav-label" aria-hidden="true">↓</span>
                        <span class="reader-nav-title"><?=htmlspecialchars(Paths::label($next['path'], false), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></span>
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
</body>
</html>
