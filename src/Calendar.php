<?php

namespace Drupal\elereg;


use Drupal;
use DateInterval;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\node\Entity\Node;

/**
 * Service description.
 */
class Calendar
{

    const SECONDS_IN_DAY = 86400;

    const SECONDS_IN_WEEK = 604800;

    private array $settings;

    private array $busyTickets;

    private array $specialDays;

    public function __construct()
    {
        $this->settings = Drupal::config('elereg.settings')->getRawData();
    }

    private function generateDay(string $day, string $genHours = ''): array
    {
        $ret = [];
        $formatYmd = 'Y-m-d';
        $formatHis = 'H:i:s';
        $beginDay = DrupalDateTime::createFromTimestamp($day)->format($formatYmd);
        $formatYmdHis = "$formatYmd $formatHis";
        $begin = DrupalDateTime::createFromFormat($formatYmdHis, $beginDay . ' ' . $this->settings['work_from'])->getTimestamp();
        if ($genHours) {
            $end = DrupalDateTime::createFromFormat($formatYmdHis, $beginDay . ' ' . $genHours)->getTimestamp();
        } else {
            if (date('w', $day) == 5) {
                $end = DrupalDateTime::createFromFormat($formatYmdHis, $beginDay . ' ' . $this->settings['work_end_friday'])->getTimestamp();
            } else {
                $end = DrupalDateTime::createFromFormat($formatYmdHis, $beginDay . ' ' . $this->settings['work_end'])->getTimestamp();
            }
        }
        $curTime = time();
        $lunchFrom = str_replace(':', '', $this->settings['lunch_from']);
        $lunchTo = str_replace(':', '', $this->settings['lunch_end']);
        for ($timeStamp = $begin; $timeStamp < $end; $timeStamp += ($this->settings['interval'] * 60)) {
            $time = DrupalDateTime::createFromFormat($formatYmdHis, $beginDay . ' ' . DrupalDateTime::createFromTimestamp($timeStamp)->format($formatHis));
            $_time = $time->format('Hi') . '00';
            if (($_time >= $lunchFrom) && ($_time <= $lunchTo)) {
                continue;
            }
            $tmp['t'] = $time->format('H:i');
            $tmp['s'] = $curTime - 10 < $time->getTimestamp();
            if (isset($this->busyTickets[$time->getTimestamp()])) {
                $tmp['s'] = false;
            }
            $ret[] = $tmp;
        }
        return $ret;
    }

    /**
     * @param $day
     *
     * @return array
     */
    private function generateWeek($day): array
    {
        $ret = [];
        if (intval(date('w')) == 5) {
            $endTime = $this->settings['work_end_friday'];
        } else {
            $endTime = $this->settings['work_end'];
        }
        $cDayEnd = DrupalDateTime::createFromFormat('Y-m-d H:i:s', date('Y-m-d') . ' ' . $endTime);
        $curDay = intval(date('w', $day)) - 1;
        $firstWeekDay = strtotime('-' . $curDay . ' days', $day);
        $lastWeekDay = strtotime('+' . (6 - $curDay) . ' days', $day);
        $curTime = DrupalDateTime::createFromTimestamp(time());
        for ($_dayOfWeek = $firstWeekDay; $_dayOfWeek <= $lastWeekDay; $_dayOfWeek += self::SECONDS_IN_DAY) {
            $dayOfWeek = DrupalDateTime::createFromTimestamp($_dayOfWeek);
            $tmp['d'] = $dayOfWeek->format('d.m.Y');
            $tmp['w'] = $dayOfWeek->format('l');
            $tmp['s'] = $curTime->getTimestamp() <= $dayOfWeek->getTimestamp();
            if (($curTime->format('Y-m-d') == $dayOfWeek->format('Y-m-d')) && ($curTime->getTimestamp() > $cDayEnd->getTimestamp())) {
                $tmp['s'] = false;
            }
            if (($dayOfWeek->format('w') > 5) || ($dayOfWeek->format('w') < 1)) {
                $tmp['s'] = false;
            }
            $genHours = '';
            if (isset($this->specialDays[$tmp['d']])) {
                $tmp['s'] = true;
                if ($this->specialDays[$tmp['d']]) {
                    $genHours = $this->specialDays[$tmp['d']];
                } else {
                    $tmp['s'] = false;
                }
            }
            if ($tmp['s']) {
                $tmp['h'] = $this->generateDay($dayOfWeek->getTimestamp(), $genHours);
            }
            $ret[] = $tmp;
        }
        return $ret;
    }

    /**
     * @return array
     */
    public function generateMonth(): array
    {
        //        if (!$this->settings['debug']) {
        //            $cache = Drupal::cache()->get(__CLASS__ . ':' . __FUNCTION__);
        //            if (isset($cache->data)) {
        //                return $cache->data;
        //            }
        //        }
        $this->getBusyTickets();
        $this->getSpecialDays();
        $weeks = [];
        $curTime = time();

        for ($day = 0; $day < (self::SECONDS_IN_WEEK * $this->settings['weeks']); $day += self::SECONDS_IN_WEEK) {
            $weeks[] = $this->generateWeek($curTime + $day);
        }
        //        if (!$this->settings['debug']) {
        //            Drupal::cache()->set(__CLASS__ . ':' . __FUNCTION__, $weeks, time() + ($this->settings['interval'] * 59));
        //        }
        return $weeks;
    }

    private function getBusyTickets(): void
    {
        $fromDate = DrupalDateTime::createFromTimestamp(time())->format('Y-m-d\TH:i:s');
        $query = Drupal::entityQuery('node')->condition('type', 'registration')->condition('field_data', $fromDate, '>');
        foreach ($query->execute() as $row) {
            $node = Node::load($row);
            $cdt = DrupalDateTime::createFromFormat('Y-m-d\TH:i:s', $node->get('field_data')->getValue()[0]['value']);
            $cdt->add(new DateInterval('PT5H'));
            $this->busyTickets[$cdt->getTimestamp()] = 1;
        }
    }

    private function getSpecialDays(): void
    {
        $fromDate = DrupalDateTime::createFromTimestamp(time())->format('Y-m-d');
        $query = Drupal::entityQuery('node')->condition('type', 'holidays')->condition('field_spec_data', $fromDate, '>');
        foreach ($query->execute() as $row) {
            $node = Node::load($row);
            $cdt = DrupalDateTime::createFromFormat('Y-m-d H:i:s', $node->get('field_spec_data')->getValue()[0]['value'] . ' 00:00:00');
            $this->specialDays[$cdt->format('d.m.Y')] = $this->getSpecialDayEnd($node->get('field_day_type')->getValue()[0]['value']);
        }
    }

    private function getSpecialDayEnd(int $dayType): string
    {
        return match ($dayType) {
            2 => '',
            4, 16 => $this->settings['work_end_friday'],
            default => $this->settings['work_end'],
        };
    }


}
