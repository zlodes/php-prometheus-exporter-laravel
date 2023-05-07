<?php

declare(strict_types=1);

namespace Zlodes\PrometheusExporter\Laravel\Commands;

use Illuminate\Console\Command;
use JsonException;
use Zlodes\PrometheusExporter\Registry\Registry;

final class ListMetrics extends Command
{
    protected $signature = 'metrics:list';
    protected $description = 'Outputs a table with all the registered metrics';

    /**
     * @throws JsonException
     */
    public function handle(Registry $registry): void
    {
        $metrics = [];
        $counter = 0;

        foreach ($registry->getAll() as $metric) {
            ++$counter;

            $metrics[] = [
                $counter,
                $metric->getName(),
                $metric->getType()->value,
                json_encode($metric->getInitialLabels(), JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
            ];
        }

        $tableHeader = [
            '#',
            'Name',
            'Type',
            'Initial tags',
        ];

        $this->table($tableHeader, $metrics);
    }
}
