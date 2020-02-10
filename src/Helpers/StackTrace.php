<?php

namespace App\Helpers;

use Exception;
use ReflectionMethod;
use ReflectionFunction;
use ReflectionParameter;
use ReflectionException;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Class StackTrace
 * @package App\Helpers
 */
class StackTrace implements Arrayable, Jsonable
{
    const TRUNCATED = '...';

    /**
     * @var bool
     */
    protected static $showAll = false;

    /**
     * @var bool
     */
    protected static $truncate = false;

    /**
     * @var array
     */
    protected $args = [];

    /**
     * @var array[]
     */
    protected $content = [];

    /**
     * @var array
     */
    protected $workingPaths = [];

    /**
     * StackTrace constructor.
     * @param array $args
     */
    public function __construct(array $args = [])
    {
        $this->setArgs($args);

        $this->workingPaths = [
            app_path(),
            tests_path(),
            database_path()
        ];
    }

    /**
     * @param array $args
     * @return $this
     */
    public function setArgs(array $args = [])
    {
        $this->args = $args;
        $this->refresh();

        return $this;
    }

    /**
     * @param bool $showAll
     */
    public static function setShowAll($showAll = true)
    {
        self::$showAll = $showAll;
    }

    /**
     * @param bool $truncate
     */
    public static function setTruncate($truncate = true)
    {
        self::$truncate = $truncate;
    }

    /**
     * @return array
     */
    public function getTrace()
    {
        $backtrace = debug_backtrace();

        $lines = [];
        /** @var array $trace */
        foreach ($backtrace as $trace) {
            //Handle the fact that not all desired fields will be present in each backtrace record.
            $fields = ['file', 'line', 'function', 'class', 'args'];
            $trace = array_intersect_key($trace, array_flip($fields));
            $trace = array_merge(array_fill_keys($fields, ''), $trace);

            //Present the arguments in a truncated fashion
            $trace['args'] = $trace['args'] ? array_map([$this, 'normalizeArgs'], $trace['args']) : [];
            if ($trace['function']) {
                $function = $trace['class'] ? [$trace['class'], $trace['function']] : $trace['function'];
                $trace['args'] = $this->applyParameterNames($trace['args'], $function);
            }

            //Get the file and line number for closure calls
            if (!$trace['file'] || !$trace['line']) {
                try {
                    $reflection = $trace['class'] ? new ReflectionMethod($trace['class'], $trace['function'])
                        : new ReflectionFunction($trace['function']);
                    $trace['file'] = $trace['file'] ?: $reflection->getFileName();
                    $trace['line'] = $trace['line'] ?: $reflection->getStartLine();
                } catch (ReflectionException $e) {
                    $trace['file'] = $e->getMessage();
                    $trace['line'] = uniqid();
                }
            }

            //String the backtrace values together in an easier-to-read fashion
            $line = $trace['file'] . ':' . $trace['line'];
            $function = trim($trace['class'] . '::' . $trace['function'], ':');

            $lines[$line] = [$function => $trace['args']];
        }

        return $lines;
    }

    /**
     * @param array $args
     * @param string|array $function
     * @param bool $includeDefaults
     * @return array
     */
    protected function applyParameterNames(array $args, $function, $includeDefaults = true)
    {
        [$parameterNames, $defaults] = $this->getParameterDefinitions($function);

        $args = $includeDefaults ? $args + array_values($defaults) : $args;
        $args = array_pad($args, count($parameterNames), null);
        $argNames = $parameterNames + array_keys($args);
        $args = !empty($args) && !empty($argNames) ? array_combine($argNames, $args) : $args;

        return $args;
    }

    /**
     * @param string|string[] $function
     * @return array
     */
    public function getParameterDefinitions($function)
    {
        list($class, $function) = array_pad((array)$function, -2, null);

        $defaults = $parameterNames = [];

        try {
            $reflection = $class ? new ReflectionMethod($class, $function) : new ReflectionFunction($function);

            /** @var ReflectionParameter $param */
            foreach ($reflection->getParameters() as $param) {
                if ($param->isDefaultValueAvailable()) {
                    $defaults[$param->name] = $param->getDefaultValue();
                }

                $parameterNames[] = $param->name;

            }
        } catch (ReflectionException $e) {
            //
        }

        return [$parameterNames, $defaults];
    }

    /**
     * @param mixed $arg
     * @return string
     */
    protected function normalizeArgs($arg)
    {
        switch (gettype($arg)) {
            case 'object':
                $arg = get_class($arg);

                break;
            case 'resource':
                $arg = get_resource_type($arg) ?: 'resource';

                break;
            case 'array':
                $arg = $this->normalizeArrays($arg);

                break;
        }

        return $arg;
    }

    /**
     * @param array $arg
     * @return array
     */
    protected function normalizeArrays(array $arg)
    {
        foreach ($arg as $index => $elm) {
            $arg[$index] = $this->normalizeArgs($elm);
        }

        return $arg;
    }

    /**
     * @param mixed $arg
     * @return string
     */
    protected function stringifyArgs($arg)
    {
        $truncateAt = 16;

        switch (gettype($arg)) {
            case 'string':
                if (strlen($arg) > $truncateAt) {
                    $arg = substr($arg, 0, $truncateAt) . '...';
                }
                $arg = '"' . $arg . '"';

                break;
            case 'array':
                $arg = 'array(' . count($arg) . ')';

                break;
            case 'boolean':
                $arg = $arg ? 'true' : 'false';

                break;
            case 'NULL':
                $arg = 'null';

                break;
        }

        return $arg;
    }

    /**
     * @param array $trace
     * @return array
     */
    protected function flatten(array $trace)
    {
        /**
         * @var string $line
         * @var array $args
         */
        foreach ($trace as $line => $args) {
            $function = key($args);
            $args = current($args);

            foreach ($args as $param => $arg) {
                $args[$param] = $param . ':' . $this->stringifyArgs($arg);
            }

            $args = implode(', ', $args);
            $function .= $function != self::TRUNCATED ? '(' . $args . ')' : '';

            $trace[$line] = $function;
        }

        return $trace;
    }

    /**
     * @param mixed $arg
     * @return bool
     */
    protected function isException($arg)
    {
        return is_object($arg) && $arg instanceof Exception;
    }

    /**
     * @return $this
     */
    public function refresh()
    {
        if (!empty($this->args) && $this->isException($err = current($this->args))) {
            /** @var Exception $err */
            $key = key($this->args);
            $this->args[$key] = get_class($err) . ': "' . $err->getMessage() . '"';
        }

        $this->content = [
            'debug' => $this->args,
            'trace' => $this->getTrace()
        ];

        $this->content = $this->stripSelf($this->content);

        return $this;
    }

    /**
     * @return array[]
     */
    public function get()
    {
        !$this->content && $this->refresh();

        return $this->content;
    }

    /**
     * @param array $content
     * @return array
     */
    protected function filterApp(array $content)
    {
        $hidden = $trace = [];
        $positions = array_flip(array_keys($content['trace']));

        //If cordoned-off lines have been identified, apply them under a nested collection
        $applyHidden = function ($trace) use (&$hidden) {
            if (!empty($hidden)) {
                $lines = array_column($hidden, 'line');
                $function = array_column($hidden, 'function');
                $hiddenContent = array_combine($lines, $function);

                $positions = array_keys($hidden);
                $index = $from = current($positions);
                ($to = last($positions)) != $from && $index .= "-$to";

                $hidden = [];

                //If the first position is 0, the trace was called from a hidden place and should be included
                if (!$from) {
                    $trace = array_merge($hiddenContent, $trace);

                    return $trace;
                }

                $trace[$index] = [self::TRUNCATED => $hiddenContent];
            }

            return $trace;
        };

        /**
         * @var string $line
         * @var array $function
         */
        foreach ($content['trace'] as $line => $function) {
            $directory = current(explode(':', $line));

            //Cordon off all lines that are not in the workingPath list
            $delimiters = array_pad([], count($this->workingPaths), '/');
            $pattern = '^('.implode('|', array_map('preg_quote', $this->workingPaths, $delimiters)).')';
            if (!preg_match("/$pattern/", $directory)) {
                $hidden[$positions[$line]] = compact('line', 'function');

                continue;
            }

            $trace = $applyHidden($trace);
            $trace[$line] = $function;
        }

        $content['trace'] = $applyHidden($trace);

        return $content;
    }

    /**
     * @param array[] $content
     * @return array[]
     */
    protected function truncate(array $content)
    {
        foreach ($content as $line => $method) {
            if ($method == self::TRUNCATED) {
                $content[$line] = [];
            }
        }

        $content['trace'] = $this->flatten($content['trace']);

        return $content;
    }

    /**
     * @return array|array[]
     */
    public function toArray()
    {
        $content = $this->get();
        $content = !self::$showAll ? $this->filterApp($content) : $content;
        $content = self::$truncate ? $this->truncate($content) : $content;

        foreach ($content['debug'] as $index => $trace) {
            $method = last(explode('::', __METHOD__));
            if (is_object($trace) && method_exists($trace, $method)) {
                $content['debug'][$index] = $trace->$method();
            }
        }

        return $content;
    }

    /**
     * @param int $options
     * @return false|string
     */
    public function toJson($options = 0)
    {
        $content = $this->toArray();
        $content = json_encode($content, $options);

        return $content;
    }

    /**
     * @return false|string
     */
    public function __toString()
    {
        return $this->toJson();
    }

    /**
     * @param array $content
     * @return array
     */
    protected function stripSelf(array $content)
    {
        $callToSelf = [];

        /**
         * @var string $index
         * @var array $line
         */
        foreach ($content['trace'] as $index => $line) {
            $function = key($line);
            $called = explode('::', $function);

            if (current($called) == __CLASS__) {
                $callToSelf = [$index => $line];
                unset($content['trace'][$index]);
            }
        }

//        $content['trace'] = array_merge($callToSelf, $content['trace']);

        return $content;
    }
}
