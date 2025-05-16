<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Book;
use Illuminate\Support\Facades\Http;

class BookController extends Controller
{
    /**
     * Search books with filters
     *
     * @queryParam status string Filter by book status (available, borrowed, maintenance)
     * @queryParam search string Search by book title or author
     * @queryParam category_id integer Filter by category ID
     * @queryParam per_page integer Items per page. Default: 15
     * @queryParam page integer Page number. Default: 1
     * 
     * @response 200 {
     *   "data": [{"id": 1, "title": "...", ...}],
     *   "current_page": 1,
     *   "per_page": 15,
     *   "total": 10
     * }
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'status' => 'sometimes|in:available,borrowed,maintenance',
            'search' => 'sometimes|string|max:255',
            'category_id' => 'sometimes|integer|exists:categories,id',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1'
        ]);

        $query = Book::query();

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Search by title or author
        if ($search = $request->input('search')) {
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('author', 'like', "%{$search}%");
            });
        }

        // Filter by category
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Get paginated results
        $perPage = $request->input('per_page', 15);
        $books = $query->paginate($perPage);

        return response()->json($books);
    }

    /**
     * AI-based Semantic Search
     *
     * @bodyParam q string required The search query
     * @response 200 {
     *   "data": [{"id": 1, "title": "...", ...}],
     *   "query": "original query",
     *   "count": 5
     * }
     */
    public function searchAI(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:3|max:500',
        ]);

        $query = $request->input('q');

        try {
            // Get embedding from JINA AI
            $apiKey = env('JINA_API_KEY');
            \Log::debug('JINA_API_KEY from env:', ['key_exists' => !empty($apiKey), 'key_length' => strlen($apiKey)]);
            
            if (empty($apiKey)) {
                \Log::error('JINA_API_KEY is empty in .env file');
                throw new \Exception('JINA_API_KEY is not set in .env file');
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->timeout(30)
            ->post('https://api.jina.ai/v1/embeddings', [
                'input' => [$query],
                'model' => 'jina-embeddings-v2-base-en'
            ]);

            if (!$response->successful()) {
                $statusCode = $response->status();
                $errorBody = $response->body();
                throw new \Exception(sprintf(
                    'JINA AI API Error - Status: %d, Response: %s',
                    $statusCode,
                    $errorBody
                ));
            }

            $embeddingData = $response->json();
            
            if (!isset($embeddingData['data'][0]['embedding'])) {
                throw new \Exception('Invalid response format from JINA AI');
            }

            $embedding = $embeddingData['data'][0]['embedding'];

            // Search in Qdrant
            $qdrantUrl = 'http://194.233.95.147:44912/collections/books/points/search';
            \Log::info('AI Search Query', [
                'query' => $query,
                'vector_length' => count($embedding)
            ]);
            
            try {
                $searchResponse = Http::timeout(30)->post($qdrantUrl, [
                    'vector' => $embedding,
                    'top' => 5,
                    'score_threshold' => 0.3,
                    'with_payload' => true,
                    'with_vectors' => false,
                    'params' => [
                        'hnsw_ef' => 128,
                        'exact' => false
                    ]
                ]);

                \Log::debug('Qdrant response status:', ['status' => $searchResponse->status()]);
                \Log::debug('Qdrant response body:', ['body' => $searchResponse->body()]);

                if (!$searchResponse->successful()) {
                    throw new \Exception(sprintf(
                        'Qdrant search failed - Status: %d, Response: %s',
                        $searchResponse->status(),
                        $searchResponse->body()
                    ));
                }
            } catch (\Exception $e) {
                \Log::error('Qdrant request failed', [
                    'error' => $e->getMessage(),
                    'url' => $qdrantUrl,
                    'trace' => $e->getTraceAsString()
                ]);
                throw new \Exception('Qdrant search failed: ' . $e->getMessage());
            }

            $results = $searchResponse->json();
            $bookIds = collect($results['result'] ?? [])->pluck('id');

            // Get full book details from database
            $books = Book::whereIn('id', $bookIds)
                ->get()
                ->sortBy(function ($book) use ($bookIds) {
                    return array_search($book->id, $bookIds->toArray());
                });

            return response()->json([
                'data' => $books,
                'query' => $query,
                'count' => $books->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to process search',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}