<?php

namespace Drupal\elereg\Controller;

use DateInterval;
use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\Entity\Node;

/**
 * Returns responses for elereg routes.
 */
class EleregController extends ControllerBase
{

    const SECONDS_IN_DAY = 86400;

    const SECONDS_IN_WEEK = 604800;

    const VOC_SERVICES = 'services';


    private array $settings;

    private array $busyTickets;

    /**
     * @var \Drupal\Core\Entity\EntityStorageInterface
     */
    protected EntityStorageInterface $entityStorage;

    private array $specialDays;

    /**
     * Builds the response.
     */
    public function build(): array
    {
        $build['content'] = [
            '#type' => 'item',
            '#markup' => $this->t('It works!'),
        ];

        return $build;
    }

    /**
     * @return array
     */
    public function main(): array
    {
        $build['content'] = [
            '#type' => 'item',
            '#markup' => '<div class="elereg-throbber"></div>',
            '#attached' => [
                'drupalSettings' => [
                    'elereg' => ['endPoint' => '/elereg/ajax', 'rootElement' => '.column.main-content'],
                ],
            ],
        ];

        $build['#attached']['library'][] = 'elereg/elereg';
        return $build;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    public function ajax(Request $request): JsonResponse
    {
        $data = [];
        $this->entityStorage = Drupal::entityTypeManager()->getStorage('node');
        $this->settings = $this->config('elereg.settings')->getRawData();
        if ($request->getMethod() == 'GET') {
            $data['dates'] = $this->generateMonth();
            $data['services'] = $this->getServices();
        }
        if ($request->getMethod() == 'POST') {
            $values = (array)json_decode($request->getContent());
            $data = $this->saveRegistration($values);
        }
        return (new JsonResponse())->setData($data);
    }

    /**
     * @param $day
     * @param $genHours
     *
     * @return array
     */
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
    private function generateMonth(): array
    {
        if (!$this->settings['debug']) {
            $cache = Drupal::cache()->get(__CLASS__ . ':' . __FUNCTION__);
            if (isset($cache->data)) {
                return $cache->data;
            }
        }
        $this->getBusyTickets();
        $this->getSpecialDays();
        $weeks = [];
        $curTime = time();

        for ($day = 0; $day < (self::SECONDS_IN_WEEK * $this->settings['weeks']); $day += self::SECONDS_IN_WEEK) {
            $weeks[] = $this->generateWeek($curTime + $day);
        }
        if (!$this->settings['debug']) {
            Drupal::cache()->set(__CLASS__ . ':' . __FUNCTION__, $weeks, time() + ($this->settings['interval'] * 59));
        }
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

    /**
     * @return array
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    private function getServices(): array
    {
        if (!$this->settings['debug']) {
            $cache = Drupal::cache()->get(__CLASS__ . ':' . __FUNCTION__);
            if (isset($cache->data)) {
                return $cache->data;
            }
        }

        $ret = [];
        $terms = Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree(self::VOC_SERVICES);
        foreach ($terms as $term) {
            if ($term->status) {
                $ret[] = [
                    'id' => $term->tid,
                    'name' => $term->name,
                ];
            }
        }
        if (!$this->settings['debug']) {
            Drupal::cache()->set(__CLASS__ . ':' . __FUNCTION__, $ret, time() + ($this->settings['interval'] * 59));
        }
        return $ret;
    }

    /**
     * @param array $values
     *
     * @return string[]
     */
    private function saveRegistration(array $values): array
    {
        $ret = ['status' => 'ok'];
        if (!isset($values['Services'], $values['tel'], $values['fio'], $values['Weeks'], $values['Hours'])) {
            $ret['error'] = 'Ошибка сохранения';
            $ret['errorMessage'] = 'Not all required data';
            $ret['data'] = $values;
            $ret['status'] = 'error';
            return $ret;
        }
        try {
            $date = DrupalDateTime::createFromFormat('d.m.Y H:i:s', $values['Weeks'][0] . ' ' . $values['Hours'][0] . ':00');
        } catch (\InvalidArgumentException $e) {
            $ret['error'] = 'Плохой формат даты';
            $ret['errorMessage'] = $e->getMessage();
            $ret['status'] = 'error';
            return $ret;
        }
        $tel = preg_replace('/\D/', '', $values['tel']);
        $telLen = mb_strlen($tel);
        if ($telLen > 10) {
            $tel = mb_substr($tel, $telLen - 10, 10);
        }
        $fio = mb_substr($values['fio'], 0, 254);
        $title = "$tel " . $date->format('Y-m-d H:i');
        $date->sub(new DateInterval('PT5H'));
        if (!$this->checkPhoneForOneDay($tel, $date, $ret)) {
            return $ret;
        }
        if (!$this->checkPhoneForThreePerWeek($tel, $date, $ret)) {
            return $ret;
        }
        $node = Node::create(['type' => 'registration', 'title' => $title]);
        $node->set('field_data', $date->format('Y-m-d\TH:i:s'))->set('field_tel', $tel)->set('field_fio', $fio);
        $services = [];
        foreach ($values['Services'] as $serviceId) {
            $services[] = ['target_id' => $serviceId];
        }
        $node->set('field_services', $services);
        try {
            $node->save();
        } catch (EntityStorageException | \InvalidArgumentException $e) {
            $ret['error'] = 'Ошибка сохранения';
            $ret['errorMessage'] = $e->getMessage();
            $ret['status'] = 'error';
            return $ret;
        }
        $ret['nid'] = $node->id();
        return $ret;
    }

    private function checkPhoneForOneDay(string $tel, DrupalDateTime $date, array &$ret): bool
    {
        $query = Drupal::entityQuery('node')->condition('type', 'registration')->condition('field_tel', $tel)->condition('field_data', $date->format('Y-m-d') . '%', 'LIKE');
        $result = $query->execute();
        $ret['checkInfo'] = $result;
        if (count($result)) {
            $ret['message'] = 'На этот день вы уже зарегистрированы';
            $ret['info'] = $result;
            $ret['status'] = 'warning';
            return false;
        }
        return true;
    }

    private function checkPhoneForThreePerWeek(string $tel, DrupalDateTime $date, array &$ret): bool
    {
        $day = $date->getTimestamp();
        $curDay = intval(date('w', $day)) - 1;
        $firstWeekDay = DrupalDateTime::createFromTimestamp(strtotime('-' . $curDay . ' days', $day))->format('Y-m-d') . '00:00:00';
        $lastWeekDay = DrupalDateTime::createFromTimestamp(strtotime('+' . (6 - $curDay) . ' days', $day))->format('Y-m-d') . '23:59:59';
        $query = Drupal::entityQuery('node')->condition('type', 'registration')->condition('field_tel', $tel)->condition('field_data', $firstWeekDay, '>=')->condition(
            'field_data',
            $lastWeekDay,
            '<='
        );

        $services = [];
        $result = $query->execute();
        foreach ($result as $nid) {
            $node = Node::load($nid);
            foreach ($node->get('field_services')->getValue() as $v) {
                if ($v['target_id']) {
                    $services[$v['target_id']] = $v['target_id'];
                }
            }
        }
        $availServices = $this->getServices();
        if (count($services) >= count($availServices)) {
            $ret['message'] = 'На указанной неделе вы уже зарегистрированы на все доступные услуги(' . count($services) . ')';
            $ret['info'] = $services;
            $ret['status'] = 'warning';
            return false;
        }
        return true;
    }

}
