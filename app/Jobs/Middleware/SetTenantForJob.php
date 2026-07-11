<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;

class SetTenantForJob
{
    /**
     * Set the PostgreSQL session variable for RLS before job execution.
     */
    public function handle(object $job, Closure $next): void
    {
        $companyId = $this->resolveCompanyId($job);

        try {
            if ($companyId) {
                DB::statement("SET app.current_company_id = '{$companyId}'");
            }

            $next($job);
        } finally {
            DB::statement("SET app.current_company_id = ''");
        }
    }

    /**
     * Resolve the company ID from the job's properties.
     */
    protected function resolveCompanyId(object $job): ?int
    {
        if (isset($job->importedFile)) {
            return $job->importedFile->company_id;
        }

        if (isset($job->bankFile)) {
            return $job->bankFile->company_id;
        }

        if (isset($job->scheduledExport)) {
            return $job->scheduledExport->company_id;
        }

        return null;
    }
}
