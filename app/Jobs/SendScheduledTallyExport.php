<?php

namespace App\Jobs;

use App\Mail\TallyExportMail;
use App\Models\ScheduledTallyExport;
use App\Models\Transaction;
use App\Services\TallyExport\TallyExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use ZipArchive;

class SendScheduledTallyExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public ScheduledTallyExport $scheduledExport,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new Middleware\SetTenantForJob,
        ];
    }

    /**
     * Exponential backoff intervals in seconds.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(TallyExportService $tallyExportService): void
    {
        $this->scheduledExport->loadMissing('company');
        $company = $this->scheduledExport->company;

        [$from, $to] = $this->scheduledExport->resolvedDateRange();

        $query = Transaction::query()
            ->where('company_id', $company->id)
            ->whereNotNull('account_head_id')
            ->whereBetween('date', [$from, $to])
            ->with(['accountHead', 'importedFile.company', 'importedFile.bankAccount'])
            ->orderBy('date');

        $this->applyStatementTypeFilter($query);

        $transactions = $query->get();

        if ($transactions->isEmpty()) {
            $this->updateRunStatus('no_data', 'No mapped transactions found for the configured date range.');
            Log::info('Scheduled Tally export skipped — no transactions', [
                'schedule_id' => $this->scheduledExport->id,
                'company_id' => $company->id,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ]);

            return;
        }

        $xml = $tallyExportService->exportTransactions($transactions);
        $zipPath = $this->createZipFile($xml, $from, $to);

        try {
            $periodDescription = $from->format('d M Y').' – '.$to->format('d M Y');

            /** @var array<int, string> $recipients */
            $recipients = $this->scheduledExport->recipient_emails;

            foreach ($recipients as $email) {
                Mail::to($email)->send(new TallyExportMail(
                    companyName: $company->name,
                    periodDescription: $periodDescription,
                    transactionCount: $transactions->count(),
                    zipFilePath: $zipPath,
                ));
            }

            $this->updateRunStatus('success');

            Log::info('Scheduled Tally export sent successfully', [
                'schedule_id' => $this->scheduledExport->id,
                'company_id' => $company->id,
                'transaction_count' => $transactions->count(),
                'recipients' => $recipients,
            ]);

            activity('scheduled-tally-exports')
                ->performedOn($this->scheduledExport)
                ->withProperties([
                    'status' => 'success',
                    'transaction_count' => $transactions->count(),
                    'period' => $periodDescription,
                    'recipients' => $recipients,
                ])
                ->log('Scheduled Tally XML export sent');
        } finally {
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->updateRunStatus('failed', $exception->getMessage());

        Log::error('Scheduled Tally export failed', [
            'schedule_id' => $this->scheduledExport->id,
            'exception' => $exception->getMessage(),
        ]);

        activity('scheduled-tally-exports')
            ->performedOn($this->scheduledExport)
            ->withProperties([
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ])
            ->log('Scheduled Tally XML export failed');
    }

    /** @param Builder<Transaction> $query */
    private function applyStatementTypeFilter(Builder $query): void
    {
        $statementType = $this->scheduledExport->statement_type;

        if ($statementType !== null) {
            $query->whereHas(
                'importedFile',
                fn (Builder $q) => $q->where('statement_type', $statementType)
            );
        }
    }

    private function createZipFile(string $xml, Carbon $from, Carbon $to): string
    {
        $tempDir = storage_path('app/private/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $xmlFilename = 'tally-export-'.$from->format('Y-m-d').'-to-'.$to->format('Y-m-d').'.xml';
        $zipPath = $tempDir.'/'.uniqid('tally-export-', true).'.zip';

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("Failed to create ZIP file at {$zipPath}");
        }

        $zip->addFromString($xmlFilename, $xml);
        $zip->close();

        return $zipPath;
    }

    private function updateRunStatus(string $status, ?string $message = null): void
    {
        $this->scheduledExport->update([
            'last_run_at' => now(),
            'last_run_status' => $status,
            'last_run_message' => $message,
        ]);
    }
}
