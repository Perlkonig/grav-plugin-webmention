<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Common\Config\Config;
use Grav\Common\Page\Page;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Yaml\Yaml;

/**
 * Class WebmentionPlugin
 * @package Grav\Plugin
 */
class WebmentionPlugin extends Plugin
{

    protected $route;

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

        // SENDER
        if ($config->get('plugins.webmention.sender.enabled')) {
            $uri = $this->grav['uri'];
            $path = $uri->path();
            $disabled = (array) $config->get('plugins.webmention.sender.ignore_routes');
            if (!in_array($path, $disabled)) {
                // find mention routes
                $clean = true;
                foreach ($disabled as $route) {
                    if ($this->startsWith($path, $config->get('plugins.webmention.receiver.route'))) {
                        $clean = false;
                        break;
                    }
                }
                if ($clean) {
                    if ($config->get('plugins.webmention.sender.page_only')) {
                        $enabled = $this->add_enable($enabled, 'onPageContentProcessed',  ['onPageContentProcessed', 0]);
                    } else {
                        $enabled = $this->add_enable($enabled, 'onOutputGenerated', ['onOutputGenerated', 0]);
                    }
                }
            }
        }

        // RECEIVER
        if ($config->get('plugins.webmention.receiver.enabled')) {
            // ROUTE
            $uri = $this->grav['uri'];
            $route = $config->get('plugins.webmention.receiver.route');
            if ($route && $this->startsWith($uri->path(), $route)) {
                $enabled = $this->add_enable($enabled, 'onPagesInitialized', ['handleReceipt', 0]);
            }
            $enabled = $this->add_enable($enabled, 'onTwigTemplatePaths', ['onTwigTemplatePaths', 0]);
            // EXPOSE_DATA
            if ($config->get('plugins.webmention.receiver.expose_data')) {
                $enabled = $this->add_enable($enabled, 'onPagesInitialized', ['exposeData', 0]);
            }
            // ADVERTISE
            $advertise = $config->get('plugins.webmention.receiver.advertise_method');
            if ($advertise === 'header') {
                $enabled = $this->add_enable($enabled, 'onPagesInitialized', ['advertise_header', 100]);
            } elseif ($advertise === 'link') {
                $enabled = $this->add_enable($enabled, 'onOutputGenerated', ['advertise_link', 100]);
            }
        }

        $this->enable($enabled);
    }

    private function add_enable ($array, $key, $value) {
        if (array_key_exists($key, $array)) {
            array_push($array[$key], $value);
        } else {
            $array[$key] = [$value];
        }
        return $array;
    }

    private function startsWith($haystack, $needle) {
        // search backwards starting from haystack length characters from the end
        return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== false;
    }    

    private function endsWith($haystack, $needle) {
        // search forward starting from end minus needle length characters
        return $needle === "" || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== false);
    }

    public function exposeData(Event $e) {
        $config = $this->grav['config'];
        $datadir = $config->get('plugins.webmention.datadir');
        $datafile = $config->get('plugins.webmention.receiver.file_data');
        $root = DATA_DIR . $datadir . '/';
        $filename = $root . $datafile;
        $datafh = File::instance($filename);
        $data = Yaml::parse($datafh->content());
        if ($data === null) {
            $data = array();
        }
        $datafh->free();

        $node = null;
        $permalink = $this->grav['page']->permalink();
        if (array_key_exists($permalink, $data)) {
            $node = $data[$permalink];
        }
        if ($node !== null) {
            $config->set('plugins.webmention.data', $node);
        }
    }

    /**
     * Determine whether to advertise the Webmention endpoint on the current page.
     *
     * @param  Uri    $uri    Grav Uri object for the current page.
     * @param  Config $config Grav Config object containing plugin settings.
     *
     * @return boolean
     */
    private function shouldAdvertise(Uri $uri, Config $config) {
        // First check that we do not advertise on the receiver itself.
        if ($this->startsWith($uri->route(), $config->get('plugins.webmention.receiver.route'))) {
            return false;
        }

        // Also do not advertise on any pages for which incoming webmentions are ignored.
        $currentPath = implode('/', array_slice($uri->paths(), 0, -1)) . '/' . $uri->basename();
        $ignorePaths = $config->get('plugins.webmention.receiver.ignore_paths');
        foreach ($ignorePaths as $ignore) {
            if ($this->endsWith($currentPath, $ignore)) {
                return false;
            }
        }

        return true;
    }
    
    public function advertise_header(Event $e) {
        $uri = $this->grav['uri'];
        $config = $this->grav['config'];

        // Check if the current requested URL needs to advertise the endpoint.
        if (!$this->shouldAdvertise($uri, $config)) {
            return;
        }

        // Build and send the Link header.
        $base = $uri->base();
        $rcvr_route = $config->get('plugins.webmention.receiver.route');
        $rcvr_url = $base.$rcvr_route;
        header('Link: <'.$rcvr_url.'>; rel="webmention"', false);
    }

    public function advertise_link(Event $e) {
        $uri = $this->grav['uri'];
        $config = $this->grav['config'];

        // Check if the current requested URL needs to advertise the endpoint.
        if (!$this->shouldAdvertise($uri, $config)) {
            return;
        }

        // Then only proceed if we are working on HTML.
        if ($this->grav['page']->templateFormat() !== 'html') {
            return;
        }

        // After that determine if a HEAD element exists to add the LINK to.
        $output = $this->grav->output;
        $headElement = strpos($output, '</head>');
        if ($headElement === false) {
            return;
        }

        // Build the LINK element.
        $base = $uri->base();
        $rcvr_route = $config->get('plugins.webmention.receiver.route');
        $rcvr_url = $base.$rcvr_route;
        $tag = '<link href="'.$rcvr_url.'" rel="webmention" />';

        // Inject LINK element before the HEAD element's closing tag.
        $output = substr_replace($output, $tag, $headElement, 0);

        // replace output
        $this->grav->output = $output;
    }

    public function handleReceipt(Event $e) {
        // Somebody actually sent us a mention
        $config = $this->grav['config'];
        $datadir = $config->get('plugins.webmention.datadir');
        $datafile = $config->get('plugins.webmention.receiver.file_data');
        $root = DATA_DIR . $datadir . '/';

        if (!empty($_POST)) {
            $source = null;
            $target = null;
            $vouch = null;
            if (isset($_POST['source'])) {
                $source = $_POST['source'];
            }
            if (isset($_POST['target'])) {
                $target = $_POST['target'];
            }
            if ( ($config->get('plugins.webmention.vouch.enabled')) && (isset($_POST['vouch'])) ) {
                $vouch = $_POST['vouch'];
            }

            // Section 3.2.1 Request Verification
            //   $source and $target must be present
            if ( is_null($source) || is_null($target)) {
                $this->throw_400();
                return;
            }
            //   $source, $target, and $vouch (if present) must be valid URLs
            if ( (!filter_var($source, FILTER_VALIDATE_URL)) || (!filter_var($target, FILTER_VALIDATE_URL)) ) {
                $this->throw_400();
                return;
            }
            if ( (!is_null($vouch)) && (!filter_var($vouch, FILTER_VALIDATE_URL)) ) {
                $this->throw_400();
                return;
            }
            //   $source, $target, and $vouch (if present) must be http or https scheme
            if ( (! $this->startsWith($source, 'http://')) && (! $this->startsWith($source, 'https://')) ) {
                $this->throw_400();
                return;
            }
            if ( (! $this->startsWith($target, 'http://')) && (! $this->startsWith($target, 'https://')) ) {
                $this->throw_400();
            }
            if (! is_null($vouch)) {
                if ( (! $this->startsWith($vouch, 'http://')) && (! $this->startsWith($vouch, 'https://')) ) {
                    $this->throw_400();
                    return;
                }
            }
            //   $source must not equal $target
            if ($source === $target) {
                $this->throw_400();
                return;
            }
            //   $vouch (if present) must not equal $source or $target
            if (! is_null($vouch)) {
                if ( ($source === $vouch) || ($target === $vouch) ) {
                    $this->throw_400();
                    return;
                }
            }
            //   $target must accept webmentions
            $accepts = true;
            //     First check host and then check path
            $parts = parse_url($target);
            foreach ($config->get('plugins.webmention.receiver.ignore_paths') as $route) {
                if ($this->endsWith($target, $route)) {
                    $accepts = false;
                    break;
                }
            }
            if ($parts['host'] !== $this->grav['uri']->host()) {
                $accepts = false;
            }
            if (! $accepts) {
                $this->throw_400($this->grav['language']->translate('PLUGIN_WEBMENTION.MESSAGES.BAD_REQUEST_BADROUTE'));
                return;
            }
            //   $source must not be blacklisted
            if ($config->get('plugins.webmention.receiver.blacklist')) {
                $blacklisted = false;
                foreach ($config->get('plugins.webmention.receiver.blacklist') as $pattern) {
                    if (preg_match($pattern, $source)) {
                        $blacklisted = true;
                        break;
                    }
                }
                if ( $blacklisted && (! $config->get('plugins.webmention.receiver.blacklist_silently'))) {
                    $this->throw_403();
                    return;
                }
            }

            // Vouch extension checks
            if ( ($config->get('plugins.webmention.vouch.enabled')) && ($config->get('plugins.webmention.vouch.required')) ) {
                // First delete $vouch if blacklisted
                if ($vouch !== null) {
                    $vblisted = false;
                    if ($config->get('plugins.webmention.vouch.blacklist')) {
                        foreach ($config->get('plugins.webmention.vouch.blacklist') as $pattern) {
                            if (preg_match($pattern, $vouch)) {
                                $vblisted = true;
                                break;
                            }
                        }
                    }
                    if ($vblisted) {
                        $vouch = null;
                    }
                }

                // $vouch is present if $source is not whitelisted
                if ($vouch === null) {
                    $iswhite = false;
                    if ($config->get('plugins.webmention.receiver.whitelist')) {
                        foreach ($config->get('plugins.webmention.receiver.whitelist') as $pattern) {
                            if (preg_match($pattern, $source)) {
                                $iswhite = true;
                                break;
                            }
                        }
                    }
                    if (! $iswhite) {
                        $this->throw_400($this->grav['language']->translate('PLUGIN_WEBMENTION.MESSAGES.BAD_REQUEST_MISSING_VOUCH'));
                        return;
                    }
                }
            }

            // Anything that should trigger an error message should have triggered by now.
            // Now we write this mention to the data file and return the appropriate 2XX response.

            // Store
            //   Load the current data
            $filename = $root . $datafile;
            $datafh = File::instance($filename);
            $datafh->lock();
            $data = Yaml::parse($datafh->content());
            if ($data === null) {
                $data = array();
            }

            //   Does this mention already exist?
            $isdupe = false;
            if (array_key_exists($target, $data)) {
                foreach ($data[$target] as &$entry) {
                    if ($entry['source_url'] === $source) {
                        $isdupe = true;
                        //$entry['received'] = time();
                        $entry['vouch_url'] = $vouch;
                        $entry['source_mf2'] = null;
                        $entry['source_mf2'] = null;
                        $entry['lastchecked'] = null;
                        $entry['lastcode'] = null;
                        $entry['valid'] = null;
                        $entry['visible'] = false;
                        break;
                    }
                }
                unset($entry);
            } else {
                $data[$target] = array();
            }
            $hash = md5($source.'|'.$target);
            if (! $isdupe) {
                $entry = [
                    'source_url' => $source,
                    'hash' => $hash,
                    'received' => time(),
                    'vouch_url' => $vouch,
                    'source_mf2' => null,
                    'vouch_mf2' => null,
                    'lastchecked' => null,
                    'lastcode' => null,
                    'valid' => null,
                    'visible' => false
                ];
                array_push($data[$target], $entry);
            }

            $datafh->save(YAML::dump($data));
            $datafh->free();

            // Respond
            $base = $this->grav['uri']->base();
            $route = $this->grav['uri']->route();
            $rcvr_route = $config->get('plugins.webmention.receiver.route');
            $pages = $this->grav['pages'];
            $page = new Page;
            if ($config->get('plugins.webmention.receiver.status_updates')) {
                $status_url = $base.$rcvr_route.'/'.$hash;
                header('Location: '.$status_url);
                $config->set('plugins.webmention._msg', $status_url);
                $page->init(new \SplFileInfo(__DIR__ . '/pages/201-created.md'));
            } else {
                $page->init(new \SplFileInfo(__DIR__ . '/pages/202-accepted.md'));
            }
            $page->slug(basename($route));
            $pages->addPage($page, $route);        

        } else {
        // Someone is asking about an earlier request
            // get the hash
            $route = $this->grav['uri']->route();

            if ($route === $config->get('plugins.webmention.receiver.route')) {
                $this->throw_405();
                return;
            }

            $hash = end(explode('/', $route));

            // find it
            $filename = $root . $datafile;
            $datafh = File::instance($filename);
            $data = Yaml::parse($datafh->content());
            $datafh->free();
            if ($data === null) {
                return; // Should result in a 404 naturally
            } else {
                $entry = null;
                foreach ($data as $key => $value) {
                    foreach ($value as $link) {
                        if ($link['hash'] === $hash) {
                            $entry = $link;
                            $entry['target'] = $key;
                            break 2;
                        }
                    }
                }
                if ($entry === null) {
                    return; // Should result in a 404 naturally
                }
                
            }

            // output
            $pages = $this->grav['pages'];
            $page = new Page;
            $config->set('plugins.webmention._msg.mentioner', $entry['source_url']);
            $config->set('plugins.webmention._msg.mentionee', $entry['target']);
            $config->set('plugins.webmention._msg.date_received', $entry['received']);
            if ($entry['valid'] === null) {
                $config->set('plugins.webmention._msg.valid', 'Not yet checked');
            } else {
                $config->set('plugins.webmention._msg.valid', ($entry['source_url'] ? 'Yes' : 'No'));
            }
            $config->set('plugins.webmention._msg.approved', ($entry['visible'] ? 'Yes' : 'No'));
            $page->init(new \SplFileInfo(__DIR__ . '/pages/status-update.md'));
            $page->slug(basename($route));
            $pages->addPage($page, $route);        
        }
    }

    private function throw_400($msg = null) {
        if ($msg === null) {
            $msg = $this->grav['language']->translate('PLUGIN_WEBMENTION.MESSAGES.BAD_REQUEST_SPEC');
        }
        $this->grav['config']->set('plugins.webmention._msg', $msg);
        $route = $this->grav['uri']->route();
        $pages = $this->grav['pages'];
        $page = new Page;
        $page->init(new \SplFileInfo(__DIR__ . '/pages/400-bad-request.md'));
        $page->slug(basename($route));
        $pages->addPage($page, $route);        
    }

    private function throw_403() {
        $this->grav['config']->set('plugins.webmention._msg', $msg);
        $route = $this->grav['uri']->route();
        $pages = $this->grav['pages'];
        $page = new Page;
        $page->init(new \SplFileInfo(__DIR__ . '/pages/403-forbidden.md'));
        $page->slug(basename($route));
        $pages->addPage($page, $route);        
    }

    private function throw_405() {
        $route = $this->grav['uri']->route();
        $pages = $this->grav['pages'];
        $page = new Page;
        $page->init(new \SplFileInfo(__DIR__ . '/pages/405-method-not-allowed.md'));
        $page->slug(basename($route));
        $pages->addPage($page, $route);        
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
        //$page = $e['page'];
        $page = $this->grav['page'];

        // process the content
        $this->sender($e, $page->content(), $page);

        // If `automatic` is true, send the notifications
        if ($config->get('plugins.webmention.sender.automatic')) {
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
        if ($config->get('plugins.webmention.sender.automatic')) {
            $this->notify($page);
        }
    }

    private function sender(Event $e, $content, $page)
    {
        $pageid = $page->route();
        $config = $this->grav['config'];
        $datadir = $config->get('plugins.webmention.datadir');
        $datafile = $config->get('plugins.webmention.sender.file_data');
        $root = DATA_DIR . $datadir . '/';

        // Load data file
        $filename = $root . $datafile;
        $datafh = File::instance($filename);
        $datafh->lock(); // Apparently this will create a nonexistent file, too.
        $data = Yaml::parse($datafh->content());
        if ( ($data === null) || (! array_key_exists($pageid, $data)) ){
            $data[$pageid] = [
                'lastmodified' => null,
                'permalink' => $page->permalink(),
                'links' => []
            ];
        }

        // Only do something if the timestamps don't match
        if ($data[$pageid]['lastmodified'] !== $page->modified()) {
            $data[$pageid]['lastmodified'] = $page->modified();
            $data[$pageid]['permalink'] = $page->permalink();
            
            //scan for links
            $client = new \IndieWeb\MentionClient();
            $links = $client->findOutgoingLinks($content);
            //dump('Found outgoing links');
            //dump($links);

            //get blacklist
            $blacklist = $config->get('plugins.webmention.sender.blacklist');

            $whitelinks = array();
            foreach ($links as $link) {
                $clean = true;
                if ($blacklist !== null) {
                    foreach ($blacklist as $pattern) {
                        if (preg_match($pattern, $link)) {
                            $clean = false;
                            break;
                        }
                    }
                }
                if ($clean) {
                    array_push($whitelinks, $link);
                }
            }
            //dump('The following are the whitelisted links:');
            //dump($whitelinks);

            $existing = array();
            //dump($data[$pageid]['links']);
            // Look for existing or missing links
            foreach ($data[$pageid]['links'] as &$prevlink) {
                //dump('Checking previous link: '.$prevlink['url'].' against the following links:');
                //dump($whitelinks);
                array_push($existing, $prevlink['url']);
                if (! in_array($prevlink['url'], $whitelinks)) {
                    //dump('Found missing link: '.$prevlink['url']);
                    $prevlink['inpage'] = false;
                    $prevlink['lastnotified'] = null;
                } else {
                    $prevlink['inpage'] = true;
                    $prevlink['lastnotified'] = null;
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
        $datadir = $config->get('plugins.webmention.datadir');
        $datafile = $config->get('plugins.webmention.sender.file_data');
        $mapfile = $config->get('plugins.webmention.vouch.file_sender_map');
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

        foreach ($data as $pageid => &$pagedata) {
            if ( ($page === null) || ($pageid === $page->route()) ) {
                foreach ($pagedata['links'] as &$link) {
                    //dump('Attempting to notify '.$link['url']);
                    $client = new \IndieWeb\MentionClient();
                    if ($link['lastnotified'] === null) {
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
        unset($pagedata);

        // Save updated data
        $datafh->save(YAML::dump($data));
        $datafh->free();
    }

    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }
}
