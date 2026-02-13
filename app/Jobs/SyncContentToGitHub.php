<?php

namespace App\Jobs;

use App\Services\GitHubApiService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Finder;

class SyncContentToGitHub implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 120, 300];

    protected const CACHE_KEY = 'github-sync:pending';

    public function handle(GitHubApiService $github): void
    {
        $pending = Cache::pull(static::CACHE_KEY);

        if (empty($pending)) {
            return;
        }

        $changes = $this->collectChanges($github);

        if (empty($changes)) {
            Log::info('GitHub Sync: no file changes detected, skipping commit.');

            return;
        }

        $message = $this->buildCommitMessage($pending);
        $author = $this->resolveAuthor($pending);

        $sha = $github->createCommit($changes, $message, $author);

        Log::info("GitHub Sync: committed {$sha}", [
            'files_changed' => count($changes),
        ]);
    }

    /**
     * Compare local files against the remote tree and return differences.
     *
     * @return array<string, string|null> path => content (null = deletion)
     */
    protected function collectChanges(GitHubApiService $github): array
    {
        $remoteTree = $github->getCurrentTree();
        $paths = config('github-sync.paths', []);
        $basePath = base_path();
        $changes = [];

        $localFiles = [];
        foreach ($paths as $configPath) {
            $fullPath = $basePath.'/'.$configPath;
            if (! is_dir($fullPath)) {
                continue;
            }

            $files = Finder::create()->files()->ignoreDotFiles(false)->in($fullPath);
            foreach ($files as $file) {
                $relativePath = ltrim(str_replace($basePath, '', $file->getPathname()), '/');
                $content = $file->getContents();
                $localSha = GitHubApiService::computeBlobSha($content);

                $localFiles[$relativePath] = true;

                if (! isset($remoteTree[$relativePath]) || $remoteTree[$relativePath] !== $localSha) {
                    $changes[$relativePath] = $content;
                }
            }
        }

        foreach ($remoteTree as $remotePath => $remoteSha) {
            foreach ($paths as $configPath) {
                if (str_starts_with($remotePath, $configPath.'/') && ! isset($localFiles[$remotePath])) {
                    $changes[$remotePath] = null;
                    break;
                }
            }
        }

        return $changes;
    }

    protected function buildCommitMessage(array $pending): string
    {
        $messages = collect($pending)
            ->pluck('message')
            ->unique()
            ->values();

        if ($messages->count() === 1) {
            return $messages->first();
        }

        return "Update content\n\n".
            $messages->map(fn (string $msg) => "- {$msg}")->implode("\n");
    }

    /**
     * @return array{name: string, email: string}
     */
    protected function resolveAuthor(array $pending): array
    {
        $lastWithAuthor = collect($pending)
            ->reverse()
            ->first(fn (array $entry) => ! empty($entry['author']['name']));

        return $lastWithAuthor['author'] ?? [];
    }
}
