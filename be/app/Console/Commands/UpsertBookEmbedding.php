<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Book;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpsertBookEmbedding extends Command
{
    protected $signature = 'books:upsert-embedding';
    protected $description = 'Upsert book embeddings to Qdrant';

    protected $qdrantUrl = 'http://194.233.95.147:44912';
    protected $collectionName = 'books';
    protected $vectorSize = 768; // Default for jina-embeddings-v2

    public function handle()
    {
        $this->info('Starting book embeddings upsert process...');
        
        try {
            // Create collection if it doesn't exist
            $this->createCollectionIfNotExists();
            
            // Process books in chunks
            Book::chunk(100, function ($books) {
                $this->processBooksBatch($books);
            });

            $this->info('✅ Book embeddings upserted successfully!');
        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            Log::error('Failed to upsert book embeddings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
        
        return 0;
    }

    protected function createCollectionIfNotExists()
    {
        $collectionUrl = "{$this->qdrantUrl}/collections/{$this->collectionName}";
        
        // Check if collection exists
        $response = Http::get($collectionUrl);
        
        if ($response->status() === 200) {
            $this->info("ℹ️  Collection '{$this->collectionName}' already exists");
            return true;
        }
        
        // Create new collection
        $this->info("🔄 Creating collection '{$this->collectionName}'...");
        
        $response = Http::put($collectionUrl, [
            'vectors' => [
                'size' => $this->vectorSize,
                'distance' => 'Cosine'
            ]
        ]);
        
        if (!$response->successful()) {
            throw new \Exception("Failed to create collection: " . $response->body());
        }
        
        $this->info("✅ Collection '{$this->collectionName}' created successfully");
        return true;
    }
    
    protected function processBooksBatch($books)
    {
        $this->info("🔍 Processing batch of " . $books->count() . " books...");
        
        $texts = $books->map(function($book) {
            return implode(' ', [
                'Title:', $book->title,
                'Author:', $book->author,
                'Description:', $book->description,
                'Published in:', $book->publication_year,
                'Category:', $book->category->name ?? 'Uncategorized',
                'Status:', $book->status
            ]);
        })->toArray();

        // Get embeddings from JINA AI
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('JINA_API_KEY'),
            'Content-Type' => 'application/json',
        ])->timeout(120)->post('https://api.jina.ai/v1/embeddings', [
            'input' => $texts,
            'model' => 'jina-embeddings-v2-base-en'
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to get embeddings from JINA AI: ' . $response->body());
        }

        $embeddings = $response->json()['data'];
        
        // Prepare points for Qdrant
        $points = $books->values()->map(function ($book, $i) use ($embeddings) {
            if (!isset($embeddings[$i]['embedding'])) {
                throw new \Exception("Missing embedding for book ID: {$book->id}");
            }
            
            return [
                'id' => $book->id,
                'vector' => $embeddings[$i]['embedding'],
                'payload' => [
                    'title' => $book->title,
                    'author' => $book->author,
                    'description' => $book->description,
                    'publication_year' => $book->publication_year,
                    'status' => $book->status,
                ],
            ];
        });

        // Upsert points to Qdrant
        $response = Http::timeout(60)->put(
            "{$this->qdrantUrl}/collections/{$this->collectionName}/points",
            ['points' => $points->toArray()]
        );

        if (!$response->successful()) {
            throw new \Exception('Failed to upsert points to Qdrant: ' . $response->body());
        }
        
        $this->info("✅ Successfully upserted " . $points->count() . " book embeddings");
    }
}
