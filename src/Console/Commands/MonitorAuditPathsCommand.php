<?php

namespace Drcantagalo\LaravelMonitor\Console\Commands;

use Drcantagalo\LaravelMonitor\Support\PathsAuditor;
use Illuminate\Console\Command;

class MonitorAuditPathsCommand extends Command
{
    protected $signature = 'monitor:audit-paths';

    protected $description = 'Audit monitor_paths (trap and safe) against the application\'s real routes and traffic, reporting rows that may collide with a live route';

    public function handle(): int
    {
        $findings = PathsAuditor::audit();

        if (empty($findings)) {
            $this->info('No collisions found — every reviewed path is clear.');

            return 0;
        }

        $this->table(
            ['Path', 'Status', 'Matched route', 'Source', 'Severity'],
            collect($findings)->map(fn ($finding) => [
                $finding['path'],
                $finding['status'],
                $finding['matched_route']['uri'],
                $finding['matched_route']['source'],
                $finding['severity'],
            ])->all()
        );

        $highSeverityCount = collect($findings)->where('severity', 'high')->count();

        if ($highSeverityCount > 0) {
            $this->warn("{$highSeverityCount} high-severity collision(s) found — these 'trap' paths may be blocking real users. Review with getPages/unflagPath.");
        }

        return 0;
    }
}
