<?php

/*
The MIT License (MIT)

Copyright (c) 2018 Databases and Information Systems Research Group,
University of Basel, Switzerland

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
 */

// Include PHP Framework Interop Group Interfaces for Logger (PSR-3)
include_once('include/Psr/Log/AbstractLogger.php');
include_once('include/Psr/Log/LogLevel.php');

/**
 * A light, permissions-checking logging class.
 * Originally written from Kenny Katzgrau <katzgrau@gmail.com> for use with wpSearch
 *
 * Usage:
 * $log = new Logger_Library('/var/log/', LogLevel::INFO);
 * $log->info('Returned a million search results'); //Prints to the log file
 * $log->error('Oh dear.'); //Prints to the log file
 * $log->debug('x = 5'); //Prints nothing due to current severity threshold
 */
class Logger_Library extends AbstractLogger {

    /**
     * Logger options
     * Anything options not considered 'core' to the logging library should be
     * settable view the third parameter in the constructor
     *
     * Core options include the log file path and the log threshold
     *
     * @var array
     */
    private $options = [
        'extension' => 'txt',
        'dateFormat' => 'Y-m-d G:i:s.u',
        'filename' => false,
        'flushFrequency' => false,
        'prefix' => 'log_',
        'logFormat' => false,
        'appendContext' => true,
    ];


    /**
     * Path to the log file
     * @var string
     */
    private $logFilePath;


    /**
     * Current minimum logging threshold
     * @var integer
     */
    private $logLevelThreshold = LogLevel::DEBUG;


    /**
     * The number of lines logged in this instance's lifetime
     * @var int
     */
    private $logLineCount = 0;


    /**
     * Log Levels
     * @var array
     */
    private $logLevels = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT => 1,
        LogLevel::CRITICAL => 2,
        LogLevel::ERROR => 3,
        LogLevel::WARNING => 4,
        LogLevel::NOTICE => 5,
        LogLevel::INFO => 6,
        LogLevel::DEBUG => 7
    ];


    /**
     * This holds the file handle for this instance's log file
     * @var resource
     */
    private $fileHandle;


    /**
     * This holds the last line logged to the logger
     *  Used for unit tests
     * @var string
     */
    private $lastLine = '';


    /**
     * Octal notation for default permissions of the log file
     * @var integer
     */
    private $defaultPermissions = 0777;


    /**
     * Holds instance for Singleton-Pattern
     */
    static private $instance = null;


    /**
     * Singelton
     */
    static public function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self(LOG_DIRECTORY);
        }
        return self::$instance;
    }


    /**
     * Class constructor
     *
     * @param string $logDirectory File path to the logging directory
     * @param array $options
     *
     * @internal param string $logLevelThreshold The LogLevel Threshold
     * @internal param string $logFilePrefix The prefix for the log file name
     * @internal param string $logFileExt The extension for the log file
     */
    public function __construct($logDirectory, array $options = []) {
        $this->options = array_merge($this->options, $options);
        $logDirectory = rtrim($logDirectory, DIRECTORY_SEPARATOR);
        if (!file_exists($logDirectory)) {
            mkdir($logDirectory, $this->defaultPermissions, true);
        }
        if ($logDirectory === "php://stdout" || $logDirectory === "php://output") {
            $this->setLogToStdOut($logDirectory);
            $this->setFileHandle('w+');
        } else {
            $this->setLogFilePath($logDirectory);
            if (file_exists($this->logFilePath) && !is_writable($this->logFilePath)) {
                throw new RuntimeException('The file could not be written to. Check that appropriate permissions have been set.');
            }
            $this->setFileHandle('a');
        }
        if (!$this->fileHandle) {
            throw new RuntimeException('The file could not be opened. Check permissions.');
        }
    }


    /**
     * @param string $stdOutPath
     */
    public function setLogToStdOut($stdOutPath) {
        $this->logFilePath = $stdOutPath;
    }


    /**
     * @param string $logDirectory
     */
    public function setLogFilePath($logDirectory) {
        if ($this->options['filename']) {
            if (strpos($this->options['filename'], '.log') !== false || strpos($this->options['filename'], '.txt') !== false) {
                $this->logFilePath = $logDirectory . DIRECTORY_SEPARATOR . $this->options['filename'];
            } else {
                $this->logFilePath = $logDirectory . DIRECTORY_SEPARATOR . $this->options['filename'] . '.' . $this->options['extension'];
            }
        } else {
            $this->logFilePath = $logDirectory . DIRECTORY_SEPARATOR . $this->options['prefix'] . date('Y-m-d') . '.' . $this->options['extension'];
        }
    }


    /**
     * @param $writeMode
     *
     * @internal param resource $fileHandle
     */
    public function setFileHandle($writeMode) {
        $this->fileHandle = fopen($this->logFilePath, $writeMode);
    }


    /**
     * Class destructor
     */
    public function __destruct() {
        if ($this->fileHandle) {
            fclose($this->fileHandle);
        }
    }


    /**
     * Sets the date format used by all instances of Logger
     *
     * @param string $dateFormat Valid format string for date()
     */
    public function setDateFormat($dateFormat) {
        $this->options['dateFormat'] = $dateFormat;
    }


    /**
     * Sets the Log Level Threshold
     *
     * @param string $logLevelThreshold The log level threshold
     */
    public function setLogLevelThreshold($logLevelThreshold) {
        $this->logLevelThreshold = $logLevelThreshold;
    }


    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @throws Exception
     */
    public function log($level, $message, array $context = []) {
        if ($this->logLevels[$this->logLevelThreshold] < $this->logLevels[$level]) {
            return;
        }
        $message = $this->formatMessage($level, $message, $context);
        $this->write($message);
    }


    /**
     * Writes a line to the log without prepending a status or timestamp
     *
     * @param string $message Line to write to the log
     */
    public function write($message) {
        if (null !== $this->fileHandle) {
            if (fwrite($this->fileHandle, $message) === false) {
                throw new RuntimeException('The file could not be written to. Check that appropriate permissions have been set.');
            } else {
                $this->lastLine = trim($message);
                $this->logLineCount++;
                if ($this->options['flushFrequency'] && $this->logLineCount % $this->options['flushFrequency'] === 0) {
                    fflush($this->fileHandle);
                }
            }
        }
    }


    /**
     * Get the file path that the log is currently writing to
     *
     * @return string
     */
    public function getLogFilePath() {
        return $this->logFilePath;
    }


    /**
     * Get the last line logged to the log file
     *
     * @return string
     */
    public function getLastLogLine() {
        return $this->lastLine;
    }


    /**
     * Formats the message for logging.
     *
     * @param string $level The Log Level of the message
     * @param string $message The message to log
     * @param array $context The context
     *
     * @return string
     * @throws Exception
     */
    private function formatMessage($level, $message, $context) {
        if ($this->options['logFormat']) {
            $parts = [
                'date' => $this->getTimestamp(),
                'level' => strtoupper($level),
                'priority' => $this->logLevels[$level],
                'message' => $message,
                'context' => json_encode($context),
            ];
            $message = $this->options['logFormat'];
            foreach ($parts as $part => $value) {
                $message = str_replace('{' . $part . '}', $value, $message);
            }
        } else {
            $message = "[{$this->getTimestamp()}] [{$level}] {$message}";
        }
        if ($this->options['appendContext'] && !empty($context)) {
            $message .= PHP_EOL . $this->indent($this->contextToString($context));
        }
        return $message . PHP_EOL;
    }


    /**
     * Gets the correctly formatted Date/Time for the log entry.
     *
     * PHP DateTime is dump, and you have to resort to trickery to get microseconds
     * to work correctly, so here it is.
     *
     * @return string
     * @throws Exception
     */
    private function getTimestamp() {
        $originalTime = microtime(true);
        $micro = sprintf("%06d", ($originalTime - floor($originalTime)) * 1000000);
        $date = new DateTime(date('Y-m-d H:i:s.' . $micro, intval($originalTime)));
        return $date->format($this->options['dateFormat']);
    }


    /**
     * Takes the given context and coverts it to a string.
     *
     * @param array $context The Context
     *
     * @return string
     */
    private function contextToString($context) {
        $export = '';
        foreach ($context as $key => $value) {
            $export .= "{$key}: ";
            $export .= preg_replace([
                '/=>\s+([a-zA-Z])/im',
                '/array\(\s+\)/im',
                '/^  |\G  /m'
            ], [
                '=> $1',
                'array()',
                '    '
            ], str_replace('array (', 'array(', var_export($value, true)));
            $export .= PHP_EOL;
        }
        return str_replace(['\\\\', '\\\''], ['\\', '\''], rtrim($export));
    }


    /**
     * Indents the given string with the given indent.
     *
     * @param string $string The string to indent
     * @param string $indent What to use as the indent.
     *
     * @return string
     */
    private function indent($string, $indent = '    ') {
        return $indent . str_replace("\n", "\n" . $indent, $string);
    }
}