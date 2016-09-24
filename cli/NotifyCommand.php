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
class NotifyCommand extends ConsoleCommand
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
            ->setName("notify")
            ->setDescription("Sends webmentions")
            ->addOption(
                'old',
                null,
                InputOption::VALUE_REQUIRED,
                'The number of days after which you want to retry failed notifications.',
                30)
            ->addOption(
                'autoconfirm',
                'y',
                InputOption::VALUE_NONE,
                'Answers "yes" to all the prompts automatically (for use in scripted environments)')
            ->addOption(
                'suppressinfo',
                'x',
                InputOption::VALUE_NONE,
                'Suppresses the informational output but not the processing output. It implies --autoconfirm. Intended for scripted environments.')
            ->setHelp('The <info>notify</info> command sends notifications to external links. Output is controlled at three levels: the "autoconfirm" option will print all output and skip all prompts; the "suppressinfo" option implies "autoconfirm" and only produces output if links are actually notifed; and finally the "quiet" option impiles all of the above and outputs nothing at all.');
    }

    /**
     * @return int|null|void
     */
    protected function serve()
    {
        // Collects the arguments and options as defined
        $this->options = [
            'old' => $this->input->getOption('old'),
            'auto' => $this->input->getOption('autoconfirm'),
            'suppress' => $this->input->getOption('suppressinfo')
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

        // Get counts and notify in batches
        //   Total unnotified
        $count = $this->count_unnotified($data);
        if ($count > 0) {
            if (! $this->options['suppress']) {
                $this->output->writeln('There are '.$count.' notifications pending.');
            }
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Do you wish to notify these mentions (Y/n)?', true);
            if ( ($this->options['auto']) || ($helper->ask($this->input, $this->output, $question)) ) {
                $data = $this->notify($data);
            }
        } else {
            if (! $this->options['suppress']) {
                $this->output->writeln('There are no notifications pending.');
            }
        }

        //   Cull old links
        $count = $this->count_tocull($data);
        if ($count > 0) {
            if (! $this->options['suppress']) {
                $this->output->writeln('There are '.$count.' links that have been removed from your pages that you have also notified the linker of. These can be safely deleted.');
            }
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Do you wish to delete these records (Y/n)?', true);
            if ( ($this->options['auto']) || ($helper->ask($this->input, $this->output, $question)) ) {
                $data = $this->cull_removed($data);
            }
        } else {
            if (! $this->options['suppress']) {
                $this->output->writeln('There are no removed links pending deletion.');
            }
        }

        //   Total entries not checked for X (30) days
        $old = $this->options['old'];
        $count = $this->count_old($data, $old);
        if ($count > 0) {
            if (! $this->options['suppress']) {
                $this->output->writeln('There are '.$count.' old failed notifications (older than '.$old.' days).');
            }
            $question = new ConfirmationQuestion('Do you wish to retry these old mentions (Y/n)?', true);
            if ( ($this->options['auto']) || ($helper->ask($this->input, $this->output, $question)) ) {
                $data = $this->reset_old($data, $old);
                $data = $this->notify($data);
            }
        } else {
            if (! $this->options['suppress']) {
                $this->output->writeln('There are no old notifications (older than '.$old.' days).');
            }
        }

        // Save data
        $datafh->save(YAML::dump($data));
        $datafh->free();
        if (! $this->options['suppress']) {
            $this->output->writeln('Done.');
        }
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
                    // Is old?
                    if ( (!is_null($link['lastnotified'])) && ($link['lastnotified'] <= $threshold) ) {
                        // Is failed?
                        if ( (is_null($link['laststatus'])) || (!$this->startsWith($link['laststatus'], '2')) ) {
                            $count++;    
                        }
                    }
                }
            }
        }        
        return $count;
    }

    private function count_tocull($data) {
        $count = 0;
        if ($data !== null) {
            foreach ($data as $pageid => $pagedata) {
                foreach($pagedata['links'] as $link) {
                    if ( (! $link['inpage']) && ($link['lastnotified'] !== null) && ($this->startsWith($link['laststatus'], '2')) ) {
                        $count++;
                    }
                }
            }
        }
        return $count;
    }

    private function cull_removed($data) {
        if ($data !== null) {
            foreach ($data as $pageid => &$pagedata) {
                foreach($pagedata['links'] as $key => $link) {
                    if ( (! $link['inpage']) && ($link['lastnotified'] !== null) && ($this->startsWith($link['laststatus'], '2')) ) {
                        unset($pagedata['links'][$key]);
                    }
                }
            }
        }
        return $data;
    }

    private function reset_old($data, $days) {
        $seconds = $days * 24 * 60 * 60;
        $threshold = time() - $seconds;
        if ($data !== null) {
            foreach ($data as $pageid => &$pagedata) {
                foreach ($pagedata['links'] as &$link) {
                    // Is old?
                    if ( (!is_null($link['lastnotified'])) && ($link['lastnotified'] <= $threshold) ) {
                        // Is failed?
                        if ( (is_null($link['laststatus'])) || (!$this->startsWith($link['laststatus'], '2')) ) {
                            $link['lastnotified'] = null;  
                        }
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

                            // Verify that endpoint does not resolve to a loopback address
                            $allgood = true;
                            $url = parse_url($supports);
                            if ($url) {
                                $ip = gethostbyname($url['host']);
                                if (self::ip_in_range($ip, '127.0.0.0/32')) {
                                    $allgood = false;
                                } elseif (self::ip_in_range($ip, '10.0.0.0/8')) {
                                    $allgood = false;
                                } elseif (self::ip_in_range($ip, '172.16.0.0/12')) {
                                    $allgood = false;
                                } elseif (self::ip_in_range($ip, '192.168.0.0/16')) {
                                    $allgood = false;
                                }
                            }
                            if (! $allgood) {
                                $link['lastnotified'] = time();
                                $link['laststatus'] = null;
                                $link['lastmessage'] = 'ENDPOINT IS A LOOPBACK/RESERVED ADDRESS! You should probably blacklist this sender.';
                                continue;
                            }

                            if ($vouch !== null) {
                                $result = $client->sendWebmention($pagedata['permalink'], $link['url'], ['vouch' => $vouch]);    
                            } else {
                                $result = $client->sendWebmention($pagedata['permalink'], $link['url']);    
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

    /**
     * Check if a given ip is in a network
     * @param  string $ip    IP to check in IPV4 format eg. 127.0.0.1
     * @param  string $range IP/CIDR netmask eg. 127.0.0.0/24, also 127.0.0.1 is accepted and /32 assumed
     * @return boolean true if the ip is in this range / false if not.
     */
    private static function ip_in_range( $ip, $range ) {
        if ( strpos( $range, '/' ) == false ) {
            $range .= '/32';
        }
        // $range is in IP/CIDR format eg 127.0.0.1/24
        list( $range, $netmask ) = explode( '/', $range, 2 );
        $range_decimal = ip2long( $range );
        $ip_decimal = ip2long( $ip );
        $wildcard_decimal = pow( 2, ( 32 - $netmask ) ) - 1;
        $netmask_decimal = ~ $wildcard_decimal;
        return ( ( $ip_decimal & $netmask_decimal ) == ( $range_decimal & $netmask_decimal ) );
    }

    private function startsWith($haystack, $needle) {
        // search backwards starting from haystack length characters from the end
        return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== false;
    }    
}