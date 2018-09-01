# Webmention Plugin

The **Webmention** Plugin is for [Grav CMS](http://github.com/getgrav/grav). It implements the [Webmention protocol](https://www.w3.org/TR/webmention/) with [the Vouch extension](https://indieweb.org/Vouch).

This plugin is in a beta state. It *does* work, but it has not been extensively tested. It's a young spec and adoption is limited. I encourage people to install it and use it and provide feedback and pull requests. The only way specs like this get adopted is by people using it. Go to.

An [implementation report](https://github.com/w3c/webmention/tree/master/implementation-reports) has been submitted.

## Installation

Installing the Webmention plugin can be done in one of two ways. The GPM (Grav Package Manager) installation method enables you to quickly and easily install the plugin with a simple terminal command, while the manual method enables you to do so via a zip file.

### GPM Installation (Preferred)

The simplest way to install this plugin is via the [Grav Package Manager (GPM)](http://learn.getgrav.org/advanced/grav-gpm) through your system's terminal (also called the command line).  From the root of your Grav install type:

    bin/gpm install webmention

This will install the Webmention plugin into your `/user/plugins` directory within Grav. Its files can be found under `/your/site/grav/user/plugins/webmention`.

### Manual Installation

To install this plugin, just download the zip version of this repository and unzip it under `/your/site/grav/user/plugins`. Then, rename the folder to `webmention`. You can find these files on [GitHub](https://github.com/Perlkonig/grav-plugin-webmention) or via [GetGrav.org](http://getgrav.org/downloads/plugins#extras).

You should now have all the plugin files under

    /your/site/grav/user/plugins/webmention
  
> NOTE: This plugin is a modular component for Grav which requires [Grav](http://github.com/getgrav/grav) and the [Error](https://github.com/getgrav/grav-plugin-error) and [Problems](https://github.com/getgrav/grav-plugin-problems) to operate.

## Configuration

Below is a combination of the default configuration and a sample configuration. An explanation of the various fields follows. To customize, first copy `webmention.yaml` to your `user/config/plugins` folder and edit that copy.

> General note: When inputting regular expressions into YAML files, be sure to use single quotes! This regular expressions are passed directly to the PHP regex engine, so include the leading and trailing forward slash and include any flags at the end (e.g., `/pattern/i` for case insensitive).

```
enabled: true
datadir: webmention
url_name_map: 
  - '/\/\/alice.example.com/': "Alice's example site"

sender:
  enabled: true
  page_only: true 
  automatic: false
  ignore_routes:
    - /random
  file_data: data_sent.yaml
  blacklist: 
    - '/\/\/.*?wordpress\.com/' #regex matching domains you *never* wish to send mentions to

receiver:
  enabled: true   # Because this affects your page data, be sure to clear the cache when changing
  expose_data: true
  advertise_method: header # header | link | *manual*
  route: /mentions
  status_updates: true
  ignore_path:
    - /random
  file_data: data_received.yaml
  blacklist_silently: true
  blacklist:
    - '/\/\/.*?wordpress\.com/' #regex matching domains you *never* wish to acknowledge mentions from
  whitelist:
    - '/\/\/.*?wordpress\.org/' # regex matching domains you will accept without a vouch (only meaningful if `vouch.required` is set to `true`)

vouch:
  enabled: true
  required: false   # Don't set this to `true` lightly!
  auto_approve: valid # white | valid | none
  sender_map: 
    - '/\/\/alice.example.com/': 'http://bob.example.com/i/love/my/cat'
  whitelist:
    - '/\/\/.*?wordpress\.org/' #regex matching domains you automatically accept as a valid vouch
  blacklist:
    - '/\/\/.*?wordpress\.com/' #regex matching domains you do *not* accept as a vouch
```

- Grav requires a top-level `enabled` field. This is how you completely disable the plugin.

- The `datadir` field is the name of the subdirectory that will be created in the `user/data` folder that will contain the data files.

- `url_name_map` is a list of regular expressions that map domain/path patterns to human-readable names. It could be used by your own code that displays mentions.

- The plugin has three modules, each with its specific config:

  - `sender` is the module that detects external links in your own posts and notifies them of the link.

    - The `enabled` field lets you disable just this module. Note that this does *not* disable the CLI interface! You would still be able to send webmentions manually via the CLI, but you wouldn't find new links (the CLI can't scan).

      If set to `true`, whenever a page is rebuilt (cache miss), the plugin checks the data file. If the page hasn't been processed before, or if the `\Grav\Common\Page\Page::modified()` timestamp is later than what plugin has recorded, the page will automatically be processed and the data file updated. Only absolute links are extracted, which should exclude any intrablog links naturally.

    - The `page_only` field determines what output will actually be scanned for links. If `true`, the scan will happen after the `onPageContentProcessed` event and will only scan the page content for links. If set to `false`, the scan happens after the `onOutputGenerated` event, which will scan the entire content of the &lt;body&gt; tag (for blog set ups, that will include the sidebars, footers, etc.).

    - If `automatic` is set to `true`, then after the page has been scanned and links found, mentions will be sent immediately, before rendering. If the page is link heavy, this *will* slow down the site. **Don't do this unless you really mean it!**

      If set to `false`, notification will only be sent when triggered by the CLI.

    - The `ignore_routes` field lets you disable the sender module for specific routes. Note that you do *not* need to include the `receiver.route` in this list. That route and all children of it are automatically screened out.

    - `file_data` is the name of the core data file for sent notifications. It lists all the page routes, their last modified dates, and all the external links and their notification status.

    - `blacklist` lists domain and path patterns that represent links you never want the plugin to notify.

  - `receiver` is the module that accepts notifications of links to your site.

    - The `enabled` field lets you disable just this module. You can still use the CLI to manage received webmentions. But no new mentions can be received while disabled.

    - If `expose_data` is set to `true`, the plugin will expose to the Grav system via the `config.plugins.webmention.data` namespace the details about the *verified and approved* webmentions received, ready for use in twig files or other plugins. See the "File Formats" section for a description of the data format.

    - The `advertise_method` field tells the plugin how you wish to advertise to external clients that you accept webmentions. There are three methods recognized by the spec: in the HTTP header itself (`header`), as a link element in the head of each page (`link`), or as an anchor in the body of the document (`manual`). If you select `header` or `link`, the advertisement will be done automatically by the plugin. Any other value is interpreted as `manual` and means you will have to insert the link yourself.

    - `route` is the route external clients will need to contact to notify you. You can (and probably should) institute rate limiting and other security measures at the server level on this route.

    - `status_updates` determines whether the plugin will allow mentioners to request status updates. 

      `true` is the default and will return the [HTTP status code](https://en.wikipedia.org/wiki/List_of_HTTP_status_codes) `201 CREATED` and will create a special route under the `receiver.route` that the requester can refer back to later to find out if the request was ever processed. There's no harm to system performance as the response is cached.

      `false` will simply return the [HTTP status code](https://en.wikipedia.org/wiki/List_of_HTTP_status_codes) `202 ACCEPTED`. The request is logged and can be processed via the CLI. No further status information is available to the mentioner.

      Synchronous verification is *not* supported by this plugin because it should never be allowed! It opens you up to become a vector in a DDoS attack.

    - The `ignore_paths` field lists paths you will not accept webmentions for nor advertise webmention functionality (*see* `advertise_method`). This is just the final path, not the formal route, which Grav can't derive from an arbitrary URL. If the target URL *ends with* any of the listed paths, the webmention will not be accepted.

    - `file_data` is the name of the core data file for received notifications. It lists each page URL along with information on the mentioner, voucher, and received and last verified dates. **This data cannot be simply regenerated or derived!** It is strongly recommended that you periodically back this file up to prevent loss or corruption.

    - `whitelist` is a list of domain/path patterns for receivers you wish to accept without vouches. This is only meaningful if `vouch.required` is set to true.

    - `blacklist` list of domain/path patterns for receivers you never wish to acknowledge webmentions from.

    - The `blacklist_silently` field tells the plugin how to handle blacklisted requests. If set to `true`, a 201 or 202 will be returned (as configured) but the request will be automatically rejected (which will be noted on the status page if you set a `status_mode` of `201`). If set to `false`, the requester will receive a `403 FORBIDDEN` and deliver a message as defined in the `languages.yaml` file (as honest or as vague as you want it to be).

  - The `vouch` module implements [the Vouch extension](https://indieweb.org/Vouch) of [the Webmention spec](https://www.w3.org/TR/webmention/).

    - `enabled` turns the module off and on. Note that enabling it does *not* mean that all mentions require vouches! It simply means that the system will accept and process them when they come in.

    - `required` makes the submission of valid vouches mandatory for received mentions.

    - `auto_approve` has three modes: `white`, `valid`, and anything else (usually `none`). It only applies if `required` is set to `true`.

      If set to `white`, then only mentions with whitelisted vouches will be automatically set as visible. If set to `valid`, the only mentions with validated vouches (contains a link to the mentioner) will be automatically set to visible. Any other value will result in the mention *not* being marked as visible, requiring you to manually do so.

    - `sender_map` is a list of domain/path patterns that are matched by the `sender` module against external links. If and only if a match is found, the `sender` module will include the mapped vouch URL in the webmention notification.

      For example, you could tell the vouch system to send a link to Bob's cat post whenever you send a mention to Alice (because you and Alice have not interacted before, but Alice and Bob have interacted before, and you and Bob have interacted before). You're asserting to Alice that you have a mutual "acquaintance":


      ```
      - '/\/\/alice.example.com/': 'http://bob.example.com/i/love/my/cat'
      ```

    - `whitelist` is a list of domain/path patterns matching URLs you accept as valid vouches without doing any actual checks. This is only meaningful if `auto_approve` is set to `white`. 

    - `blacklist` is a list of domain/path patterns matching URLs you will never accept as valid vouches. If a blacklisted vouch is given, it is ignored, and the webmention is dealt with as if no vouch was sent.

## Usage

The general principle is that the plugin will automatically handle collection of data, but user intervention is needed for the actual notification, verification, and moderation portion. Most of this is scriptable, though.

Also, this plugin does not display any data itself. It will make data available to the Grav system, but it is up to you to incorporate received mentions into your themes.

### Sender

If enabled, the system will scan pages on a cache miss and collect any external links into the data file. You would then use the CLI (described below) to actually notify those links.

There *is* a `sender.automatic` feature that will try to send notifications right away, but this will definitely slow down the page rendering. You would only want to do this if you were [precaching pages](https://github.com/getgrav/grav-plugin-precache) or had some other specific use case.

Ideally there would be a CLI scanner, but the Grav CLI system doesn't appear to have access to page routes.

### Receiver

This module creates a special route that others can use to notify you of mentions. It also handles correctly advertising the route to others. It can provide status updates to mentioners as well. 

It synchronously performs certain basic checks (as per the spec) and enforces any `vouch` requirements, but the actual verification (fetching and examining of resources) happens offline via the CLI (described below).

### Vouch

The Vouch extension is a way of establishing "trust". It demonstrates to first-time contact that you have mutual "acquaintances" (Alice knows Bob, Bob knows Charlie, so when Alice first reaches out to Charlie, she mentions Bob, who they both know). You can safely enable this module without alienating mentioners. If `vouch.required` is set to `false`, then you can accept and process vouches without requiring them.

### Admin Page

The plugin can be fully configured through the admin interface.

### Command-Line Interface

This is where the action happens. The key commands can be run by cron with minimal discomfort. You can of course manually edit the data files yourself—they're pure YAML—but the CLI provides a convenient interface to most of the things you'd need to do. 

To execute a command, go to the root of your Grav install and type `bin/plugin webmention`. That will list the various commands available. For more information, [visit the Grav CLI documentation](https://learn.getgrav.org/cli-console/grav-cli-plugin).

There are currently three commands available.

#### notify

`bin/plugin webmention notify`

`notify` does just that—examines the data file containing the external links collected by the `sender` module and notifies them of the mention. It also provides two cleanup services:

  - When you remove a link from a page, the link stays in the data file so the link can be notified of the change. Once notified, though, the link can be culled from the data file. After the initial notification is complete, the code looks for such links (links that have been removed, have been notified, *and* whose receiver replied with a 2XX code) and offers to delete them for you.

    If manually processing the files, look for links where `inpage` is set to false.

  - After a set number of days, the system can retry failed notifications if desired.

##### Parameters

From the command line, type `bin/plugin webmention help notify` for the authoritative documentation.

- `--old=X` sets the threshold for "old" entries to retry. The default is 30.

- `-y, --autoconfirm` answers "yes" to all prompts.

- `-x, --suppressinfo` implies `autoconfirm` and doesn't display the counts of records. It only produces output if records are actually notified or deleted.

- `-q` suppresses all output and also implies `autoconfirm`. 

#### verify

`bin/plugin webmention verify`

The `verify` command examines the data file of received webmentions and verifies that they actually link back to you. It also performs two cleanup functions:

  - After a set number of days, it will offer to reverify existing verified mentions.

  - If ever the verification process returns `410 GONE`, it means that post has been intentionally and permanently deleted. The script will offer to delete those for you.

##### Parameters

From the command line, type `bin/plugin webmention help verify` for the authoritative documentation.

- `--old=X` sets the threshold for "old" entries to reverify. The default is 30.

- `-y, --autoconfirm` answers "yes" to all prompts.

- `-x, --suppressinfo` implies `autoconfirm` and doesn't display the counts of records. It only produces output if records are actually notified or deleted.

- `-q` suppresses all output and also implies `autoconfirm`. 

#### delete

`bin/plugin webmention delete /route/to/delete`

The `delete` command is simple. Once you have decided to delete a post, and you are sure the URL now returns `410 GONE` ([see the Graveyard plugin](https://github.com/Perlkonig/grav-plugin-graveyard)), you need to notify any external links. Simply run this command. It will notify any links and then delete the entry from the data file.

During the notification process, it will output status codes. If any notifications fail, you can choose to not yet delete the entry from the data file so you can retry later.

## File Formats

The plugin works with data files in the `user/data` folder. Here's a list of each file (by config name) and how the plugin expects them to be formatted. These are pure YAML files, so they can be easily read and edited manually. They can also be easily corrupted! Be careful and backup often. The receiver data in particular cannot be regenerated or derived. If lost, you'd have to wait for senders to resend.

### sender.file_data

This is where all the data about sent webmentions are stored. It's in the following format:

```
{route}:
  lastmodified: {int from \Grav\Common\Page\Page::modifed()}
  permalink: {\Grav\Common\Page\Page::permalink(); necessary because the CLI can't access individual pages programmatically, apparently}
  links:
    - url: {full url}
      inpage: {true | false}
      lastnotified: {int timestamp; if blank, notification is pending}
      laststatus: {HTTP status code returned at last check}
      lastmessage: {Any message returned by the receiver when last checked}
```

### receiver.file_data

This stores all the data about webmention you've received. This data can be exposed to your twig templates by setting `receiver.expose_data` to `true`.

```
{target_url (the full url, not just the route)}:
  - source_url: {URL that mentioned you} # this is the key
    hash: {md5 of slug, source, and received; only used for 201 responses}
    vouch_url: {vouch URL, if it was provided}
    source_mf2: {extracted microformat2 codes, if present}
    vouch_mf2: {extracted microformat2 codes, if present}
    received: {int timestamp}
    lastchecked: {int timestamp}
    valid: {true | false}
    visible: {true | false}
```

## Restrictions & Other Weirdness

For those looking at the source code and going, "Holy heck why did he do it that way?", let me try to explain :)

  - I'm not a PHP native. I find it an extremely frustrating language. I am so very open to pull requests to clean things up.

  - The `sender.file_data` is indexed by route instead of by slug because of issues in the receiver module. I should have written it down at that moment because I no longer remember why. I just know it used to be by slug and then I had to change it to route for some reason. 

    I need the `permalink` field because the CLI cannot build full URIs from route information. The CLI has access to config, but that appears to be about it. It's why I can't create a `scan` command.

  - The `receiver.file_data` is keyed by full URL because I can't get Grav to recognize and parse a URL passed in full. This is why it's `receiver.ignore_paths` instead of `receiver.ignore_routes`. The system makes sure the host is right and that the path doesn't end with one of the ignored strings.

    Yes this means that if you change domains or your route structure (without appropriate redirects) your mentions will stop showing up in your themes. (a) That's probably a good thing because the sender has no way of knowing you moved anyway and the link would be dead. (b) You can trivially fix this with some sort of grep.

  - The MF2 data is just dumped as is. There are no checks around usefulness nor consistency in how it is formatting. Since I'm an MF2 novice, I welcome pull requests around how to approach this.

## Todo / Wishlist

- [ ] Force JSON output for status update, regardless of extension
- [ ] Translate CLI messages
- [ ] Add HTML interface for data files
- [ ] More certainty around nature of returned MF2 data
- [ ] Create a CLI for scanning for external links.

## Credits

I've incorporated the following libraries into this plugin:

  - [IndieWeb/MentionClient](https://github.com/indieweb/mention-client-php) for discovering endpoints and sending notifications.

  - [php-mf2](https://github.com/indieweb/php-mf2) used by IndieWeb/MentionClient to resolve relative URLs and by my code to extract MF2 data from mentioners and vouchers.
