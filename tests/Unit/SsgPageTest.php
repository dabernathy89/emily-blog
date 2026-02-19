<?php

namespace Tests\Unit;

use App\Support\SsgPage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SsgPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.url', 'https://www.example.com');
        Config::set('statamic.ssg.pagination_route', '{url}/{page_name}/{page_number}');
    }

    private function makePage(string $contentUrl): SsgPage
    {
        $content = new class($contentUrl)
        {
            public function __construct(private string $url) {}

            public function urlWithoutRedirect(): string
            {
                return $this->url;
            }
        };

        return new SsgPage(app(Filesystem::class), ['destination' => '/tmp'], $content);
    }

    /**
     * The core pagination bug: when a page lives at '/' (home), the SSG's
     * LengthAwarePaginator::generatePaginatedUrl produces '//page/2', which
     * URL::makeRelative() misparses as host='page', path='/2', writing
     * the file to /2/ instead of /page/2/. SsgPage::url() uses the fixed
     * SsgLengthAwarePaginator, which returns '/page/2' correctly.
     */
    public function test_paginated_url_is_correct_for_home_page(): void
    {
        $page = $this->makePage('/');

        $page->setPaginationCurrentPage(2);

        // Access via reflection since paginationPageName defaults to null
        $ref = new \ReflectionProperty($page, 'paginationPageName');
        $ref->setAccessible(true);
        $ref->setValue($page, 'page');

        $this->assertSame('/page/2', $page->url());
    }

    public function test_non_paginated_url_returns_content_url(): void
    {
        $page = $this->makePage('/2014/10/some-post');

        $this->assertSame('/2014/10/some-post', $page->url());
    }

    public function test_paginated_url_is_correct_for_sub_path(): void
    {
        $page = $this->makePage('/blog');

        $page->setPaginationCurrentPage(3);

        $ref = new \ReflectionProperty($page, 'paginationPageName');
        $ref->setAccessible(true);
        $ref->setValue($page, 'page');

        $this->assertSame('/blog/page/3', $page->url());
    }
}
