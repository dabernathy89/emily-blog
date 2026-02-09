<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;

class ImportBlogPostsFromWp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:blog-posts-from-wp {--pages=0 : Number of pages to fetch (0 for all)} {--dry-run : Show what would be imported without actually importing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import blog posts from WordPress REST API into Statamic Articles collection';

    /**
     * The WordPress site URL
     */
    private string $wpUrl = 'https://www.everydayaccountsblog.com';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting WordPress blog post import...');

        $dryRun = $this->option('dry-run');
        $maxPages = (int) $this->option('pages');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No entries will be created');
        }

        if ($maxPages > 0) {
            $this->info("Will fetch maximum {$maxPages} pages");
        }

        // Ensure Articles collection exists
        $collection = Collection::findByHandle('articles');
        if (!$collection) {
            $this->error('Articles collection not found. Please create it first.');
            return 1;
        }

        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $page = 1;
        $perPage = 10;

        do {
            try{
                $this->info("Fetching page {$page}...");

                $response = Http::get("{$this->wpUrl}/wp-json/wp/v2/posts", [
                    'page' => $page,
                    'per_page' => $perPage,
                    'status' => 'publish',
                    '_embed' => true,
                ]);

                if (!$response->successful()) {
                    $this->error("Failed to fetch page {$page}: " . $response->status());
                    break;
                }

                $posts = $response->json();

                if (empty($posts)) {
                    $this->info("No more posts found. Stopping at page {$page}.");
                    break;
                }

                // Check if we've reached the maximum pages limit
                if ($maxPages > 0 && $page > $maxPages) {
                    $this->info("Reached maximum pages limit ({$maxPages}). Stopping.");
                    break;
                }

                foreach ($posts as $post) {
                    try {
                        $result = $this->processWordPressPost($post, $dryRun);

                        if ($result === 'imported') {
                            $imported++;
                        } elseif ($result === 'skipped') {
                            $skipped++;
                        }

                    } catch (\Exception $e) {
                        $errors++;
                        $this->error("Error processing post ID {$post['id']}: " . $e->getMessage());
                    }
                }

                $page++;

            } catch (\Exception $e) {
                $this->error("Error fetching page {$page}: " . $e->getMessage());
                break;
            }

        } while (!empty($posts));

        $this->info("\nImport completed:");
        $this->info("- Imported: {$imported}");
        $this->info("- Skipped: {$skipped}");
        $this->info("- Errors: {$errors}");

        return 0;
    }

    /**
     * Process a single WordPress post
     */
    private function processWordPressPost($post, $dryRun = false)
    {
        $title = $post['title']['rendered'] ?? '';
        $content = $post['content']['rendered'] ?? '';
        $slug = $post['slug'] ?? '';
        $publishDate = $post['date'] ?? '';

        if (empty($slug)) {
            $slug = Str::slug($title);
        }

        // Check if entry already exists
        $existingEntry = Entry::whereCollection('articles')
            ->where('slug', $slug)
            ->first();

        if ($existingEntry) {
            $this->line("Skipping '{$title}' - entry already exists");
            return 'skipped';
        }

        if ($dryRun) {
            $this->line("Would import: {$title} -> {$slug}");
            return 'imported';
        }

        // Extract categories and tags
        $categories = $this->extractCategories($post);
        $tags = $this->extractTags($post);

        // Ensure taxonomy terms exist
        $processedCategories = $this->ensureCategoryTermsExist($categories);
        $processedTags = $this->ensureTagTermsExist($tags);

        // Create new entry
        $collection = Collection::findByHandle('articles');
        $entry = Entry::make()
            ->collection($collection)
            ->slug($slug);

        // Set the data
        $entryData = [
            'title' => $title,
            'categories' => $processedCategories,
            'tags' => $processedTags,
            'content' => $content,
        ];

        $entry->data($entryData);

        // Set date
        if ($publishDate) {
            $entry->date(Carbon::parse($publishDate));
        }

        $entry->save();

        $this->line("Imported: {$title} -> {$slug}");
        return 'imported';
    }

    /**
     * Extract categories from WordPress post
     */
    private function extractCategories($post)
    {
        $categories = [];

        if (isset($post['_embedded']['wp:term'][0])) {
            foreach ($post['_embedded']['wp:term'][0] as $category) {
                if ($category['taxonomy'] === 'category') {
                    $categories[] = $category['name'];
                }
            }
        }

        return $categories;
    }

    /**
     * Extract tags from WordPress post
     */
    private function extractTags($post)
    {
        $tags = [];

        if (isset($post['_embedded']['wp:term'][1])) {
            foreach ($post['_embedded']['wp:term'][1] as $tag) {
                if ($tag['taxonomy'] === 'post_tag') {
                    $tags[] = $tag['name'];
                }
            }
        }

        return $tags;
    }

    /**
     * Ensure taxonomy terms exist for categories
     */
    private function ensureCategoryTermsExist($categories)
    {
        $processedCategories = [];

        foreach ($categories as $category) {
            $slug = Str::slug($category);

            // Check if term already exists
            $existingTerm = Term::whereTaxonomy('categories')
                ->where('slug', $slug)
                ->first();

            if (!$existingTerm) {
                // Create new term
                $term = Term::make()
                    ->taxonomy('categories')
                    ->slug($slug)
                    ->data([
                        'title' => $category,
                    ]);

                $term->save();
            }

            $processedCategories[] = $slug;
        }

        return $processedCategories;
    }

    /**
     * Ensure taxonomy terms exist for tags
     */
    private function ensureTagTermsExist($tags)
    {
        $processedTags = [];

        foreach ($tags as $tag) {
            $slug = Str::slug($tag);

            // Check if term already exists
            $existingTerm = Term::whereTaxonomy('tags')
                ->where('slug', $slug)
                ->first();

            if (!$existingTerm) {
                // Create new term
                $term = Term::make()
                    ->taxonomy('tags')
                    ->slug($slug)
                    ->data([
                        'title' => $tag
                    ]);

                $term->save();
            }

            $processedTags[] = $slug;
        }

        return $processedTags;
    }
}
