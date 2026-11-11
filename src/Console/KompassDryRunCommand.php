<?php

declare(strict_types=1);

namespace Ivanange\Billing\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Ivanange\Billing\Migration\KompassInspector;

/**
 * Dry run only: reads a Kompass database and prints what moving its
 * billing into this package would create. Nothing is written. The import
 * itself is planned in docs/KOMPASS_MIGRATION.md.
 */
class KompassDryRunCommand extends Command
{
    protected $signature = 'billing:kompass-dry-run {--connection= : Database connection that holds the Kompass tables}';

    protected $description = 'Report how Kompass billing data would map onto this package (read-only)';

    public function handle(DatabaseManager $databases): int
    {
        $connection = $databases->connection($this->option('connection') ?: null);
        $report = (new KompassInspector($connection))->report();

        $this->table(['Kompass driver', 'Type', 'Active', 'Deleted', 'Becomes'], array_map(fn (array $d): array => [
            "#{$d['id']} ".$this->clean($d['name']), $this->clean($d['type']), $d['active'] ? 'yes' : 'no', $d['deleted'] ? 'yes' : 'no',
            $d['provider'] ?? 'not migrated (no provider yet)',
        ], $report['drivers']));

        $this->table(['Item', 'Count'], [
            ['Subscriptions', $report['subscriptions']],
            ['… without payer (payer = beneficiary)', $report['subscriptions_without_payer']],
            ...array_map(fn (string $status, int $n): array => ["Cycles {$status}", $n], array_keys($report['cycles']), $report['cycles']),
            ['Payments to create (paid cycles)', $report['payments_to_create']],
            ['Overlapping cycles', $report['overlapping_cycles']],
        ]);

        $this->line('Plans to create: '.implode(', ', $report['plans_to_create'] ?: ['none']));
        $this->line('Currencies: '.implode(', ', $report['currencies'] ?: ['none']));

        foreach ($report['duplicates_active'] as $type) {
            $this->warn("Several active [{$type}] drivers: only one stays active after migration.");
        }

        $this->info('Dry run: nothing was written.');

        return self::SUCCESS;
    }

    /** Values come from another app's database: strip control characters before printing. */
    private function clean(string $value): string
    {
        return (string) preg_replace('/[[:cntrl:]]/', '', $value);
    }
}
