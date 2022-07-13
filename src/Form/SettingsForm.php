<?php

namespace Drupal\elereg\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure elereg settings for this site.
 */
class SettingsForm extends ConfigFormBase
{


    /**
     * {@inheritdoc}
     */
    public function getFormId(): string
    {
        return 'elereg_settings';
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames(): array
    {
        return ['elereg.settings'];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $settings = $this->config('elereg.settings');
        $form['generate'] = [
            '#type' => 'fieldgroup',
            '#title' => 'Параметры расписания',
            'base_time' => [
                '#type' => 'fieldgroup',
                'work_from' => [
                    '#type' => 'datetime',
                    '#title' => 'Начало приёма',
                    '#default_value' => DrupalDateTime::createFromFormat('H:i:s', $settings->get('work_from') ?: '08:00:00'),
                    '#date_date_element' => 'none',
                    '#date_time_element' => 'time',
                    '#date_time_format' => 'H:i:s',
                ],
                'work_end' => [
                    '#type' => 'datetime',
                    '#title' => 'Завершение приёма',
                    '#default_value' => DrupalDateTime::createFromFormat('H:i:s', $settings->get('work_end') ?: '16:30:00'),
                    '#date_date_element' => 'none',
                    '#date_time_element' => 'time',
                    '#date_time_format' => 'H:i:s',
                ],
                'work_end_friday' => [
                    '#type' => 'datetime',
                    '#title' => 'Завершение приёма в сокращённый день',
                    '#default_value' => DrupalDateTime::createFromFormat('H:i:s', $settings->get('work_end_friday') ?: '15:30:00'),
                    '#date_date_element' => 'none',
                    '#date_time_element' => 'time',
                    '#date_time_format' => 'H:i:s',
                ],
            ],
            'free_time' => [
                '#type' => 'fieldgroup',
                'lunch_from' => [
                    '#type' => 'datetime',
                    '#title' => 'Начало обеда',
                    '#default_value' => DrupalDateTime::createFromFormat('H:i:s', $settings->get('lunch_from') ?: '12:00:00'),
                    '#date_date_element' => 'none',
                    '#date_time_element' => 'time',
                    '#date_time_format' => 'H:i:s',
                ],
                'lunch_end' => [
                    '#type' => 'datetime',
                    '#title' => 'Завершение обеда',
                    '#default_value' => DrupalDateTime::createFromFormat('H:i:s', $settings->get('lunch_end') ?: '12:40:00'),
                    '#date_date_element' => 'none',
                    '#date_time_element' => 'time',
                    '#date_time_format' => 'H:i:s',
                ],
            ],
            'interval' => [
                '#type' => 'number',
                '#title' => 'Интервал между приёмами',
                '#description' => 'минуты',
                '#default_value' => $settings->get('interval') ?: 10,
                '#attributes' => ['min' => 10, 'max' => 60, 'step' => 5,],
            ],
            'weeks' => [
                '#type' => 'number',
                '#title' => 'Недели',
                '#description' => 'Сколько недель показывать в календаре',
                '#default_value' => $settings->get('weeks') ?: 5,
                '#attributes' => ['min' => 1, 'max' => 6, 'step' => 1,],
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
        $values = $form_state->getUserInput();
        $timeFrom = DrupalDateTime::createFromFormat('H:i:s', $values['work_from']['time'])->getTimestamp();
        $timeEnd = DrupalDateTime::createFromFormat('H:i:s', $values['work_end']['time'])->getTimestamp();
        $timeEndFriday = DrupalDateTime::createFromFormat('H:i:s', $values['work_end_friday']['time'])->getTimestamp();
        if (($timeFrom > $timeEnd) || ($timeFrom > $timeEndFriday)) {
            $form_state->setErrorByName('work_from', 'Начало приёма должно не может быть больше времени окончания');
        }
        parent::validateForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $values = $form_state->getUserInput();
        foreach (['work_from', 'work_end', 'work_end_friday', 'interval', 'weeks', 'lunch_from', 'lunch_end'] as $key) {
            $this->config('elereg.settings')->set($key, is_array($values[$key]) ? reset($values[$key]) : $values[$key])->save();
        }
        parent::submitForm($form, $form_state);
    }

}
