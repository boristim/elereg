<?php


namespace Drupal\elereg;


use DateInterval;
use DateTimeImmutable;
use Drupal;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Exception;

class Smpp
{

    private SMSC_SMPP $smsc;

    private array $settings;

    function __construct()
    {
        $this->smsc = Drupal::service('elereg.smsc_smpp');
        $this->settings = Drupal::config('elereg.sms_settings')->getRawData();
    }

    public function sendMessage(Node $registration)
    {
        $status = false;
        $phone = '7' . $registration->get('field_tel')->getValue()[0]['value'];
        $title = "SMS $phone, для регистрации " . $registration->id();
        $message = $this->composeMessage($registration);


        $node = Node::create(['type' => 'sms', 'title' => $title]);
        $node->set('body', $message)->set('field_phone', $phone);
        try {
            $h24 = time() - (24 * 3600);
            $query = Drupal::entityQuery('node')->condition('type', 'sms')->condition('created', $h24, '>')->condition('field_phone', $phone);
            $result = $query->execute();
            if (!count($result)) {
                if ($this->smsc->send_sms($phone, $message, $this->settings['sender'])) {
                    $status = true;
                }
            }
        } catch (Exception $e) {
            $status = false;
            Drupal::logger('SMS')->error($e->getMessage());
        }
        $node->set('field_status', $status);
        $node->save();
    }

    private function composeMessage(Node $registration): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $registration->get('field_data')->getValue()[0]['value']);
        $date = $date->add(new DateInterval('PT5H'));
        $fields = [
            '%fio' => $registration->get('field_fio')->getValue()[0]['value'],
            '%phone' => $registration->get('field_tel')->getValue()[0]['value'],
            '%date' => $date->format('d/m/Y'),
            '%time' => $date->format('H:i'),
        ];
        $services = [];
        foreach ($registration->get('field_services')->getValue() as $service) {
            $term = Term::load($service['target_id']);
            $services[] = '"' . $term->getName() . '"';
        }
        $fields['%service'] = implode(', ', $services);
        return strtr($this->settings['message'], $fields);
    }

}