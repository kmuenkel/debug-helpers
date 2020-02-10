<?php

namespace App\Helpers;

use Monolog\Formatter\FormatterInterface;

/**
 * Class JsonFormatter
 * @package App\Helpers
 */
class RawJsonFormatter implements FormatterInterface
{
    /**
     * {@inheritDoc}
     */
    public function format(array $record)
    {
        return $record['message'].PHP_EOL;
    }

    /**
     * {@inheritDoc}
     */
    public function formatBatch(array $records)
    {
        $newRecords = [];
        foreach ($records as $record) {
            $newRecords = json_decode($this->format($record));
        }

        return json_encode($newRecords);
    }
}
