<?php
namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
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
        $links = ['https://webmention.rocks/test/1',
        'https://webmention.rocks/test/2',
        'https://webmention.rocks/test/3',
        'https://webmention.rocks/test/4',
        'https://webmention.rocks/test/5',
        'https://webmention.rocks/test/6',
        'https://webmention.rocks/test/7',
        'https://webmention.rocks/test/8',
        'https://webmention.rocks/test/9',
        'https://webmention.rocks/test/10',
        'https://webmention.rocks/test/11',
        'https://webmention.rocks/test/12',
        'https://webmention.rocks/test/13',
        'https://webmention.rocks/test/14',
        'https://webmention.rocks/test/15',
        'https://webmention.rocks/test/16',
        'https://webmention.rocks/test/17',
        'https://webmention.rocks/test/18',
        'https://webmention.rocks/test/19',
        'https://webmention.rocks/test/20',
        'https://webmention.rocks/test/21'];

        foreach ($links as $link) {
            $this->output->writeln('Testing '.$link);
            $client = new \IndieWeb\MentionClient();
            $supports = $client->discoverWebmentionEndpoint($link);
            if ($supports) {
                $result = $client->sendWebmention('http://perlkonig.com/blog/webmention-testing', $link, ['comment' => '<a href="https://github.com/Perlkonig/grav-plugin-webmention">Webmention plugin for Grav CMS</a>']);
                $this->output->writeln(var_dump($result));
            }
        }
    }
}