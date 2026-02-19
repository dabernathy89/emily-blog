<?php

namespace App\Providers;

use App\Listeners\ContentGitSubscriber;
use App\Support\SsgGenerator;
use App\Support\SsgLengthAwarePaginator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Statamic\Extensions\Pagination\LengthAwarePaginator as StatamicLengthAwarePaginator;
use Statamic\Facades\Entry;
use Statamic\StaticSite\Generator;
use Statamic\StaticSite\SSG;
use Statamic\StaticSite\Tasks;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Generator::class, function ($app) {
            return new SsgGenerator($app, $app[Filesystem::class], $app[Router::class], $app[Tasks::class]);
        });
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
