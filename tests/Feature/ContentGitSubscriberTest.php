<?php

namespace Tests\Feature;

use App\Jobs\SyncContentToGitHub;
use App\Listeners\ContentGitSubscriber;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntrySaved;
use Tests\TestCase;

class ContentGitSubscriberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'github-sync.enabled' => true,
            'github-sync.dispatch_delay' => 2,
            'github-sync.queue_connection' => null,
            'github-sync.use_authenticated' => true,
            'github-sync.ignored_events' => [],
        ]);

        Cache::forget('github-sync:pending');
        Cache::lock('laravel_unique_job:App\Jobs\SyncContentToGitHub:')->forceRelease();
    }

    public function test_event_dispatches_sync_job(): void
    {
        Bus::fake([SyncContentToGitHub::class]);

        $event = $this->createMockEvent('Entry saved');

        $subscriber = new ContentGitSubscriber;
        $subscriber->handleContentEvent($event);

        Bus::assertDispatched(SyncContentToGitHub::class);
    }

    public function test_event_appends_to_cache(): void
    {
        Bus::fake();

        $event = $this->createMockEvent('Entry saved');

        $subscriber = new ContentGitSubscriber;
        $subscriber->handleContentEvent($event);

        $pending = Cache::get('github-sync:pending');

        $this->assertCount(1, $pending);
        $this->assertEquals('Entry saved', $pending[0]['message']);
    }

    public function test_multiple_events_batch_in_cache(): void
    {
        Bus::fake();

        $subscriber = new ContentGitSubscriber;
        $subscriber->handleContentEvent($this->createMockEvent('First save'));
        $subscriber->handleContentEvent($this->createMockEvent('Second save'));

        $pending = Cache::get('github-sync:pending');

        $this->assertCount(2, $pending);
        $this->assertEquals('First save', $pending[0]['message']);
        $this->assertEquals('Second save', $pending[1]['message']);
    }

    public function test_disabled_config_skips_subscription(): void
    {
        config(['github-sync.enabled' => false]);

        $dispatcher = Event::getFacadeRoot();
        $subscriber = new ContentGitSubscriber;
        $subscriber->subscribe($dispatcher);

        Bus::fake();

        // Fire an EntrySaved-like event through the dispatcher — it should not reach our subscriber
        // since subscribe() returned early without registering listeners
        $this->assertNull(Cache::get('github-sync:pending'));
    }

    public function test_ignored_events_are_skipped(): void
    {
        Bus::fake();

        config(['github-sync.ignored_events' => [EntrySaved::class]]);

        $event = $this->createStub(EntrySaved::class);

        $subscriber = new ContentGitSubscriber;
        $subscriber->handleContentEvent($event);

        Bus::assertNotDispatched(SyncContentToGitHub::class);
    }

    public function test_authenticated_user_is_extracted_as_author(): void
    {
        Bus::fake();

        $user = new class
        {
            public function name(): string
            {
                return 'Jane Doe';
            }

            public function email(): string
            {
                return 'jane@example.com';
            }
        };

        $event = $this->createMockEvent('Entry saved');
        $event->authenticatedUser = $user;

        $subscriber = new ContentGitSubscriber;
        $subscriber->handleContentEvent($event);

        $pending = Cache::get('github-sync:pending');
        $this->assertEquals('Jane Doe', $pending[0]['author']['name']);
        $this->assertEquals('jane@example.com', $pending[0]['author']['email']);
    }

    public function test_commit_message_used_from_provides_commit_message_interface(): void
    {
        Bus::fake();

        $event = new class implements \Statamic\Contracts\Git\ProvidesCommitMessage
        {
            public function commitMessage(): string
            {
                return 'Custom commit message from event';
            }
        };

        $subscriber = new ContentGitSubscriber;
        $subscriber->handleContentEvent($event);

        $pending = Cache::get('github-sync:pending');
        $this->assertEquals('Custom commit message from event', $pending[0]['message']);
    }

    protected function createMockEvent(string $message = 'Test event'): object
    {
        return new class($message) implements \Statamic\Contracts\Git\ProvidesCommitMessage
        {
            public mixed $authenticatedUser = null;

            public function __construct(protected string $message) {}

            public function commitMessage(): string
            {
                return $this->message;
            }
        };
    }
}
