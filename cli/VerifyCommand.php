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
//require_once __DIR__ . '/../classes/MentionClient.php';
require_once __DIR__ . '/../classes/Parser.php';

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
            ->setDescription("Verifies webmentions")
            ->addOption(
                'old',
                null,
                InputOption::VALUE_REQUIRED,
                'The number of days after which you want to reverify notifications.',
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
            ->setHelp('The <info>verify</info> command verifies received webmentions. Output is controlled at three levels: the "autoconfirm" option will print all output and skip all prompts; the "suppressinfo" option implies "autoconfirm" and only produces output if links are actually notifed; and finally the "quiet" option impiles all of the above and outputs nothing at all.');
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
        $datafile = $config->get('plugins.webmention.receiver.file_data');
        $root = DATA_DIR . $datadir . '/';

        $filename = $root . $datafile;
        $datafh = File::instance($filename);
        $datafh->lock(); // Apparently this will create a nonexistent file, too.
        $data = Yaml::parse($datafh->content());

        // Get counts and notify in batches
        //   Total unnotified
        $count = $this->count_unverified($data);
        if ($count > 0) {
            if (! $this->options['suppress']) {
                $this->output->writeln('There are '.$count.' verifications pending.');
            }
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Do you wish to verify these mentions (Y/n)?', true);
            if ( ($this->options['auto']) || ($helper->ask($this->input, $this->output, $question)) ) {
                $data = $this->verify($data);
            }
        } else {
            if (! $this->options['suppress']) {
                $this->output->writeln('There are no verifications pending.');
            }
        }

        //   Total entries not checked for X (30) days
        $old = $this->options['old'];
        $count = $this->count_old($data, $old);
        if ($count > 0) {
            if (! $this->options['suppress']) {
                $this->output->writeln('There are '.$count.' old verified webmentions (older than '.$old.' days).');
            }
            $question = new ConfirmationQuestion('Do you wish to reverify these old mentions (Y/n)?', true);
            if ( ($this->options['auto']) || ($helper->ask($this->input, $this->output, $question)) ) {
                $data = $this->reset_old($data, $old);
                $data = $this->verify($data);
            }
        } else {
            if (! $this->options['suppress']) {
                $this->output->writeln('There are no old verifications (older than '.$old.' days).');
            }
        }

        //   Total 410s
        $count = $this->count_410s($data);
        if ($count > 0) {
            if (! $this->options['suppress']) {
                $this->output->writeln('There are '.$count.' mentions now returning "410 GONE."');
            }
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Do you wish to delete these mentions (Y/n)?', true);
            if ( ($this->options['auto']) || ($helper->ask($this->input, $this->output, $question)) ) {
                $data = $this->cull_410s($data);
            }
        } else {
            if (! $this->options['suppress']) {
                $this->output->writeln('There are no mentions returning "410 GONE."');
            }
        }

        // Save data
        $datafh->save(YAML::dump($data));
        $datafh->free();
        if (! $this->options['suppress']) {
            $this->output->writeln('Done.');
        }
    }

    private function count_unverified($data) {
        $count = 0;
        if ($data !== null) {
            foreach ($data as $pageid => $pagedata) {
                foreach ($pagedata as $link) {
                    if ($link['lastchecked'] === null) {
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
                foreach ($pagedata as $link) {
                    // Is old?
                    if ( (!is_null($link['lastchecked'])) && ($link['lastchecked'] <= $threshold) ) {
                        $count++;    
                    }
                }
            }
        }        
        return $count;
    }

    private function count_410s($data) {
        $count = 0;
        if ($data !== null) {
            foreach ($data as $pageid => $pagedata) {
                foreach ($pagedata as $link) {
                    if ($link['lastcode'] == 410) {
                        $count++;
                    }
                }
            }
        }        
        return $count;        
    }

    private function cull_410s($data) {
        if ($data !== null) {
            foreach ($data as $pageid => &$pagedata) {
                foreach($pagedata as $key => &$link) {
                    if ($link['lastcode'] == 410) {
                        unset($pagedata[$key]);
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
                foreach ($pagedata as &$link) {
                    // Is old?
                    if ( (!is_null($link['lastchecked'])) && ($link['lastchecked'] <= $threshold) ) {
                        $link['lastchecked'] = null;  
                    }
                }
                unset($link);
            }
            unset($pagedata);
        }        
        return $data;
    }

    private function verify($data) {
        if ($data !== null) {
            $config = $this->getgrav()['config'];

            //Iterate and verify
            foreach ($data as $slug => &$pagedata) {
                $this->output->writeln('Target URL: ' . $slug);
                foreach ($pagedata as &$link) {
                    $this->output->writeln("\tSource URL: " . $link['source_url']);
                    if ($link['lastchecked'] === null) {
                        $this->output->writeln("\t\tVerifying...");
                        // Set `lastchecked`
                        $link['lastchecked'] = time();
                        // Fetch the source url
                        $result = self::_get($link['source_url']);
                        $link['lastcode'] = $result['code'];
                        $result['headers'] = array_change_key_case($result['headers'], CASE_LOWER);
                        //$this->output->writeln('Result: '.var_export($result, true));
                        // Validate the source
                        $valid = false;
                        if (self::startsWith($result['code'], '2')) {
                            // Check for HTML, then do naive search
                            if ( (isset($result['headers']['content-type'])) && (self::startsWith(strtolower($result['headers']['content-type']), 'text/html')) ) {
                                $content = $result['body'];
                                // strip all comments
                                $content = preg_replace('/<!--.*?-->/', '', $content);
                                // search href
                                if (strpos($content, 'href="'.$slug.'"') !== false) {
                                    $valid = true;
                                // search src
                                } elseif (strpos($content, 'src="'.$slug.'"') !== false) {
                                    $valid = true;
                                }
                            } else {
                                if (strpos($result['body'], $slug) !== false) {
                                   $valid = true;
                                }
                            }
                        }
                        $this->output->writeln("\t\tValid: " . var_export($valid, true));

                        // Extract MF2 if present
                        $link['source_mf2'] = null;
                        $mf2 = \Mf2\parse($result['body'], $link['source_url']);
                        if ($mf2 !== null) {
                            $link['source_mf2'] = $mf2;
                        }
                        $link['vouch_mf2'] = null;

                        // If vouch, only if valid
                        if ($valid) {
                            $vouchrequired = false;
                            $autoapprove = 'none';
                            $vouchvalid = false;
                            $vouchwhite = false;
                            $vouchblack = false;
                            if ($config->get('plugins.webmention.vouch.enabled')) {
                                $vouchrequired = $config->get('plugins.webmention.vouch.required');
                                $autoapprove = $config->get('plugins.webmention.vouch.auto_approve');
                                $vouch = $link['vouch_url'];
                                if ($vouch !== null) {
                                    //   Check whitelist
                                    $whitelist = (array) $config->get('plugins.webmention.vouch.whitelist');
                                    $blacklist = (array) $config->get('plugins.webmention.vouch.blacklist');
                                    foreach ($whitelist as $entry) {
                                        if (preg_match($entry, $vouch)) {
                                            $vouchwhite = true;
                                            $vouchvalid = true;
                                            break;
                                        }
                                    }
                                    //   Check blacklist if not whitelisted
                                    if (! $vouchwhite) {
                                        foreach ($blacklist as $entry) {
                                            if (preg_match($entry, $vouch)) {
                                                $vouchblack = true;
                                                break;
                                            }
                                        }
                                    }
                                    //   Validate if not whitelisted
                                    if ( (! $vouchvalid) && (! $vouchblack) ) {
                                        $result = self::_get($vouch);
                                        if (self::startsWith($result['code'], '2')) {
                                            if (strpos($result['body'], $link['source_url']) !== false) {
                                                $vouchvalid = true;
                                                $mf2 = \Mf2\parse($result['body'], $vouch);
                                                if ($mf2 !== null) {
                                                    $link['vouch_mf2'] = $mf2;
                                                }
                                            }
                                        }
                                    }
                                }
                                $this->output->writeln("\t\tVouch required? ".var_export($vouchrequired, true));
                                $this->output->writeln("\t\tVouch whitelisted? ".var_export($vouchwhite, true));
                                $this->output->writeln("\t\tVouch blacklisted? ".var_export($vouchblack, true));
                                $this->output->writeln("\t\tVouch valid? ".var_export($vouchvalid, true));
                            }
                        }
                        // Set valid
                        $trulyvalid = $valid;
                        if ( ($vouchrequired) && (!$vouchvalid) ) {
                            $trulyvalid = false;
                        }
                        $link['valid'] = $trulyvalid;
                        $this->output->writeln("\t\tMarking as valid? " . var_export($trulyvalid, true));

                        // Set visible
                        $visible = false;
                        if ($trulyvalid) {
                            if ($vouchrequired) {
                                if ($autoapprove === 'white') {
                                    if ($vouchwhite) {
                                        $visible = true;
                                    }
                                } elseif ($autoapprove === 'valid') {
                                    if ($vouchvalid) {
                                        $visible = true;
                                    }
                                }
                            } else {
                                $visible = true;
                            }
                        }
                        $link['visible'] = $visible;
                        $this->output->writeln("\t\tMarking as visible? " . var_export($visible, true));
                    }
                }
                unset($link);
            }
            unset($pagedata);
        }        
        return $data;
    }

  /**
   * @codeCoverageIgnore
   */
  private static function _get($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return array(
      'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
      'headers' => self::_parse_headers(trim(substr($response, 0, $header_size))),
      'body' => substr($response, $header_size)
    );
  }

  private static function _parse_headers($headers) {
    $retVal = array();
    $fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $headers));
    foreach($fields as $field) {
      if(preg_match('/([^:]+): (.+)/m', $field, $match)) {
        $match[1] = preg_replace_callback('/(?<=^|[\x09\x20\x2D])./', function($m) {
          return strtoupper($m[0]);
        }, strtolower(trim($match[1])));
        // If there's already a value set for the header name being returned, turn it into an array and add the new value
        $match[1] = preg_replace_callback('/(?<=^|[\x09\x20\x2D])./', function($m) {
          return strtoupper($m[0]);
        }, strtolower(trim($match[1])));
        if(isset($retVal[$match[1]])) {
          if(!is_array($retVal[$match[1]]))
            $retVal[$match[1]] = array($retVal[$match[1]]);
          $retVal[$match[1]][] = $match[2];
        } else {
          $retVal[$match[1]] = trim($match[2]);
        }
      }
    }
    return $retVal;
  }

    private static function startsWith($haystack, $needle) {
        // search backwards starting from haystack length characters from the end
        return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== false;
    }    
}