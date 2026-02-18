<?php

namespace App\Providers;

use App\Listeners\ContentGitSubscriber;
use App\Support\SsgLengthAwarePaginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Statamic\Extensions\Pagination\LengthAwarePaginator as StatamicLengthAwarePaginator;
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

        Event::subscribe(ContentGitSubscriber::class);

        if ($this->app->runningInConsole()) {
            $this->app->extend(StatamicLengthAwarePaginator::class, function ($paginator) {
                return $this->app->makeWith(SsgLengthAwarePaginator::class, [
                    'items' => $paginator->getCollection(),
                    'total' => $paginator->total(),
                    'perPage' => $paginator->perPage(),
                    'currentPage' => $paginator->currentPage(),
                    'options' => $paginator->getOptions(),
                ]);
            });
        }
    }
}
