<?php
namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Common\Page\Pages;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use RocketTheme\Toolbox\File\File;
use Symfony\Component\Yaml\Yaml;
use Grav\Plugin\WebmentionPlugin;
require_once __DIR__ . '/../classes/MentionClient.php';

/**
 * Class HelloCommand
 *
 * @package Grav\Plugin\Console
 */
class DeleteCommand extends ConsoleCommand
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
            ->setName("delete")
            ->setDescription("Notifies any links in a deleted page that the page has been deleted")
            ->addArgument(
                'route',
                InputArgument::REQUIRED,
                'The route that has been deleted'
            )
            ->setHelp('The <info>delete</info> command sends notifications to links found in delete pages. Before running this command, ensure that the URL in question returns `410 GONE`. A `404 NOT FOUND` response is not sufficient. See the Grav plugin "Graveyard."'
            );
    }

    /**
     * @return int|null|void
     */
    protected function serve()
    {
        // Collects the arguments and options as defined
        $this->options = [
            'route' => $this->input->getArgument('route')
        ];
        if ( ($this->output->isQuiet()) || ($this->options['suppress']) ) {
            $this->options['auto'] = true;
        }

        $config = $this->getgrav()['config'];
        $datadir = $config->get('plugins.webmention.datadir');
        $datafile = $config->get('plugins.webmention.sender.file_data');
        $root = DATA_DIR . $datadir . '/';

        $filename = $root . $datafile;
        $datafh = File::instance($filename);
        $datafh->lock(); // Apparently this will create a nonexistent file, too.
        $data = Yaml::parse($datafh->content());

        // Confirm deletion
        $route = $this->options['route'];
        $this->output->writeln('You have requested to delete the route '.$route.'.');
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('Do you wish to proceed (y/N)?', false);
        if ($helper->ask($this->input, $this->output, $question)) {
            $extract = null;
            if (array_key_exists($route, $data)) {
                $extract = [$data[$route]];
            }
            if ($extract === null) {
                $this->output->writeln('That route contains no external links.');
            } else {
                // Notify links
                $this->notify($extract);
                // Delete route from data file
                $question = new ConfirmationQuestion('Notifications complete. Last chance. Do you wish to delete the route from the data file (you should) (y/N)?', false);
                if ($helper->ask($this->input, $this->output, $question)) {
                    unset($data[$route]);
                }
            }
        }

        // Save data
        $datafh->save(YAML::dump($data));
        $datafh->free();
        $this->output->writeln('Done.');
    }

    private function notify($data) {
        if ($data !== null) {
            $config = $this->getgrav()['config'];
            $client = new \IndieWeb\MentionClient();

            // If `vouch` is enabled, load the map file
            if ($config->get('plugins.webmention.vouch.enabled')) {
                $mapdata = (array) $config->get('plugins.webmention.vouch.send_map');
                if ($mapdata === null) {
                    $mapdata = array();
                }            
            }

            //Iterate and notify
            foreach ($data as $slug => &$pagedata) {
                $this->output->writeln('Route: ' . $slug);
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
                           $this->output->writeln("\t\tEndpoint found: " . var_export($supports, true));

                            if ($vouch !== null) {
                                $result = $client->sendWebmention($pagedata['permalink'], $link['url'], ['vouch' => $vouch]);    
                            } else {
                                $result = $client->sendWebmention($pagedata['permalink'], $link['url']);    
                            }
                            //dump($result);
                            $link['lastnotified'] = time();
                            $link['laststatus'] = $result['code'];
                            $this->output->writeln("\t\tStatus code returned: " . $result['code']);
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
        }        
        return $data;
    }

    private function startsWith($haystack, $needle) {
        // search backwards starting from haystack length characters from the end
        return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== false;
    }    
}