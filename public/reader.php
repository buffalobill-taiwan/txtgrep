<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use TxtGrep\Reader;

$fileParam = $_REQUEST['file'] ?? '';
$inodeParam = $_REQUEST['inode'] ?? '';
$logical = Reader::resolve(
    is_string($fileParam) ? $fileParam : '',
    is_string($inodeParam) ? $inodeParam : ''
);
if ($logical === null) {
    http_response_code(404);
    exit('file not found');
}

$content = Reader::content($logical);
$title = basename($logical);
$chapters = Reader::chapters($logical);
$index = array_search($logical, $chapters, true);
$previous = $index !== false && array_key_exists($index - 1, $chapters) ? $chapters[$index - 1] : null;
$next = $index !== false && array_key_exists($index + 1, $chapters) ? $chapters[$index + 1] : null;
$first = array_shift($content);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?=htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></title>
    <link rel="stylesheet" href="css/uikit.min.css" />
    <link rel="stylesheet" href="css/nouislider.css" />
    <link rel="stylesheet" href="css/index.css?<?=filemtime(__DIR__ . '/css/index.css') ?: 1 ?>" />
    <style>
        body { writing-mode: vertical-rl; line-break: normal; font-size: 1.5em; }
        p { text-indent: 2em; padding: 0.5em; }
        h1 { text-indent: 0; text-align: center; padding: 0.5em; }
    </style>
    <script src="js/uikit.min.js"></script>
    <script src="js/uikit-icons.min.js"></script>
    <script src="js/wNumb.min.js"></script>
    <script src="js/nouislider.js"></script>
    <script src="js/rtlwheel.js"></script>
    <script src="js/cash.min.js"></script>
    <script src="js/index.js?<?=filemtime(__DIR__ . '/js/index.js') ?: 1 ?>"></script>
</head>
<body>
    <?php if ($first !== null): ?>
        <h1 class="uk-heading"><?=htmlspecialchars($first, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></h1>
    <?php endif; ?>
    <?php foreach ($content as $line): ?>
        <p class="uk-text"><?=htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></p>
    <?php endforeach; ?>
    <p class="half"> </p>
    <?php if ($previous !== null || $next !== null): ?>
        <div class="uk-margin uk-margin-left uk-flex uk-flex-around">
            <?php if ($previous !== null): ?>
                <div><a href="<?=htmlspecialchars(Reader::url($previous), ENT_QUOTES)?>">上一章</a></div>
            <?php endif; ?>
            <?php if ($next !== null): ?>
                <div><a href="<?=htmlspecialchars(Reader::url($next), ENT_QUOTES)?>">下一章</a></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>
