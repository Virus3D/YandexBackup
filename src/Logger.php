<?php

declare(strict_types=1);

namespace App\Backup;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class Logger implements LoggerInterface
{
    public function __construct(private string $logFile = 'backup.log')
    {
        error_reporting(E_ALL);
        ini_set('error_log', $this->logFile);
    }// end __construct()

    /**
     * @inheritDoc
     */
    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }// end emergency()

    /**
     * @inheritDoc
     */
    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }// end alert()

    /**
     * @inheritDoc
     */
    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }// end critical()

    /**
     * @inheritDoc
     */
    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }// end error()

    /**
     * @inheritDoc
     */
    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }// end warning()

    /**
     * @inheritDoc
     */
    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }// end notice()

    /**
     * @inheritDoc
     */
    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }// end info()

    /**
     * @inheritDoc
     */
    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }// end debug()

    /**
     * Conflict conditions.
     *
     * @param string[] $context
     */
    public function conflict(string $message, array $context = []): void
    {
        $this->log('CONFLICT', $message, $context);
    }// end conflict()

    /**
     * Exception conditions.
     *
     * @param string[] $context
     */
    public function exception(string $message, array $context = []): void
    {
        $this->log('EXCEPTION', $message, $context);
    }// end exception()

    /**
     * @inheritDoc
     */
    public function log($level, $message, array $context = [])
    {
        $pid = function_exists('getmypid') ? getmypid() : 'N/A';
        $contextStr = $context ? '. Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        error_log("({$pid}) [{$level}] {$message}{$contextStr}", 0);
    }// end log()
}// end class
