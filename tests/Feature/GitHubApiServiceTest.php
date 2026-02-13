<?php

namespace Tests\Feature;

use App\Services\GitHubApiService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubApiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'github-sync.repository' => 'owner/repo',
            'github-sync.branch' => 'main',
            'github-sync.token' => 'fake-token',
            'github-sync.committer.name' => 'Statamic CMS',
            'github-sync.committer.email' => 'cms@example.com',
        ]);
    }

    public function test_create_commit_makes_all_api_calls_in_order(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/git/ref/heads/main' => Http::response([
                'object' => ['sha' => 'abc123'],
            ]),
            'api.github.com/repos/owner/repo/git/commits/abc123' => Http::response([
                'tree' => ['sha' => 'tree456'],
            ]),
            'api.github.com/repos/owner/repo/git/blobs' => Http::response([
                'sha' => 'blob789',
            ]),
            'api.github.com/repos/owner/repo/git/trees' => Http::response([
                'sha' => 'newtree101',
            ]),
            'api.github.com/repos/owner/repo/git/commits' => Http::response([
                'sha' => 'newcommit202',
            ]),
            'api.github.com/repos/owner/repo/git/refs/heads/main' => Http::response([
                'object' => ['sha' => 'newcommit202'],
            ]),
        ]);

        $service = new GitHubApiService;
        $sha = $service->createCommit(
            ['content/test.md' => 'Hello World'],
            'Update test',
            ['name' => 'Jane Doe', 'email' => 'jane@example.com']
        );

        $this->assertEquals('newcommit202', $sha);

        Http::assertSentCount(6);
    }

    public function test_create_commit_sends_base64_encoded_blobs(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/git/ref/heads/main' => Http::response([
                'object' => ['sha' => 'abc123'],
            ]),
            'api.github.com/repos/owner/repo/git/commits/abc123' => Http::response([
                'tree' => ['sha' => 'tree456'],
            ]),
            'api.github.com/repos/owner/repo/git/blobs' => Http::response([
                'sha' => 'blob789',
            ]),
            'api.github.com/repos/owner/repo/git/trees' => Http::response([
                'sha' => 'newtree101',
            ]),
            'api.github.com/repos/owner/repo/git/commits' => Http::response([
                'sha' => 'newcommit202',
            ]),
            'api.github.com/repos/owner/repo/git/refs/heads/main' => Http::response([
                'object' => ['sha' => 'newcommit202'],
            ]),
        ]);

        $service = new GitHubApiService;
        $service->createCommit(['file.md' => 'Test content'], 'msg');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/git/blobs')) {
                return false;
            }

            $body = $request->data();

            return $body['encoding'] === 'base64'
                && $body['content'] === base64_encode('Test content');
        });
    }

    public function test_create_commit_handles_deletions_with_null_sha(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/git/ref/heads/main' => Http::response([
                'object' => ['sha' => 'abc123'],
            ]),
            'api.github.com/repos/owner/repo/git/commits/abc123' => Http::response([
                'tree' => ['sha' => 'tree456'],
            ]),
            'api.github.com/repos/owner/repo/git/trees' => Http::response([
                'sha' => 'newtree101',
            ]),
            'api.github.com/repos/owner/repo/git/commits' => Http::response([
                'sha' => 'newcommit202',
            ]),
            'api.github.com/repos/owner/repo/git/refs/heads/main' => Http::response([
                'object' => ['sha' => 'newcommit202'],
            ]),
        ]);

        $service = new GitHubApiService;
        $sha = $service->createCommit(
            ['content/deleted.md' => null],
            'Delete file'
        );

        $this->assertEquals('newcommit202', $sha);

        // No blob creation for deletions
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/git/blobs');
        });

        // Tree entry should have null sha
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/git/trees')) {
                return false;
            }

            $tree = $request->data()['tree'] ?? [];

            return count($tree) === 1
                && $tree[0]['path'] === 'content/deleted.md'
                && $tree[0]['sha'] === null;
        });
    }

    public function test_create_commit_throws_on_api_error(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/git/ref/heads/main' => Http::response(
                ['message' => 'Not Found'],
                404
            ),
        ]);

        $service = new GitHubApiService;

        $this->expectException(RequestException::class);

        $service->createCommit(['file.md' => 'content'], 'msg');
    }

    public function test_get_current_tree_returns_path_sha_map(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/git/ref/heads/main' => Http::response([
                'object' => ['sha' => 'abc123'],
            ]),
            'api.github.com/repos/owner/repo/git/commits/abc123' => Http::response([
                'tree' => ['sha' => 'tree456'],
            ]),
            'api.github.com/repos/owner/repo/git/trees/tree456*' => Http::response([
                'tree' => [
                    ['path' => 'content/hello.md', 'type' => 'blob', 'sha' => 'sha1'],
                    ['path' => 'content/world.md', 'type' => 'blob', 'sha' => 'sha2'],
                    ['path' => 'content', 'type' => 'tree', 'sha' => 'sha3'],
                ],
            ]),
        ]);

        $service = new GitHubApiService;
        $tree = $service->getCurrentTree();

        $this->assertEquals([
            'content/hello.md' => 'sha1',
            'content/world.md' => 'sha2',
        ], $tree);
    }

    public function test_test_connection_returns_repo_info(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo' => Http::response([
                'name' => 'repo',
                'full_name' => 'owner/repo',
                'permissions' => ['push' => true],
            ]),
        ]);

        $service = new GitHubApiService;
        $result = $service->testConnection();

        $this->assertEquals('owner/repo', $result['full_name']);
        $this->assertTrue($result['permissions']['push']);
    }

    public function test_compute_blob_sha_matches_git_format(): void
    {
        // "Hello World\n" has a well-known git blob SHA
        $content = "Hello World\n";
        $sha = GitHubApiService::computeBlobSha($content);

        // git hash-object: echo "Hello World" | git hash-object --stdin
        $this->assertEquals('557db03de997c86a4a028e1ebd3a1ceb225be238', $sha);
    }

    public function test_commit_includes_author_when_provided(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/git/ref/heads/main' => Http::response([
                'object' => ['sha' => 'abc123'],
            ]),
            'api.github.com/repos/owner/repo/git/commits/abc123' => Http::response([
                'tree' => ['sha' => 'tree456'],
            ]),
            'api.github.com/repos/owner/repo/git/blobs' => Http::response([
                'sha' => 'blob789',
            ]),
            'api.github.com/repos/owner/repo/git/trees' => Http::response([
                'sha' => 'newtree101',
            ]),
            'api.github.com/repos/owner/repo/git/commits' => Http::response([
                'sha' => 'newcommit202',
            ]),
            'api.github.com/repos/owner/repo/git/refs/heads/main' => Http::response([
                'object' => ['sha' => 'newcommit202'],
            ]),
        ]);

        $service = new GitHubApiService;
        $service->createCommit(
            ['file.md' => 'content'],
            'msg',
            ['name' => 'Jane', 'email' => 'jane@test.com']
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/git/commits') || $request->method() !== 'POST') {
                return false;
            }

            $data = $request->data();

            return ($data['author']['name'] ?? null) === 'Jane'
                && ($data['author']['email'] ?? null) === 'jane@test.com'
                && ($data['committer']['name'] ?? null) === 'Statamic CMS';
        });
    }

}
