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
    <link rel="stylesheet" href="css/uikit.min.css" />
    <link rel="stylesheet" href="css/nouislider.css" />
    <link rel="stylesheet" href="css/index.css?<?=filemtime(__DIR__ . '/css/index.css') ?: 1 ?>" />
    <script src="js/uikit.min.js"></script>
    <script src="js/uikit-icons.min.js"></script>
    <script src="js/wNumb.min.js"></script>
    <script src="js/nouislider.js"></script>
    <script src="js/cash.min.js"></script>
    <script src="js/index.js?<?=filemtime(__DIR__ . '/js/index.js') ?: 1 ?>"></script>
    <script>
        $(document).ready(function () { initIndex(); });
    </script>
</head>
<body>
<div class="uk-container uk-container-center uk-margin-top uk-margin-large-bottom">
    <div class="uk-grid" data-uk-grid-margin>
        <div class="uk-width-1-1">
            <h1 class="uk-heading">全文檢索 <span class="uk-text-small">txtgrep</span></h1>
            <form id="search-form" class="uk-text">
                <select class="uk-select uk-form-width-small" name="source">
                    <?php foreach ($books as $book): ?>
                        <option value="<?=htmlspecialchars($book, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
                            <?=htmlspecialchars($book, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>
                        </option>
                    <?php endforeach; ?>
                    <option value="*">*全部</option>
                </select>
                <input name="keyword" class="uk-input uk-form-width-small"
                       value="<?=htmlspecialchars($keyword, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"
                       maxlength="256" type="text" placeholder="關鍵字詞（POSIX regex）">
                <button class="uk-button uk-button-primary" id="go">檢索</button>
            </form>
            <p id="search-status" role="status"></p>
            <?php if ($books === []): ?>
                <p class="uk-text-warning">找不到書目。請確認 TXTGREP_CORPUS 指向含有書目子目錄的資料夾。</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="uk-grid" data-uk-grid-margin>
        <div class="uk-width-1-1">
            <table id="result-table" class="uk-table uk-table-justify uk-table-small uk-table-hover"></table>
        </div>
    </div>
</div>
<button id="btnScrollToTop" title="Go to top"><span uk-icon="icon: arrow-up; ratio: 2"></span></button>
<div id="loading-dialog" class="uk-flex-top" uk-modal>
    <div class="uk-modal-dialog uk-margin-auto-vertical uk-flex uk-flex-center">
        <div class="uk-container">
            <h2 class="uk-modal-title">檢索中</h2>
            <p class="uk-text-large"><span uk-spinner="ratio: 3"></span></p>
        </div>
    </div>
</div>
</body>
</html>
