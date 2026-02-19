<?php

namespace App\Support;

use Statamic\StaticSite\Page;

class SsgPage extends Page
{
    /**
     * Override url() to use SsgLengthAwarePaginator::generatePaginatedUrl()
     * instead of the hardcoded Statamic\StaticSite\LengthAwarePaginator version.
     *
     * The SSG's LengthAwarePaginator::generatePaginatedUrl() does not handle
     * relative paths (e.g. '/') correctly — it produces '//page/2', which
     * URL::makeRelative() misparses as a protocol-relative URL (host='page',
     * path='/2'), writing the file to /2/ instead of /page/2/.
     */
    public function url(): string
    {
        $url = $this->content->urlWithoutRedirect();

        if ($this->paginationCurrentPage) {
            $url = SsgLengthAwarePaginator::generatePaginatedUrl(
                $url,
                $this->paginationPageName,
                $this->paginationCurrentPage
            );
        }

        return $url;
    }
}
