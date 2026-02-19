<?php

namespace App\Support;

use Statamic\StaticSite\Generator;

class SsgGenerator extends Generator
{
    /**
     * Override to return SsgPage instead of Statamic\StaticSite\Page,
     * ensuring paginated file paths are computed correctly.
     */
    protected function createPage($content): SsgPage
    {
        return new SsgPage($this->files, $this->config, $content);
    }
}
