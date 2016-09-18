<?php
namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Common\Page\Pages;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Grav\Plugin\WebmentionPlugin;
require_once __DIR__ . '/../classes/MentionClient.php';

/**
 * Class HelloCommand
 *
 * @package Grav\Plugin\Console
 */
class TestCommand extends ConsoleCommand
{
    /**
     * @var array
     */
    protected $options = [];

    /**
     * Greets a person with or without yelling
     */
    protected function configure()
    {
        $this
            ->setName("test")
            ->setDescription("Tests webmentions");
    }

    /**
     * @return int|null|void
     */
    protected function serve()
    {
        $pages = $this->getGrav()['pages'];
        $this->output->writeln(var_dump($pages->routes()));
    }
}