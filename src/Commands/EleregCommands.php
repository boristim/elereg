<?php


namespace Drupal\Elereg\Commands;


use Drupal;
use Drupal\Core\Entity\EntityStorageInterface;
use Drush\Commands\DrushCommands;

class EleregCommands extends DrushCommands
{

    /**
     * @var \Drupal\Core\Entity\EntityStorageInterface
     */
    protected EntityStorageInterface $entityStorage;

    public function __construct()
    {
        parent::__construct();
        $this->entityStorage = Drupal::entityTypeManager()->getStorage('node');
    }

    /**
     * @param string $period
     * @param false[] $options
     *
     * @command elereg:generate:period
     * @aliases egp
     * @usage elereg:generate:period month --verb --force
     *  generates work dates on period (month, quarter, year)
     */
    public function generateCalendar(string $period = 'month', array $options = ['verb' => false, 'force' => false])
    {
        $this->writeln($period);
    }

}