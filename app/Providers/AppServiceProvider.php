<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Statamic\Facades\Entry;
use Statamic\StaticSite\SSG;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        SSG::addUrls(function () {
            return Entry::query()
                ->where('collection', 'articles')
                ->get()
                ->map(fn ($entry) => $entry->date()->format('Y/m'))
                ->unique()
                ->values()
                ->map(fn ($path) => '/'.$path)
                ->all();
        });
    }
}
