<?php

namespace App\Support;

use Statamic\Facades\URL;
use Statamic\StaticSite\LengthAwarePaginator as SsgPaginator;
use Statamic\Support\Arr;
use Statamic\Support\Str;

class SsgLengthAwarePaginator extends SsgPaginator
{
    /**
     * Override to use static:: instead of self:: so generatePaginatedUrl() dispatch
     * resolves to this class's overridden version at runtime.
     */
    public function url($page): string
    {
        if ($page <= 0) {
            $page = 1;
        }

        $url = static::generatePaginatedUrl($this->path(), $this->getPageName(), $page);

        if (Str::contains($this->path(), '?') || count($this->query)) {
            $url .= '?'.Arr::query($this->query);
        }

        return $url.$this->buildFragment();
    }

    /**
     * Fix: ensure $url is absolute before inserting into the route template.
     *
     * The SSG's version assumes $url is always absolute (true for full builds where
     * pages are Entry objects). Incremental builds use Route objects whose
     * urlWithoutRedirect() returns a relative path like '/'. Inserting '/' into
     * '{url}/page/{page_number}' produces '//page/2', which parse_url() misinterprets
     * as a protocol-relative URL (host='page', path='/2'), so files end up at /2/
     * instead of /page/2/.
     */
    public static function generatePaginatedUrl($url, $pageName, $pageNumber): string
    {
        if (! str_starts_with($url, 'http')) {
            $url = rtrim(config('app.url'), '/').$url;
        }

        $route = config('statamic.ssg.pagination_route');
        $url = str_replace('{url}', $url, $route);
        $url = str_replace('{page_name}', $pageName, $url);
        $url = str_replace('{page_number}', $pageNumber, $url);

        return URL::makeRelative($url);
    }
}
