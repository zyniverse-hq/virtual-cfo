<?php

namespace App\Jobs;

use App\Ai\Agents\DescriptionSummarizer;
use App\Models\ImportedFile;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SummarizeTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ImportedFile $file
    ) {}

    public function handle(): void
    {
        // Get all transactions for this file that don't have a short_description yet
        // Chunk by 50 to avoid hitting token limits and keep it cost-efficient
        $this->file->transactions()
            ->whereNull('short_description')
            ->select(['id', 'description'])
            ->chunkById(50, function ($transactions) {
                $batch = $transactions->map(function ($t) {
                    /** @var Transaction $t */
                    return [
                        'id' => $t->id,
                        'description' => $t->description,
                    ];
                })->toArray();

                try {
                    $response = DescriptionSummarizer::make()->prompt(
                        'Summarize these transaction descriptions: '.json_encode($batch)
                    );

                    /** @var array<int, array{transaction_id: int, short_description: string}> $summaries */
                    $summaries = $response['summaries'] ?? [];

                    // Group by transaction_id for easy lookup
                    $summariesMap = collect($summaries)->keyBy('transaction_id');

                    // Batch update
                    foreach ($transactions as $transaction) {
                        /** @var Transaction $transaction */
                        $summary = $summariesMap->get($transaction->id);
                        if ($summary && ! empty($summary['short_description'])) {
                            $transaction->updateQuietly([
                                'short_description' => $summary['short_description'],
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to summarize descriptions batch', [
                        'file_id' => $this->file->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
