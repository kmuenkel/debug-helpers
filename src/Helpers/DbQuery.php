<?php

namespace Debug\Helpers;

use PDO;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Expression;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Class DbQuery
 * @package App\Helpers
 */
class DbQuery implements Arrayable, Jsonable
{
    /**
     * @var StackTrace
     */
    protected $tracer;

    /**
     * @var bool
     */
    protected static $recording = false;

    /**
     * @var array
     */
    protected static $queries = [];

    /**
     * @var StackTrace[]
     */
    protected static $traces = [];

    /**
     * DbQuery constructor.
     * @param StackTrace|null $tracer
     */
    public function __construct(StackTrace $tracer = null)
    {
        $this->tracer = $tracer ?: app(StackTrace::class);
    }

    /**
     * @param QueryExecuted $query
     */
    public function handle(QueryExecuted $query)
    {
        if (self::$recording) {
            $config = config('database.connections.' . $query->connectionName);
            $config = Arr::only($config, ['driver', 'host', 'port', 'username']);
            $database = $query->connection->getDatabaseName();
            $time = round($query->time / 1000, 2);

            $sql = $this->compileQuery($query);
            $source = ($config['driver'] ?? '') . ':'
                . ($config['username'] ?? '') . '@'
                . ($config['host'] ?? '') . ':'
                . ($config['port'] ?? '');
            $sql = "USE $database;".PHP_EOL.$sql;
            $args = compact('source', 'sql', 'time');
            $tracer = (clone $this->tracer)->setArgs($args);

            self::$queries[] = $args;
            self::$traces[] = $tracer;
        }
    }

    /**
     * @return array
     */
    public function dump()
    {
        $queries = self::$queries;
        self::$queries = [];

        return $queries;
    }

    /**
     * @return $this
     */
    public function record()
    {
        self::$recording = true;
        self::$traces = self::$queries = [];

        return $this;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $traces = [];

        foreach (self::$traces as $trace) {
            $traces[] = $trace->toArray();
        }

        return array_values(array_reverse($traces));
    }

    /**
     * @param int $options
     * @return false|string
     */
    public function toJson($options = 0)
    {
        $traces = $this->toArray();

        return json_encode($traces, $options);
    }

    /**
     * @return false|string
     */
    public function __toString()
    {
        return $this->toJson();
    }

    /**
     * @param string|EloquentBuilder|Expression $sql
     * @param array $values
     * @param PDO|null $pdo
     * @return string
     */
    public function compileQuery($sql, array $values = [], PDO $pdo = null)
    {
        $connection = DB::connection();
        $pdo = $pdo ?: $connection->getPdo();
        $driverName = $connection->getDriverName();

        if (is_object($sql)) {
            if ($sql instanceof EloquentBuilder || $sql instanceof QueryBuilder) {
                $values = $sql->getBindings();
                $sql = $sql->toSql();
            } elseif ($sql instanceof QueryExecuted) {
                $pdo = $sql->connection->getPdo();
                $values = $sql->bindings;
                $sql = $sql->sql;
            } elseif ($sql instanceof QueryException) {
                $values = $sql->getBindings();
                $sql = $sql->getSql();
            }
        }

        $enclosures = [
            'back_tick' => ($driverName == 'sqlite') ? '"' : '`',
            'apostrophe' => "'"
        ];

        $matches = [];
        foreach ($enclosures as $name => $enclosure) {
            $matches[$name] = [];
            preg_match_all("/$enclosure.*?$enclosure/", $sql, $matches[$name]);
            $matches[$name] = Arr::last($matches[$name]);
            $sql = preg_replace("/$enclosure.*?$enclosure/", "$enclosure?$enclosure", $sql);
        }

        $sql = strtoupper($sql);

        foreach ($enclosures as $name => $enclosure) {
            $sql = Str::replaceArray("$enclosure?$enclosure", $matches[$name], $sql);
        }

        $values = array_map(function ($value) use ($pdo) {
            if (!is_numeric($value) && !is_null($value)) {
                /** @var PDO $value */
                $value = $pdo->quote($value);
            }

            return $value;
        }, $values);

        $sql = str_replace(' AND 1', '', $sql);
        $sql = str_replace('WHERE 1 AND ', 'WHERE ', $sql);

        $sql = Str::replaceArray('?', $values, $sql);
        $sql = preg_replace('/\s+/', ' ', $sql);
//        $sql = preg_replace('/(` AS `)(.+?)(`)/', "$1'$2'", $sql);
//        $sql = str_replace('`', '', $sql);
//        $sql = preg_replace("/( AS )(')(.+?)(')/", "$1`$3`", $sql);
        $sql = rtrim($sql, ';').';';
        #$sql .= '# '.$query['time'].'ms';

        return $sql;
    }
}
