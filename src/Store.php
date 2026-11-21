<?php

namespace Geodro\ErrorReport;

use RuntimeException;

// One JSON file per distinct error, holding its count and latest occurrence.
class Store
{
    public function __construct(private string $path, private int $maxEntries) {}

    /**
     * @param array<string, mixed> $occurrence
     * @return array<string, mixed>
     */
    public function save(array $occurrence): array
    {
        $this->ensureDirectory();
        $file = $this->file($occurrence['id']);
        $handle = fopen($file, 'c+') ?: throw new RuntimeException("cannot open {$file}");

        try {
            flock($handle, LOCK_EX);
            $existing = json_decode(stream_get_contents($handle) ?: 'null', true);
            $now = gmdate('Y-m-d H:i:s').' UTC';
            $entry = [
                'id' => $occurrence['id'],
                'count' => ($existing['count'] ?? 0) + 1,
                'first_seen' => $existing['first_seen'] ?? $now,
                'last_seen' => $now,
                'seen_at' => microtime(true),
                'last' => $occurrence,
            ];
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
            @chmod($file, 0666);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        // Only a new entry can push the store past its limit; a repeat must stay cheap during an error storm.
        if ($entry['count'] === 1) {
            $this->prune();
        }

        return $entry;
    }

    /** @return list<array> newest first */
    public function all(): array
    {
        $this->ensureReadable();
        $entries = array_filter(array_map(
            fn ($f) => json_decode((string) @file_get_contents($f), true),
            glob($this->path.'/*.json') ?: [],
        ));
        usort($entries, fn ($a, $b) => $b['seen_at'] <=> $a['seen_at']);

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $this->ensureReadable();
        if ($id === 'latest') {
            return $this->all()[0] ?? null;
        }
        if (! preg_match('/^[0-9a-f]+$/', $id) || ! is_file($this->file($id))) {
            return null;
        }

        return json_decode((string) file_get_contents($this->file($id)), true);
    }

    public function clear(): void
    {
        array_map('unlink', glob($this->path.'/*.json') ?: []);
    }

    private function prune(): void
    {
        foreach (array_slice($this->all(), $this->maxEntries) as $stale) {
            @unlink($this->file($stale['id']));
        }
    }

    // PHP-FPM and the artisan/cron user are often different accounts, and whichever errors
    // first creates the folder. Entries are anonymised, so open permissions keep both working.
    private function ensureDirectory(): void
    {
        if (! is_dir($this->path)) {
            if (! @mkdir($this->path, 0777, true) && ! is_dir($this->path)) {
                throw new RuntimeException("cannot create {$this->path}");
            }
            @chmod($this->path, 0777);
        }
    }

    private function ensureReadable(): void
    {
        if ($dir = @opendir($this->path)) {
            closedir($dir);

            return;
        }
        // Not created yet is fine; a folder this account cannot see must not pass for an empty one.
        if (! is_dir($this->path) && ($parent = @opendir(dirname($this->path)))) {
            closedir($parent);

            return;
        }
        $user = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'this user') : 'this user';
        throw new RuntimeException("Cannot read {$this->path} as {$user}. Run the command as the user PHP-FPM runs as.");
    }

    private function file(string $id): string
    {
        return $this->path.'/'.$id.'.json';
    }
}
