<?php

namespace App\Console\Commands;

use App\Jobs\SendScheduledTallyExport;
use App\Models\ScheduledTallyExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunScheduledTallyExports extends Command
{
    protected $signature = 'tally:run-scheduled-exports';

    protected $description = 'Check and dispatch due scheduled Tally XML exports';

    public function handle(): int
    {
        $schedules = ScheduledTallyExport::where('is_active', true)->get();

        $dispatched = 0;

        foreach ($schedules as $schedule) {
            if ($schedule->isDue()) {
                $schedule->update([
                    'last_run_at' => now(),
                    'last_run_status' => 'queued',
                ]);

                SendScheduledTallyExport::dispatch($schedule);
                $dispatched++;

                $this->info("Dispatched export for schedule #{$schedule->id} (company #{$schedule->company_id})");
            }
        }

        $message = "Checked {$schedules->count()} active schedules, dispatched {$dispatched} export(s).";
        $this->info($message);
        Log::info($message);

        return Command::SUCCESS;
    }
}
