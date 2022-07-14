<?php

namespace Drupal\elereg\Form;


use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure elereg settings for this site.
 */
class SettingsSmsForm extends ConfigFormBase
{


    /**
     * {@inheritdoc}
     */
    public function getFormId(): string
    {
        return 'elereg_sms_settings';
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames(): array
    {
        return ['elereg.sms_settings'];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $settings = $this->config('elereg.sms_settings');
        $form['sms'] = [
            '#type' => 'fieldgroup',
            '#title' => 'Параметры расписания',
            'sender' => [
                '#type' => 'textfield',
                '#title' => 'Имя отправителя',
                '#required' => true,
                '#default_value' => $settings->get('sender') ?: '',
            ],
            'addr' => [
                '#type' => 'textfield',
                '#title' => 'Адрес SMPP сервера',
                '#required' => true,
                '#default_value' => $settings->get('addr') ?: '',
            ],
            'port' => [
                '#type' => 'number',
                '#title' => 'Порт SMPP сервера',
                '#required' => true,
                '#default_value' => $settings->get('port') ?: 5016,
                '#attributes' => [
                    'min' => 1,
                    'max' => 65536,
                ],
            ],
            'login' => [
                '#type' => 'textfield',
                '#title' => 'Имя пользователя',
                '#required' => true,
                '#default_value' => $settings->get('login') ?: '',
            ],
            'pass' => [
                '#type' => 'textfield',
                '#title' => 'Пароль',
                '#required' => true,
                '#default_value' => $settings->get('pass') ?: '',
            ],
            'period' => [
                '#type' => 'number',
                '#title' => 'Период',
                '#description' => 'задержка между SMS на один номер, мин.',
                '#required' => true,
                '#default_value' => $settings->get('period') ?: '',
                '#attributes' => [
                    'min' => 1,
                    'max' => 1440,
                ],
            ],
            'message' => [
                '#type' => 'textarea',
                '#title' => 'Сообщение',
                '#description' => 'Возможные подстановки: %fio - ФИО, %phone - телефон, %date - дата, %time - время, %service - услуги',
                '#required' => true,
                '#default_value' => $settings->get('message') ?: '',
            ],
        ];
        $form['#attached']['library'][] = 'elereg/elereg_admin';
        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        //        $values = $form_state->getUserInput();

        parent::validateForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $values = $form_state->getUserInput();
        foreach (['sender', 'addr', 'port', 'login', 'pass', 'message', 'period'] as $key) {
            $this->config('elereg.sms_settings')->set(
                $key,
                is_array($values[$key]) ? reset($values[$key]) : $values[$key]
            )->save();
        }
        parent::submitForm($form, $form_state);
    }

}
