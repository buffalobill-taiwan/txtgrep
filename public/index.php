<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use TxtGrep\Search;

$books = Search::availableBooks();
$keyword = $_REQUEST['keyword'] ?? '';
$keyword = is_string($keyword) ? $keyword : '';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>全文檢索 | txtgrep</title>
    <link rel="stylesheet" href="css/uikit.min.css">
    <link rel="stylesheet" href="css/nouislider.css">
    <link rel="stylesheet" href="css/index.css?<?=filemtime(__DIR__ . '/css/index.css') ?: 1 ?>">
    <script defer src="js/uikit.min.js"></script>
    <script defer src="js/uikit-icons.min.js"></script>
    <script defer src="js/wNumb.min.js"></script>
    <script defer src="js/nouislider.js"></script>
    <script defer src="js/index.js?<?=filemtime(__DIR__ . '/js/index.js') ?: 1 ?>"></script>
    <script>
        window.addEventListener('DOMContentLoaded', function () { initIndex(); });
    </script>
</head>
<body>
<main class="uk-container uk-container-center uk-margin-top uk-margin-large-bottom uk-text-large">
    <div class="uk-grid" data-uk-grid-margin>
        <div class="uk-width-1-1">
            <h1 class="uk-heading">全文檢索 <span class="uk-text-small">txtgrep</span></h1>
            <form id="search-form" class="uk-text" method="get">
                <select id="source" class="uk-select uk-width-medium uk-text-large" name="source" aria-label="書目">
                    <?php foreach ($books as $book): ?>
                        <option value="<?=htmlspecialchars($book, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
                            <?=htmlspecialchars($book, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>
                        </option>
                    <?php endforeach; ?>
                    <option value="*">*全部</option>
                </select>
                <input id="keyword" name="keyword" class="uk-input uk-width-medium uk-text-large"
                       value="<?=htmlspecialchars($keyword, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"
                       maxlength="256" type="text" placeholder="關鍵字詞（POSIX regex）"
                       aria-label="關鍵字詞（POSIX regex）">
                <button class="uk-button uk-button-primary uk-text-large" id="go" type="submit">檢索</button>
            </form>
            <p id="search-status" role="status" aria-live="polite" aria-atomic="true"></p>
            <?php if ($books === []): ?>
                <p class="uk-text-warning">找不到書目。請確認 TXTGREP_CORPUS 指向含有書目子目錄的資料夾。</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="uk-grid" data-uk-grid-margin>
        <div class="uk-width-1-1">
            <table id="result-table" class="uk-table uk-table-justify uk-table-small uk-table-hover">
                <caption class="sr-only">全文檢索結果</caption>
                <thead class="uk-hidden">
                    <tr>
                        <th scope="col"><span class="uk-text-large">章節</span></th>
                        <th scope="col"><span class="uk-text-large">內文</span></th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</main>
<button id="btnScrollToTop" class="uk-button uk-button-primary uk-border-rounded uk-position-fixed uk-position-bottom-right uk-hidden" type="button" title="回到頂端" aria-label="回到頂端"><span data-uk-icon="icon: arrow-up; ratio: 2" aria-hidden="true"></span></button>
<div id="loading-dialog" class="uk-flex-top uk-text-large" data-uk-modal role="dialog" aria-modal="true" aria-labelledby="loading-title">
    <div class="uk-modal-dialog uk-margin-auto-vertical uk-flex uk-flex-center">
        <div class="uk-container">
            <h2 id="loading-title" class="uk-modal-title">檢索中</h2>
            <p class="uk-text-large"><span data-uk-spinner="ratio: 3" aria-hidden="true"></span></p>
        </div>
    </div>
</div>
</body>
</html>
