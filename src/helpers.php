<?php

use Debug\Helpers;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Contracts\Filesystem\FileNotFoundException;

if (!function_exists('query_log')) {
    /**
     * @return Helpers\DbQuery
     */
    function query_log()
    {
        static $ready = false;
        $ready = !$ready;

        $ready && Event::listen(QueryExecuted::class, Helpers\DbQuery::class);
        /** @var Helpers\StackTrace $tracer */
        $tracer = tap(app(Helpers\StackTrace::class), function (Helpers\StackTrace $trace) {
            $trace::setTruncate();
        });
        $queryLog = app(Helpers\DbQuery::class, compact('tracer'));

        return $ready ? $queryLog->record() : $queryLog;
    }
}

if (!function_exists('dt')) {
    /**
     * @param mixed ...$args
     */
    function dt(...$args)
    {
        /** @var Helpers\StackTrace $trace */
        $trace = tap(app(Helpers\StackTrace::class)->setArgs(spread_args($args)), function (Helpers\StackTrace $trace) {
            $trace::setTruncate();
        });

        dd($trace->toArray());
    }
}

if (!function_exists('spread_args')) {
    /**
     * @param array $args
     * @return array
     */
    function spread_args(array $args) {
        return (count($args) == 1 && has_numeric_keys($args) && is_array($arg = current($args))) ? $arg : $args;
    }
}

if (!function_exists('lt')) {
    /**
     * @param mixed ...$args
     */
    function lt(...$args)
    {
        $trace = app(Helpers\StackTrace::class)->setArgs(spread_args($args));
        $trace::setTruncate();
        error_log(print_r($trace->toArray(), true));
//        app('log')->driver('json')->debug($trace->toJson());
    }
}

if (!function_exists('get_type')) {
    /**
     * @param mixed $thing
     * @return string
     */
    function get_type($thing)
    {
        return (($type = gettype($thing)) == 'object') ? get_class($thing) : $type;
    }
}

if (!function_exists('has_numeric_keys')) {
    /**
     * @param array $array
     * @return bool
     */
    function has_numeric_keys(array $array)
    {
        $keys = array_keys($array);
        sort($keys);
        $indexes = array_keys($keys);

        return $keys === $indexes;
    }
}

if (!function_exists('tests_path')) {
    /**
     * Get the path to the base of the install.
     *
     * @param  string  $path
     * @return string
     */
    function tests_path($path = '')
    {
        $dir = 'phpunit.xml';
        $dir = $path ? DIRECTORY_SEPARATOR.$path : $dir;
        $dir = base_path($dir);

        try {
            $phpUnitConfig = File::get($dir);
        } catch (FileNotFoundException $e) {
            $phpUnitConfig = '';
        }

        $items = xml_query($phpUnitConfig, '/phpunit/testsuites/testsuite/directory');

        $pathParts = collect($items)->map(function (DOMElement $item) {
            $fullPath = preg_replace('/^\./', base_path(), $item->nodeValue);
            return explode(DIRECTORY_SEPARATOR, $fullPath);
        });

        $testParent = array_intersect_assoc(...$pathParts);
        $testRootPath = $baseParts = explode(DIRECTORY_SEPARATOR, base_path());
        $testPath = array_diff_assoc($testParent, $baseParts);
        $testRootPath[] = current($testPath);
        $testRootPath = implode(DIRECTORY_SEPARATOR, $testRootPath);

        return $testRootPath;
    }
}

if (!function_exists('xml_query')) {
    /**
     * @param string $xml
     * @param string $query
     * @param string $version
     * @param string $encoding
     * @return DOMNodeList
     */
    function xml_query($xml, $query, $version = '1.0', $encoding = 'UTF-8')
    {
        /** @var DOMDocument $doc */
        $doc = tap(app(DOMDocument::class, compact('version', 'encoding')), function (DOMDocument $doc) use ($xml) {
            $doc->loadXML($xml);
        });

        $items = app(DOMXpath::class, compact('doc'))->query($query);
        $items = $items ?: app(DOMNodeList::class);

        return $items;
    }
}

if (!function_exists('rows_to_columns')) {
    /**
     * @param array[] $matrix
     * @param array $fields
     * @param bool $keepKeys
     * @return array
     */
    function rows_to_columns(array $matrix, array $fields = [], $keepKeys = false)
    {
        $keys = (!has_numeric_keys($matrix) || $keepKeys) ? array_keys($matrix) : [];
        $fields = $fields ?: array_keys((current($matrix) ?: []));
        $numResults = count($matrix);
        $matrix = array_pad($matrix, 2, array_fill_keys($fields, null)); //Even empty sets must result in array elements
        array_unshift(array_values($matrix), null);  //A callback of null is what triggers the tilt
        $matrix = array_map(...$matrix); //Sending null as the closure is what tilts the matrix
        $matrix = $fields ? array_combine($fields, $matrix) : [];
        array_walk($matrix, function (array &$column) use ($numResults, $keys) {
            $column = array_slice($column, 0, $numResults); //Prune off the placeholders, if any were needed
            $keys && $column = array_combine($keys, $column);
        });

        return $matrix;
    }
}
