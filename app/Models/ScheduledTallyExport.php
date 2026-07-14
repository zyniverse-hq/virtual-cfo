<?php

namespace App\Models;

use App\Enums\DateRangeWindow;
use App\Enums\ExportFrequency;
use App\Enums\StatementType;
use Database\Factories\ScheduledTallyExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $company_id
 * @property ExportFrequency $frequency
 * @property int|null $day_of_week
 * @property int|null $day_of_month
 * @property string $time_of_day
 * @property DateRangeWindow $date_range_window
 * @property \Carbon\Carbon|null $last_run_at
 * @property string|null $last_run_status
 * @property string|null $last_run_message
 */
class ScheduledTallyExport extends Model
{
    /** @use HasFactory<ScheduledTallyExportFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'created_by',
        'frequency',
        'day_of_week',
        'day_of_month',
        'time_of_day',
        'timezone',
        'date_range_window',
        'statement_type',
        'recipient_emails',
        'is_active',
        'last_run_at',
        'last_run_status',
        'last_run_message',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => ExportFrequency::class,
            'date_range_window' => DateRangeWindow::class,
            'statement_type' => StatementType::class,
            'recipient_emails' => 'array',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'frequency', 'day_of_week', 'day_of_month',
                'time_of_day', 'timezone', 'date_range_window',
                'statement_type', 'recipient_emails', 'is_active',
                'last_run_at', 'last_run_status',
            ])
            ->logOnlyDirty()
            ->useLogName('scheduled-tally-exports');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ScheduledExportRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledExportRun::class);
    }

    /**
     * Check if this schedule is due to run now.
     *
     * Uses a 0–4 minute tolerance window to prevent missed runs
     * from slight scheduler jitter (command runs everyMinute).
     */
    public function isDue(?Carbon $now = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = ($now ?? Carbon::now())->copy()->setTimezone($this->timezone);
        $scheduleTime = Carbon::parse($this->time_of_day, $this->timezone);
        $scheduleHour = $scheduleTime->hour;
        $scheduleMinute = $scheduleTime->minute;

        // Check if we're within 5 minutes of the scheduled time
        $scheduledMoment = $now->copy()->setTime($scheduleHour, $scheduleMinute, 0);
        $diffInMinutes = $now->diffInMinutes($scheduledMoment, false);

        // Must be within 0-4 minutes after the scheduled time
        if ($diffInMinutes > 0 || $diffInMinutes < -4) {
            return false;
        }

        // Prevent double-runs: skip if last run was within the last hour
        if ($this->last_run_at && $this->last_run_at->diffInMinutes($now) < 60) {
            return false;
        }

        return match ($this->frequency) {
            ExportFrequency::Daily => true,
            ExportFrequency::Weekly => $now->dayOfWeek === $this->day_of_week,
            ExportFrequency::Monthly => $now->day === $this->day_of_month,
        };
    }

    /**
     * Resolve the configured date range window to concrete dates.
     *
     * @return array{Carbon, Carbon}
     */
    public function resolvedDateRange(?Carbon $referenceDate = null): array
    {
        return $this->date_range_window->toDateRange($referenceDate);
    }

    /**
     * Get a human-readable schedule description.
     */
    public function getScheduleDescriptionAttribute(): string
    {
        $time = Carbon::parse($this->time_of_day)->format('h:i A');

        return match ($this->frequency) {
            ExportFrequency::Daily => "Daily at {$time}",
            ExportFrequency::Weekly => 'Every '.Carbon::create()->next($this->day_of_week)->dayName." at {$time}",
            ExportFrequency::Monthly => "Monthly on day {$this->day_of_month} at {$time}",
        };
    }
}
