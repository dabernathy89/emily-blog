<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;

class ImportBlogPosts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:blog-posts {--dry-run : Show what would be imported without actually importing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import blog posts from JSON files in resources/blogposts-old into Statamic Articles collection';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting blog post import...');

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No entries will be created');
        }

        // Ensure Articles collection exists
        $collection = Collection::findByHandle('articles');
        if (!$collection) {
            $this->error('Articles collection not found. Please create it first.');
            return 1;
        }

        $blogPostsPath = resource_path('blogposts-old');

        if (!File::exists($blogPostsPath)) {
            $this->error("Blog posts directory not found: {$blogPostsPath}");
            return 1;
        }

        $jsonFiles = $this->getJsonFiles($blogPostsPath);
        $this->info("Found " . count($jsonFiles) . " JSON files to process");

        $imported = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($jsonFiles as $file) {
            try {
                $result = $this->processJsonFile($file, $dryRun);

                if ($result === 'imported') {
                    $imported++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                }

            } catch (\Exception $e) {
                $errors++;
                $this->error("Error processing {$file}: " . $e->getMessage());
            }
        }

        $this->info("\nImport completed:");
        $this->info("- Imported: {$imported}");
        $this->info("- Skipped: {$skipped}");
        $this->info("- Errors: {$errors}");

        return 0;
    }

    /**
     * Get all JSON files recursively from the blog posts directory
     */
    private function getJsonFiles($path)
    {
        $jsonFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'json') {
                $jsonFiles[] = $file;
            }
        }

        return $jsonFiles;
    }

    /**
     * Process a single JSON file
     */
    private function processJsonFile($file, $dryRun = false)
    {
        $content = File::get($file->getPathname());
        $data = json_decode($content, true);

        if (!$data || !isset($data['data']) || !isset($data['content'])) {
            throw new \Exception('Invalid JSON structure');
        }

        $postData = $data['data'];
        $postContent = $data['content'];

        // Extract filename without extension as potential slug
        $filename = $file->getBasename('.json');
        $slug = $this->generateSlug($filename, $postData['title'] ?? '');

        // Check if entry already exists
        $existingEntry = Entry::whereCollection('articles')
            ->where('slug', $slug)
            ->first();

        if ($existingEntry) {
            $this->line("Skipping {$filename} - entry already exists");
            return 'skipped';
        }

        if ($dryRun) {
            $this->line("Would import: {$filename} -> {$slug}");
            return 'imported';
        }

        // Ensure taxonomy terms exist and get processed terms
        $processedCategories = $this->ensureCategoryTermsExist($postData['categories'] ?? []);
        $processedTags = $this->ensureTagTermsExist($postData['tags'] ?? []);

        // Create new entry
        $collection = Collection::findByHandle('articles');
        $entry = Entry::make()
            ->collection($collection)
            ->slug($slug);

        // Set the data
        $entryData = [
            'title' => $postData['title'] ?? '',
            'categories' => $processedCategories,
            'tags' => $processedTags,
            'content' => $this->convertContentToBard($postContent),
        ];

        $entry->data($entryData);

        // Set date separately
        if (isset($postData['date'])) {
            $entry->date(Carbon::parse($postData['date']));
        }

        $entry->save();

        $this->line("Imported: {$filename} -> {$slug}");
        return 'imported';
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
                        'title' => Str::title($category),
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
                        'title' => Str::title($tag)
                    ]);

                $term->save();
            }

            $processedTags[] = $slug;
        }

        return $processedTags;
    }

    /**
     * Generate a slug from filename and title
     */
    private function generateSlug($filename, $title)
    {
        // If filename already looks like a slug, use it
        if (preg_match('/^\d{4}-\d{2}-\d{2}-(.+)$/', $filename, $matches)) {
            return $matches[1];
        }

        // Otherwise generate from title
        return \Illuminate\Support\Str::slug($title ?: $filename);
    }

    /**
     * Convert the JSON content structure to Statamic Bard format
     */
    private function convertContentToBard($contentData)
    {
        if (!isset($contentData['content']) || !is_array($contentData['content'])) {
            return [];
        }

        $bardContent = [];

        foreach ($contentData['content'] as $block) {
            $bardBlock = $this->convertBlockToBard($block);
            if ($bardBlock) {
                $bardContent[] = $bardBlock;
            }
        }

        return $bardContent;
    }

    /**
     * Convert a single block to Bard format
     */
    private function convertBlockToBard($block)
    {
        if (!isset($block['type'])) {
            return null;
        }

        switch ($block['type']) {
            case 'paragraph':
                return [
                    'type' => 'paragraph',
                    'content' => $this->convertChildrenToBard($block['content'] ?? [])
                ];

            case 'heading':
                $level = $block['attrs']['level'] ?? 1;
                return [
                    'type' => 'heading',
                    'attrs' => [
                        'level' => $level
                    ],
                    'content' => $this->convertChildrenToBard($block['content'] ?? [])
                ];

            case 'image':
                return [
                    'type' => 'image',
                    'attrs' => [
                        'src' => $block['attrs']['src'] ?? '',
                        'alt' => $block['attrs']['alt'] ?? '',
                        'title' => $block['attrs']['title'] ?? null
                    ]
                ];

            default:
                // For other block types, try to preserve the structure
                return [
                    'type' => $block['type'],
                    'content' => $this->convertChildrenToBard($block['content'] ?? [])
                ];
        }
    }

    /**
     * Convert children elements to Bard format
     */
    private function convertChildrenToBard($children)
    {
        $bardChildren = [];

        foreach ($children as $child) {
            if (isset($child['type'])) {
                switch ($child['type']) {
                    case 'text':
                        // Skip empty text values
                        if (empty(trim($child['text'] ?? ''))) {
                            continue 2;
                        }

                        $textNode = [
                            'type' => 'text',
                            'text' => $child['text'] ?? ''
                        ];

                        // Add marks if they exist
                        if (isset($child['marks']) && is_array($child['marks'])) {
                            $textNode['marks'] = $this->convertMarks($child['marks']);
                        }

                        $bardChildren[] = $textNode;
                        break;

                    case 'image':
                        $bardChildren[] = [
                            'type' => 'image',
                            'attrs' => [
                                'src' => $child['attrs']['src'] ?? '',
                                'alt' => $child['attrs']['alt'] ?? '',
                                'title' => $child['attrs']['title'] ?? null
                            ]
                        ];
                        break;

                    default:
                        // For other inline elements, try to preserve structure
                        $bardChildren[] = $this->convertBlockToBard($child);
                        break;
                }
            }
        }

        return $bardChildren;
    }

    /**
     * Convert marks to Bard format
     */
    private function convertMarks($marks)
    {
        $bardMarks = [];

        foreach ($marks as $mark) {
            switch ($mark['type']) {
                case 'link':
                    $bardMarks[] = [
                        'type' => 'link',
                        'attrs' => [
                            'href' => $mark['attrs']['href'] ?? '#',
                            'title' => $mark['attrs']['title'] ?? null
                        ]
                    ];
                    break;

                case 'em':
                    $bardMarks[] = [
                        'type' => 'italic'
                    ];
                    break;

                case 'strong':
                    $bardMarks[] = [
                        'type' => 'bold'
                    ];
                    break;

                default:
                    // Preserve other mark types as-is
                    $bardMarks[] = $mark;
                    break;
            }
        }

        return $bardMarks;
    }

    /**
     * Extract plain text from children elements
     */
    private function extractTextFromChildren($children)
    {
        $text = '';
        foreach ($children as $child) {
            if (isset($child['text']) && !empty(trim($child['text']))) {
                $text .= $child['text'];
            }
        }
        return $text;
    }
}
