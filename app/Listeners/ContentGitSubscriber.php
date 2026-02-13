<?php

namespace App\Listeners;

use App\Jobs\SyncContentToGitHub;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Statamic\Contracts\Git\ProvidesCommitMessage;
use Statamic\Events\Concerns\ListensForContentEvents;

class ContentGitSubscriber
{
    use ListensForContentEvents;

    protected const CACHE_KEY = 'github-sync:pending';

    public function subscribe(Dispatcher $events): void
    {
        if (! config('github-sync.enabled')) {
            return;
        }

        foreach ($this->events as $event) {
            $events->listen($event, [static::class, 'handleContentEvent']);
        }
    }

    public function handleContentEvent(mixed $event): void
    {
        if ($this->eventIsIgnored($event)) {
            return;
        }

        $message = $event instanceof ProvidesCommitMessage
            ? $event->commitMessage()
            : class_basename($event);

        $author = $this->extractAuthor($event);

        $pending = Cache::get(static::CACHE_KEY, []);
        $pending[] = [
            'message' => $message,
            'author' => $author,
            'timestamp' => now()->toIso8601String(),
        ];
        Cache::put(static::CACHE_KEY, $pending, now()->addHours(1));

        $delay = (int) config('github-sync.dispatch_delay', 2);
        $connection = config('github-sync.queue_connection');

        $pending = SyncContentToGitHub::dispatch()
            ->delay(now()->addMinutes($delay));

        if ($connection) {
            $pending->onConnection($connection);
        }
    }

    protected function eventIsIgnored(mixed $event): bool
    {
        return collect(config('github-sync.ignored_events', []))
            ->contains(fn (string $ignored) => $event instanceof $ignored);
    }

    /**
     * @return array{name: string, email: string}
     */
    protected function extractAuthor(mixed $event): array
    {
        if (! config('github-sync.use_authenticated')) {
            return [];
        }

        $user = $event->authenticatedUser ?? null;

        if ($user) {
            return [
                'name' => $user->name() ?? $user->email(),
                'email' => $user->email(),
            ];
        }

        return [];
    }
}
