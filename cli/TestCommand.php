<?php
namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Common\Page\Pages;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use RocketTheme\Toolbox\File\File;
use Symfony\Component\Yaml\Yaml;
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
        $config = $this->getgrav()['config'];
        $datadir = $config->get('plugins.webmention.datadir');
        $datafile = $config->get('plugins.webmention.sender.file_data');
        $root = DATA_DIR . $datadir . '/';

        $filename = $root . $datafile;
        $datafh = File::instance($filename);
        $datafh->lock(); // Apparently this will create a nonexistent file, too.
        $data = Yaml::parse($datafh->content());
        if ($data !== null) {
            $client = new \IndieWeb\MentionClient();

            // If `vouch` is enabled, load the map file
            if ($config->get('plugins.webmention.vouch.enabled')) {
                $mapfilename = $root . $mapfile;
                if (file_exists($mapfilename)) {
                    $mapfh = File::instance($mapfilename);
                    $mapdata = YAML::parse($mapfh->content());
                    $mapfh->free();
                }
                if ($mapdata === null) {
                    $mapdata = array();
                }            
            }

            //Iterate and notify
            foreach ($data as $slug => &$pagedata) {
                $this->output->writeln('Slug: ' . $slug);
                foreach ($pagedata['links'] as &$link) {
                    $this->output->writeln("\tLink: " . $link['url']);
                    if ($link['lastnotified'] === null) {
                        $this->output->writeln("\t\tProcessing...");
                        // get vouch, if enabled and mapped
                        $vouch = null;
                        if ($config->get('plugins.webmention.vouch.enabled')) {
                            foreach ($mapdata as $pattern => $vouchurl) {
                                if (preg_match($pattern, $link['url'])) {
                                    $vouch = $vouchurl;
                                    break;
                                }
                            }
                        }
                        if ($vouch !== null) {
                            $this->output->writeln("\t\tVouch found: " . $vouch);
                        }

                        // discover endpoint and send if supported
                        $supports = $client->discoverWebmentionEndpoint($link['url']);
                        if ($supports) {
                           $this->output->writeln("\t\tEndpoint found: " . var_dump($supports));

                            if ($vouch !== null) {
                                $result = $client->sendWebmention($page->permalink(), $link['url'], ['vouch' => $vouch]);    
                            } else {
                                $result = $client->sendWebmention($page->permalink(), $link['url']);    
                            }
                            //dump($result);
                            $link['lastnotified'] = time();
                            $link['laststatus'] = $result['code'];
                            $msg = "Headers:\n";
                            foreach ($result['headers'] as $key => $value) {
                                $msg = $msg . $key . ': ' . $value . "\n";
                            }
                            $msg = $msg . "\nBody:\n";
                            $msg = $msg . $result['body'];
                            $link['lastmessage'] = $msg;
                        } else {
                            $this->output->writeln("\t\tWebmentions not supported");
                            $link['lastnotified'] = time();
                            $link['laststatus'] = null;
                            $link['lastmessage'] = 'Webmention support not advertised';
                        }
                    }
                }
                unset($link);
            }
            unset($pagedata);
            $datafh->save(YAML::dump($data));
        }
        $datafh->free();
    }
}