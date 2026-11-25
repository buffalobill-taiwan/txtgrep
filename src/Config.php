<?php
declare(strict_types=1);

namespace TxtGrep;

/** Reads environment variables, optionally seeded from a project-local .env file. */
final class Config
{
    private static ?self $instance = null;

    /** @var array<string, string> */
    private array $dotenv = [];

    /** @var array<string, string> */
    private array $values = [];

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $root = dirname(__DIR__);
        $envFile = $root . '/.env';
        if (is_file($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    $separator = strpos($line, '=');
                    if ($separator === false) {
                        continue;
                    }
                    $key = trim(substr($line, 0, $separator));
                    $value = trim(substr($line, $separator + 1));
                    if (strlen($value) >= 2
                        && (($value[0] === '"' && str_ends_with($value, '"'))
                            || ($value[0] === "'" && str_ends_with($value, "'")))) {
                        $value = substr($value, 1, -1);
                    }
                    $this->dotenv[$key] = $value;
                }
            }
        }

        $corpus = $this->env('TXTGREP_CORPUS', 'txt');
        if (str_starts_with($corpus, '~/')) {
            $home = getenv('HOME');
            $corpus = ($home === false ? '' : $home) . substr($corpus, 1);
        }
        if (!str_starts_with($corpus, '/')) {
            $corpus = $root . '/' . $corpus;
        }

        $prefix = trim($this->env('TXTGREP_PATH_PREFIX', 'txt'), '/');

        $this->values = [
            'project_root' => $root,
            'corpus' => rtrim($corpus, '/'),
            'path_prefix' => $prefix === '' ? 'txt' : $prefix,
            'dsn' => $this->env('TXTGREP_DSN', 'pgsql:dbname=txtgrep'),
            'user' => $this->env('TXTGREP_DB_USER', ''),
            'password' => $this->env('TXTGREP_DB_PASSWORD', ''),
            'port' => $this->env('TXTGREP_PORT', '8083'),
            'limit' => $this->env('TXTGREP_LIMIT', '500'),
            'timeout_ms' => $this->env('TXTGREP_TIMEOUT_MS', '40000'),
        ];
    }

    private function env(string $key, string $default): string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        return $this->dotenv[$key] ?? $default;
    }

    public function value(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    public function projectRoot(): string
    {
        return $this->values['project_root'];
    }

    /** Absolute filesystem path to the corpus root. */
    public function corpus(): string
    {
        return $this->values['corpus'];
    }

    /** Prefix stored in the DB and exposed in URLs, e.g. "txt". */
    public function pathPrefix(): string
    {
        return $this->values['path_prefix'];
    }

    public function dsn(): string
    {
        return $this->values['dsn'];
    }

    public function user(): ?string
    {
        return $this->values['user'] !== '' ? $this->values['user'] : null;
    }

    public function password(): ?string
    {
        return $this->values['password'] !== '' ? $this->values['password'] : null;
    }

    public function port(): int
    {
        $port = (int) $this->values['port'];
        return $port > 0 && $port < 65536 ? $port : 8083;
    }

    public function limit(): int
    {
        $limit = (int) $this->values['limit'];
        return $limit > 0 ? min($limit, 5000) : 500;
    }

    public function timeoutMs(): int
    {
        $timeout = (int) $this->values['timeout_ms'];
        return $timeout > 0 ? $timeout : 40000;
    }
}
