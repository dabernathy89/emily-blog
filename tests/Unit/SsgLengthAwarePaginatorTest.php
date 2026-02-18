<?php

namespace Tests\Unit;

use App\Support\SsgLengthAwarePaginator;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SsgLengthAwarePaginatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.url', 'https://www.example.com');
        Config::set('statamic.ssg.pagination_route', '{url}/{page_name}/{page_number}');
    }

    /**
     * Incremental builds pass relative paths (e.g. '/') from Route objects.
     * Without the fix, '/' produces '//page/2' which parse_url() misidentifies
     * as a protocol-relative URL, resulting in '/2' instead of '/page/2'.
     */
    public function test_generates_correct_url_from_relative_path(): void
    {
        $result = SsgLengthAwarePaginator::generatePaginatedUrl('/', 'page', 2);

        $this->assertSame('/page/2', $result);
    }

    public function test_generates_correct_url_from_absolute_url(): void
    {
        $result = SsgLengthAwarePaginator::generatePaginatedUrl('https://www.example.com/', 'page', 2);

        $this->assertSame('/page/2', $result);
    }

    public function test_generates_correct_url_from_absolute_url_with_path(): void
    {
        $result = SsgLengthAwarePaginator::generatePaginatedUrl('https://www.example.com/blog', 'page', 3);

        $this->assertSame('/blog/page/3', $result);
    }

    public function test_generates_correct_url_from_relative_path_with_subpath(): void
    {
        $result = SsgLengthAwarePaginator::generatePaginatedUrl('/blog', 'page', 3);

        $this->assertSame('/blog/page/3', $result);
    }

    public function test_first_page_url_resolves_correctly(): void
    {
        $result = SsgLengthAwarePaginator::generatePaginatedUrl('/', 'page', 1);

        $this->assertSame('/page/1', $result);
    }
}
