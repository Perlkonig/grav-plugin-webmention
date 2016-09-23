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
class VerifyCommand extends ConsoleCommand
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
            ->setName("verify")
            ->setDescription("Verifies received webmentions")
            ->addOption(
                'old',
                null,
                InputOption::VALUE_REQUIRED,
                'After how many days do you want to reverify webmentions?',
                30
            );
    }

    /**
     * @return int|null|void
     */
    protected function serve()
    {
        // Collects the arguments and options as defined
        $this->options = [
            'old' => $this->input->getOption('old')
        ];

        $config = $this->getgrav()['config'];
        $datadir = $config->get('plugins.webmention.datadir');
        $datafile = $config->get('plugins.webmention.sender.file_data');
        $root = DATA_DIR . $datadir . '/';

        $filename = $root . $datafile;
        $datafh = File::instance($filename);
        $datafh->lock(); // Apparently this will create a nonexistent file, too.
        $data = Yaml::parse($datafh->content());

        // Get counts and notify in batches
        //   Total unnotified
        $count = $this->count_unnotified($data);
        if ($count > 0) {
            $this->output->writeln('There are '.$count.' notifications pending.');
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Do you wish to notify these mentions (Y/n)?', true);
            if ($helper->ask($this->input, $this->output, $question)) {
                $data = $this->notify($data);
            }
        } else {
            $this->output->writeln('There are no notifications pending.');
        }

        //   Total entries not checked for X (30) days
        $old = $this->options['old'];
        $count = $this->count_old($data, $old);
        if ($count > 0) {
            $this->output->writeln('There are '.$count.' old notifications (older than '.$old.' days).');
            $question = new ConfirmationQuestion('Do you wish to retry/resend these old mentions (Y/n)?', true);
            if ($helper->ask($this->input, $this->output, $question)) {
                $data = $this->reset_old($data, $old);
                $data = $this->notify($data);
            } else {
                $this->output->writeln('You said no!');
            }
        } else {
            $this->output->writeln('There are no old notifications (older than '.$old.' days).');
        }

        // Save data
        $datafh->save(YAML::dump($data));
        $datafh->free();
        $this->output->writeln('Done.');
    }

    private function count_unnotified($data) {
        $count = 0;
        if ($data !== null) {
            foreach ($data as $pageid => $pagedata) {
                foreach ($pagedata['links'] as $link) {
                    if ($link['lastnotified'] === null) {
                        $count++;
                    }
                }
            }
        }        
        return $count;
    }

    private function count_old($data, $days) {
        $seconds = $days * 24 * 60 * 60;
        $threshold = time() - $seconds;
        $count = 0;
        if ($data !== null) {
            foreach ($data as $pageid => $pagedata) {
                foreach ($pagedata['links'] as $link) {
                    if ( (!is_null($link['lastnotified'])) && ($link['lastnotified'] <= $threshold) ) {
                        $count++;
                    }
                }
            }
        }        
        return $count;
    }

    private function reset_old($data, $days) {
        $seconds = $days * 24 * 60 * 60;
        $threshold = time() - $seconds;
        if ($data !== null) {
            foreach ($data as $pageid => &$pagedata) {
                foreach ($pagedata['links'] as &$link) {
                    if ($link['lastnotified'] <= $threshold) {
                        $link['lastnotified'] = null;
                    }
                }
                unset($link);
            }
            unset($pagedata);
        }        
        return $data;
    }

    private function notify($data) {
        if ($data !== null) {
            $config = $this->getgrav()['config'];
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
        }        
        return $data;
    }
}