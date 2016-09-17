<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Yaml\Yaml;

/**
 * Class WebmentionsPlugin
 * @package Grav\Plugin
 */
class WebmentionsPlugin extends Plugin
{
    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            return;
        }

        require_once __DIR__ . '/classes/MentionClient.php';

        $config = $this->grav['config'];
        $enabled = array();

        if ($config->get('plugins.webmentions.sender.enabled')) {
            $uri = $this->grav['uri'];
            $path = $uri->path();
            $disabled = (array) $config->get('plugins.webmentions.sender.ignore_routes');
            if (!in_array($path, $disabled)) {
                if ($config->get('plugins.webmentions.sender.page_only')) {
                    $enabled['onPageContentProcessed'] = ['onPageContentProcessed', 0];
                } else {
                    $enabled['onOutputGenerated'] = ['onOutputGenerated', 0];
                }
            }
        }

        $this->enable($enabled);
    }

    /**
     * Do some work for this event, full details of events can be found
     * on the learn site: http://learn.getgrav.org/plugins/event-hooks
     *
     * @param Event $e
     */
    public function onPageContentProcessed(Event $e)
    {
        $config = $this->grav['config'];
        $page = $e['page'];

        // process the content
        $this->sender($e, $page->content(), $page);

        // If `automatic` is true, send the notifications
        if ($config->get('plugins.webmentions.sender.automatic')) {
            $this->notify($page);
        }
    }

    public function onOutputGenerated(Event $e)
    {
        $config = $this->grav['config'];

        // extract just the body content
        $content = explode('<body', $this->grav->output);
        $content = explode('</body>', $content[1]);
        $content = $content[0];
        $begin = strpos($content, '>') + 1;
        $content = substr($content, $begin);

        // process the content
        $this->sender($e, $content, $this->grav['page']);

        // If `automatic` is true, send the notifications
        if ($config->get('plugins.webmentions.sender.automatic')) {
            $this->notify($page);
        }
    }

    private function sender(Event $e, $content, $page)
    {
        $pageid = $page->slug();
        $config = $this->grav['config'];
        $datadir = $config->get('plugins.webmentions.datadir');
        $datafile = $config->get('plugins.webmentions.sender.file_data');
        $blacklist = $config->get('plugins.webmentions.sender.file_blacklist');
        $root = DATA_DIR . $datadir . '/';

        // Load data file
        $filename = $root . $datafile;
        $datafh = File::instance($filename);
        $datafh->lock(); // Apparently this will create a nonexistent file, too.
        $data = Yaml::parse($datafh->content());
        if ( ($data === null) || (! in_array($pageid, $data)) ){
            $data[$pageid] = [
                'lastmodified' => null,
                'links' => []
            ];
        }

        // Only do something if the timestamps don't match
        if ($data[$pageid]['lastmodified'] !== $page->modified()) {
            $data[$pageid]['lastmodified'] = $page->modified();
            
            //scan for links
            $client = new \IndieWeb\MentionClient();
            $links = $client->findOutgoingLinks($content);

            //get blacklist
            $blfilename = $root . $blacklist;
            if (file_exists($blfilename)) {
                $blfh = File::instance($blfilename);
                $bldata = YAML::parse($blfh->content());
                $blfh->free();
            }
            if ($bldata === null) {
                $bldata = array();
            }

            $whitelinks = array();
            foreach ($links as $link) {
                $clean = true;
                foreach ($bldata as $pattern) {
                    if (preg_match($pattern, $link)) {
                        $clean = false;
                        break;
                    }
                }
                if ($clean) {
                    array_push($whitelinks, $link);
                }
            }

            $existing = array();
            // Look for existing or missing links
            foreach ($data[$pageid]['links'] as &$prevlink) {
                array_push($existing, $prevlink->url);
                if (! in_array($prevlink->url, $links)) {
                    $prevlink->inpage = false;
                    $prevlink->lastnotified = null;
                } else {
                    $prevlink->inpage = true;
                    $prevlink->lastnotified = null;
                }
            }
            unset($prevlink);

            // Add new links
            foreach ($links as $link) {
                if (! in_array($link, $existing)) {
                    array_push($data[$pageid]['links'], [
                        'url' => $link,
                        'inpage' => true,
                        'lastnotified' => null,
                        'laststatus' => null,
                        'lastmessage' => null
                    ]);
                }
            }
        }

        // Save updated data
        $datafh->save(YAML::dump($data));
        $datafh->free();
    }

    private function notify ($page = null) {
        $config = $this->grav['config'];
        $datadir = $config->get('plugins.webmentions.datadir');
        $datafile = $config->get('plugins.webmentions.sender.file_data');
        $mapfile = $config->get('plugins.webmentions.vouch.file_sender_map');
        $root = DATA_DIR . $datadir . '/';

        // Load data file
        $filename = $root . $datafile;
        $datafh = File::instance($filename);
        $datafh->lock(); // Apparently this will create a nonexistent file, too.
        $data = Yaml::parse($datafh->content());
        if ($data === null) {
            $datafh->free();
            return;
        }

        // If `vouch` is enabled, load the map file
        if ($config->get('plugins.webmentions.vouch.enabled')) {
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

        foreach ($data as $slug => &$value) {
            if ( ($page === null) || ($slug === $page->slug()) ) {
                foreach ($value['links'] as &$link) {
                    //dump('Attempting to notify '.$link['url']);
                    $client = new \IndieWeb\MentionClient();
                    if ($link['lastnotified'] === null) {
                        // get vouch, if enabled and mapped
                        $vouch = null;
                        if ($config->get('plugins.webmentions.vouch.enabled')) {
                            foreach ($mapdata as $pattern => $vouchurl) {
                                if (preg_match($pattern, $link['url'])) {
                                    $vouch = $vouchurl;
                                    break;
                                }
                            }
                        }

                        // discover endpoint and send if supported
                        $supports = $client->discoverWebmentionEndpoint($link['url']);
                        if ($supports) {
                            //dump('Supported!');
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
                            //dump('Not supported!');
                            $link['lastnotified'] = time();
                            $link['laststatus'] = null;
                            $link['lastmessage'] = 'Webmention support not advertised';
                        }
                    }
                }
                unset($link);
            }
        }
        unset($value);

        // Save updated data
        $datafh->save(YAML::dump($data));
        $datafh->free();
    }
}
