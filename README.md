# txtgrep

以 PostgreSQL 索引支援的全文檢索 + 章節閱讀器。專案內建四部小說（三國演義、水滸傳、紅樓夢、西遊記，共 460 章 / 約 9.8 MB），也可以換成任何「一個書目子目錄 + 一堆 `.txt` 章節」的語料。

特色：

- 每一行存成一個 DB row，由 PostgreSQL 做 regex 過濾與自然排序，稀有詞不再全場掃描。
- 網頁搜尋、章節 reader、rg 風格 CLI 共用同一套搜尋邏輯。
- 所有路徑、DSN、連線帳密、port、結果上限都走環境變數；沒有寫死的本機 hostname 或 DB 名稱。
- 可用 Composer、Makefile、pm2、nginx 範例或 Docker Compose 啟動。

## 需求

- PHP 8.1+，需含 `pdo_pgsql`、`mbstring`、`json`
- PostgreSQL（需支援 ICU collation；常見發行版皆支援）
- 選擇性：Composer、pm2、nginx、Docker / Docker Compose

不需要 Composer dependency；`src/bootstrap.php` 內建簡單 autoloader。

## 快速開始（本機）

```sh
cp .env.example .env
createdb txtgrep                 # 或依你的環境建立 DB
php bin/setup.php                # 建立 collation / tables / indexes
php bin/index.php                # 首次建立索引
php -S 127.0.0.1:8083 -t public
```

開啟 <http://127.0.0.1:8083/>。若你的 PostgreSQL 使用帳密或 TCP，請改 `.env`：

```dotenv
TXTGREP_DSN="pgsql:host=127.0.0.1;port=5432;dbname=txtgrep"
TXTGREP_DB_USER=txtgrep
TXTGREP_DB_PASSWORD=secret
```

也可以用 Makefile：

```sh
make setup
make index
make serve          # PORT=9000 make serve 可改 port
make test
```

或有 Composer：

```sh
composer setup
composer index
composer test
```

## 快速開始（Docker Compose）

```sh
docker compose up -d --build
```

複製 `.env.example` 為 `.env` 後可調整 `TXTGREP_DB_NAME`、`TXTGREP_DB_USER`、`TXTGREP_DB_PASSWORD`、`TXTGREP_PORT`。容器啟動時會自動執行 `bin/setup.php` 與增量 `bin/index.php`，然後在 <http://127.0.0.1:8083/> 提供服務。

### 舊版 Compose 掛載移轉

PostgreSQL 18 的命名卷掛載點已改為 `/var/lib/postgresql`。若現有容器使用舊掛載設定，請在重建前從仍可連線的資料庫匯出備份。先停止 app 與其他寫入者，保持 db 運作：

```sh
docker compose stop app
docker compose exec -T db sh -c 'pg_dump -Fc -U "$POSTGRES_USER" -d "$POSTGRES_DB"' > txtgrep-before-mount-change.dump
docker compose exec -T db pg_restore --list < txtgrep-before-mount-change.dump
docker compose exec -T db sh -c 'psql -X -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT (SELECT count(*) FROM files) AS files, (SELECT count(*) FROM file_lines) AS lines"'
```

如果舊容器已無法啟動，先保留現有資料卷，使用與資料目錄相容的 PostgreSQL 映像與原本的 `PGDATA` 恢復連線，再執行匯出。

確認匯出成功、備份清單包含 `files` 和 `file_lines`，並記下筆數和舊容器實際使用的資料卷。套用新版 Compose 設定後，只重建 db，等健康檢查通過再還原：

```sh
docker compose up -d --wait --force-recreate db
docker compose exec -T db sh -c 'pg_restore --exit-on-error --single-transaction -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < txtgrep-before-mount-change.dump
docker compose exec -T db sh -c 'psql -X -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT (SELECT count(*) FROM files) AS files, (SELECT count(*) FROM file_lines) AS lines"'
```

還原目標應為新初始化的空資料庫。確認筆數與備份前相同後，再用 `docker compose up -d --build app` 啟動應用程式。驗證完成前保留備份與舊資料卷。

## 設定

真正的環境變數優先於 `.env`。

| 變數 | 預設 | 說明 |
| --- | --- | --- |
| `TXTGREP_DSN` | `pgsql:dbname=txtgrep` | PDO PostgreSQL DSN；Unix socket 或 TCP 都可以 |
| `TXTGREP_DB_USER` | 空 | DB 帳號；空白表示交給 peer auth / DSN |
| `TXTGREP_DB_PASSWORD` | 空 | DB 密碼 |
| `TXTGREP_CORPUS` | `txt` | 語料根目錄；相對路徑以專案根為準 |
| `TXTGREP_PATH_PREFIX` | `txt` | DB 與 URL 中的邏輯前綴 |
| `TXTGREP_PORT` | `8083` | `php -S` 與 pm2 使用的 port |
| `TXTGREP_LIMIT` | `500` | 每次搜尋的全域上限（最大 5000） |
| `TXTGREP_TIMEOUT_MS` | `40000` | 搜尋的 `statement_timeout` |

語料結構：

```text
txt/
├── 三國演義/第001回 ... .txt
├── 水滸傳/第001回 ... .txt
├── 紅樓夢/第001回 ... .txt
└── 西遊記/第001回 ... .txt
```

新增書目只要加一個子目錄再重新執行索引即可；不需要改程式。

## 匯入 / 更新索引

```sh
php bin/index.php                     # 只重建 size 或 mtime 改變的章節
php bin/index.php --hash-check        # 額外用 sha256 確認內容
php bin/index.php --full 三國演義      # 強制重建一部
php bin/index.php --full              # 全部重建
php bin/index.php 西遊記               # 只處理一部；不會誤刪其他書
```

- 同一資料庫已有索引執行中時，新的索引程序會立即結束並回傳狀態碼 `1`。
- 清理限於目前路徑前綴及成功掃描的範圍。
- 只指定部分書目時，清理範圍也只限那些書目。
- 單一檔案以 transaction 重建。取得檔案資訊、讀取雜湊或重建失敗時保留舊索引，並繼續處理其他檔案。
- 無效 UTF-8 bytes 會被轉成 U+FFFD，行號仍與原檔一致。

## CLI

```sh
php bin/grep.php '宋江'
php bin/grep.php -i '孫悟空.{0,10}金箍棒' 西遊記
php bin/grep.php --max-count 20 --json '賈寶玉'
php bin/grep.php -- '以-開頭的pattern'
```

輸出為 `邏輯路徑:行號:內容`；`--json` 另附 `book`、`url` 與 highlight offsets。CLI 預設**區分大小寫**，`-i` 才不分；網頁搜尋固定不分大小寫。

## 網頁與 reader

- `public/index.php`：搜尋首頁，結果不顯示行號（API / CLI 仍附 `line`）。
- `public/grep.php`：POST JSON API，回傳與原版相容的欄位，另加 `url`、`book`。
- `public/reader.php`：接受 `?file=txt/書名/章節.txt` 或相容的 `?inode=...`；用 DB + 自然排序找上下章，不再依賴 `find`/symlink。

## Regex 語意（重要）

資料庫用的是 PostgreSQL POSIX ARE，不是 PHP PCRE：

| 意圖 | 寫法 |
| --- | --- |
| 單字邊界 | `\y`（不是 `\b`） |
| PCRE 控制動詞 | 不支援，例如 `(*LIMIT_MATCH=...)` 會被拒絕 |
| 常見用法 | `(?:...)`、`\d`、`.{2,}?` 等兩邊皆可用 |

送出前會先讓 PostgreSQL 編譯一次 pattern，無效 regex 回 400。高亮偏移只對回傳的 rows 用 PHP PCRE 重算；PCRE 不相容時單純不高亮，不影響搜尋結果。

## 測試

```sh
php tests/run.php          # 先 php -l 所有檔案，再跑 smoke
sh tests/benchmark.sh http://127.0.0.1:8083 local
```

`tests/smoke.php` 會從現有索引挑一段文字測試搜尋、書目篩選、限制、regex 驗證、路徑遍歷防護與 reader，因此換語料也能用。

## 部署模板

- `ecosystem.config.js`：pm2 + `php -S`，讀 `TXTGREP_PORT` / `PHP_BIN` / `TXTGREP_APP_NAME`。
- `deploy/nginx.conf.example`：通用反向代理；請自行替換 `server_name`、TLS 憑證與 `proxy_pass`。
- `Dockerfile` / `docker-compose.yml`：PostgreSQL + PHP + 自動索引。

## 目錄結構

```text
bin/        CLI：setup / index / grep
config/     預留給自訂部署設定
deploy/     nginx 範例
public/     Web root：index.php、grep.php、reader.php、靜態資源
sql/        schema.sql
src/        Config / Database / Search / Indexer / Reader / Paths / Text
tests/      lint + smoke + benchmark
txt/        內建語料（可直接替換）
```

## License

MIT，見 `LICENSE`。
