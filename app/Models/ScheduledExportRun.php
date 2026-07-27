<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\ScheduledExportRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $company_id
 * @property int|null $scheduled_tally_export_id
 * @property string $status
 * @property int $transactions_count
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property array<int, string> $recipients
 * @property string|null $error_message
 * @property string $triggered_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Company $company
 * @property-read ScheduledTallyExport|null $scheduledExport
 */
class ScheduledExportRun extends Model
{
    /** @use HasFactory<ScheduledExportRunFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'scheduled_tally_export_id',
        'status',
        'transactions_count',
        'period_start',
        'period_end',
        'recipients',
        'error_message',
        'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'transactions_count' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'recipients' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<ScheduledTallyExport, $this>
     */
    public function scheduledExport(): BelongsTo
    {
        return $this->belongsTo(ScheduledTallyExport::class, 'scheduled_tally_export_id');
    }
}
