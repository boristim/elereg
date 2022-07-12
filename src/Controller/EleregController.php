<?php

namespace Drupal\elereg\Controller;

use DateInterval;
use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\Entity\Node;

/**
 * Returns responses for elereg routes.
 */
class EleregController extends ControllerBase
{


    const VOC_SERVICES = 'services';

    private array $settings;



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
        $this->settings = $this->config('elereg.settings')->getRawData();
        $calendar = new Drupal\elereg\Calendar($this->settings);
        if ($request->getMethod() == 'GET') {
            $data['dates'] = $calendar->generateMonth();
            $data['services'] = $this->getServices();
        }
        if ($request->getMethod() == 'POST') {
            $values = (array)json_decode($request->getContent());
            $data = $this->saveRegistration($values);
        }
        return (new JsonResponse())->setData($data);
    }

    private function getServices(): array
    {
        //        if (!$this->settings['debug']) {
        //            $cache = Drupal::cache()->get(__CLASS__ . ':' . __FUNCTION__);
        //            if (isset($cache->data)) {
        //                return $cache->data;
        //            }
        //        }

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
        //        if (!$this->settings['debug']) {
        //            Drupal::cache()->set(__CLASS__ . ':' . __FUNCTION__, $ret, time() + ($this->settings['interval'] * 59));
        //        }
        return $ret;
    }

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
