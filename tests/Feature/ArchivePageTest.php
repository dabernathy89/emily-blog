<?php

namespace Tests\Feature;

use Tests\TestCase;

class ArchivePageTest extends TestCase
{
    public function test_archive_page_returns_200_with_heading(): void
    {
        $response = $this->get('/2021/03');

        $response->assertStatus(200);
        $response->assertSee('March 2021');
    }

    public function test_archive_page_shows_articles_from_that_month(): void
    {
        $response = $this->get('/2021/03');

        $response->assertStatus(200);
        $response->assertSee('Scenes From the Month: February 2021');
    }

    public function test_invalid_year_format_returns_404(): void
    {
        $response = $this->get('/21/03');

        $response->assertStatus(404);
    }

    public function test_invalid_month_format_returns_404(): void
    {
        $response = $this->get('/2021/3');

        $response->assertStatus(404);
    }

    public function test_empty_month_shows_no_articles_message(): void
    {
        $response = $this->get('/2025/01');

        $response->assertStatus(200);
        $response->assertSee('No articles found for this month');
    }
}
