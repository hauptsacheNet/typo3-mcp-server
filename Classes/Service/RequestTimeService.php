<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;

/**
 * Moves TYPO3's request time to the current wall-clock time.
 *
 * TYPO3 takes the request time once per PHP process, during bootstrap
 * (SystemEnvironmentBuilder sets EXEC_TIME, ACCESS_TIME, SIM_EXEC_TIME and
 * SIM_ACCESS_TIME; the Context derives its "date" aspect from EXEC_TIME).
 * That is right for a web request, but `mcp:server` serves a whole client
 * session from one process. Without a refresh, DataHandler stamps every
 * tstamp, crdate, sys_log and sys_history row with the process start time,
 * and starttime/endtime checks evaluate against that same frozen instant.
 *
 * Called once per tool call, before the tool runs, so each call behaves
 * like its own request.
 */
final class RequestTimeService
{
    public function __construct(
        private readonly Context $context,
    ) {}

    public function refresh(): void
    {
        $timestamp = time();

        // Same values and rounding as SystemEnvironmentBuilder: the access
        // times are rounded down to the minute
        $GLOBALS['EXEC_TIME'] = $timestamp;
        $GLOBALS['ACCESS_TIME'] = $timestamp - $timestamp % 60;
        $GLOBALS['SIM_EXEC_TIME'] = $GLOBALS['EXEC_TIME'];
        $GLOBALS['SIM_ACCESS_TIME'] = $GLOBALS['ACCESS_TIME'];

        // Whole seconds in the default timezone, as Context builds it from EXEC_TIME
        $date = (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $this->context->setAspect('date', new DateTimeAspect($date));
    }
}
