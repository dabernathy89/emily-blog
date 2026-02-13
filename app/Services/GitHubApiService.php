<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubApiService
{
    protected string $repository;

    protected string $branch;

    protected string $token;

    public function __construct()
    {
        $this->repository = config('github-sync.repository');
        $this->branch = config('github-sync.branch');
        $this->token = $this->resolveToken();
    }

    /**
     * Create an atomic commit on the configured branch.
     *
     * @param  array<string, string|null>  $changes  relativePath => contents (null = deletion)
     * @param  array{name: string, email: string}  $author
     */
    public function createCommit(array $changes, string $message, array $author = []): string
    {
        $currentCommitSha = $this->getCurrentCommitSha();
        $currentTreeSha = $this->getTreeShaForCommit($currentCommitSha);

        $treeEntries = [];
        foreach ($changes as $path => $content) {
            if ($content === null) {
                $treeEntries[] = [
                    'path' => $path,
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha' => null,
                ];
            } else {
                $blobSha = $this->createBlob($content);
                $treeEntries[] = [
                    'path' => $path,
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha' => $blobSha,
                ];
            }
        }

        $newTreeSha = $this->createTree($treeEntries, $currentTreeSha);
        $newCommitSha = $this->createCommitObject($newTreeSha, $currentCommitSha, $message, $author);
        $this->updateRef($newCommitSha);

        return $newCommitSha;
    }

    /**
     * Fetch the full recursive tree for the current branch HEAD.
     *
     * @return array<string, string> path => blob SHA
     */
    public function getCurrentTree(): array
    {
        $commitSha = $this->getCurrentCommitSha();
        $treeSha = $this->getTreeShaForCommit($commitSha);

        $response = $this->client()
            ->get("/repos/{$this->repository}/git/trees/{$treeSha}", [
                'recursive' => '1',
            ]);

        $this->ensureSuccess($response, 'Failed to fetch recursive tree');

        return collect($response->json('tree'))
            ->where('type', 'blob')
            ->pluck('sha', 'path')
            ->all();
    }

    /**
     * Test the connection and permissions.
     *
     * @return array{name: string, full_name: string, permissions: array}
     */
    public function testConnection(): array
    {
        $response = $this->client()->get("/repos/{$this->repository}");

        $this->ensureSuccess($response, 'Failed to connect to GitHub repository');

        return $response->json();
    }

    /**
     * Compute a git blob SHA for local comparison.
     */
    public static function computeBlobSha(string $content): string
    {
        $header = 'blob '.strlen($content)."\0";

        return sha1($header.$content);
    }

    protected function getCurrentCommitSha(): string
    {
        $response = $this->client()
            ->get("/repos/{$this->repository}/git/ref/heads/{$this->branch}");

        $this->ensureSuccess($response, 'Failed to get branch ref');

        return $response->json('object.sha');
    }

    protected function getTreeShaForCommit(string $commitSha): string
    {
        $response = $this->client()
            ->get("/repos/{$this->repository}/git/commits/{$commitSha}");

        $this->ensureSuccess($response, 'Failed to get commit');

        return $response->json('tree.sha');
    }

    protected function createBlob(string $content): string
    {
        $response = $this->client()
            ->post("/repos/{$this->repository}/git/blobs", [
                'content' => base64_encode($content),
                'encoding' => 'base64',
            ]);

        $this->ensureSuccess($response, 'Failed to create blob');

        return $response->json('sha');
    }

    protected function createTree(array $entries, string $baseTreeSha): string
    {
        $response = $this->client()
            ->post("/repos/{$this->repository}/git/trees", [
                'base_tree' => $baseTreeSha,
                'tree' => $entries,
            ]);

        $this->ensureSuccess($response, 'Failed to create tree');

        return $response->json('sha');
    }

    /**
     * @param  array{name: string, email: string}  $author
     */
    protected function createCommitObject(string $treeSha, string $parentSha, string $message, array $author = []): string
    {
        $payload = [
            'message' => $message,
            'tree' => $treeSha,
            'parents' => [$parentSha],
            'committer' => [
                'name' => config('github-sync.committer.name'),
                'email' => config('github-sync.committer.email'),
            ],
        ];

        if (! empty($author['name']) && ! empty($author['email'])) {
            $payload['author'] = [
                'name' => $author['name'],
                'email' => $author['email'],
            ];
        }

        $response = $this->client()
            ->post("/repos/{$this->repository}/git/commits", $payload);

        $this->ensureSuccess($response, 'Failed to create commit');

        return $response->json('sha');
    }

    protected function updateRef(string $commitSha): void
    {
        $response = $this->client()
            ->patch("/repos/{$this->repository}/git/refs/heads/{$this->branch}", [
                'sha' => $commitSha,
            ]);

        $this->ensureSuccess($response, 'Failed to update ref');
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')
            ->withToken($this->token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout(30)
            ->retry(3, 1000);
    }

    protected function ensureSuccess(\Illuminate\Http\Client\Response $response, string $message): void
    {
        if ($response->failed()) {
            throw new RuntimeException(
                "{$message}: {$response->status()} {$response->body()}"
            );
        }
    }

    protected function resolveToken(): string
    {
        return config('github-sync.token', '');
    }
}
