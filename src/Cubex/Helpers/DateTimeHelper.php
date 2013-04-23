<?php
/**
 * @author  richard.gooding
 */

namespace Cubex\Helpers;

class DateTimeHelper
{
  public static function secondsToTime(
    $secs,
    $alwaysShowHours = false,
    $alwaysShowMins = true
  )
  {
    $secs  = round($secs);
    $hours = floor($secs / 3600);
    $secs -= $hours * 3600;
    $mins = floor($secs / 60);
    $secs -= $mins * 60;

    $formatString = "";
    $params = [];
    if($alwaysShowHours || ($hours > 0))
    {
      $formatString .= "%d:";
      $params[] = $hours;
    }
    if($alwaysShowMins || ($mins > 0))
    {
      $formatString .= "%02d:";
      $params[] = $mins;
    }
    $formatString .= "%02d";
    $params[] = $secs;

    return vsprintf($formatString, $params);
  }
}