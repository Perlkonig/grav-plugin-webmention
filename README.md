# Webmention Plugin

The **Webmention** Plugin is for [Grav CMS](http://github.com/getgrav/grav). It implements the [Webmention protocol](https://www.w3.org/TR/webmention/) with [the Vouch extension](https://indieweb.org/Vouch).

**This plugin is still a work in progress! Do not install at all, yet.**

## Installation

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
  ignore_routes:
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
  auto_approve: true
  sender_map: 
    - '/\/\/alice.example.com/': 'http://bob.example.com/i/love/my/cat'
  whitelist:
    - '/\/\/.*?wordpress\.org/' #regex matching domains you automatically accept as a valid vouch
  blacklist:
    - '/\/\/.*?wordpress\.com/' #regex matching domains you do *not* accept as a vouch
```

- Grav requires a top-level `enabled` field. This is how you completely disable the plugin.

- The `datadir` field is the name of the subdirectory that will be created in the `user/data` folder that will contain the data files.

- `file_url_name_map` is a file that maps domain/path patterns to human-readable names. It is used by both the `receiver` and `vouch` modules.

- The plugin has four modules, each with its specific config:

  - `sender` is the module that detects external links in your own posts and notifies them of the link.

    - The `enabled` field lets you disable just this module. Note that this does *not* disable the CLI interface! You can still scan for and send webmention manually via the CLI.

      If set to `true`, however, then whenever a page is rebuilt (cache miss), the plugin checks the data file. If the page hasn't been processed before, or if the `\Grav\Common\Page\Page::modified()` timestamp is later than what plugin has recorded, the page will automatically be processed and the data file updated.

    - The `page_only` field determines what output will actually be scanned for links. If `true`, the scan will happen after the `onPageContentProcessed` event and will only scan the page content for links. If set to `false`, the scan happens after the `onOutputGenerated` event, which will scan the entire content of the &lt;body&gt; tag (for blog set ups, that will include the sidebars, footers, etc.).

    - If `automatic` is set to `true`, then after the page has been scanned and links found, mentions will be sent immediately, before rendering. If the page is link heavy, this *will* slow down the site. Don't do this unless you really mean it!

      If set to `false`, notification will only be sent when triggered by the CLI.

    - The `ignore_routes` field lets you disable the sender module for specific routes.

    - `file_data` is the name of the core data file for sent notifications. It lists all the page ids and their last modified dates as well as all the external links and their status.

    - `blacklist` lists domain and path patterns that represent links you never want the plugin to notify.

  - `receiver` is the module that accepts notifications of links to your site.

    - The `enabled` field lets you disable just this module. You can still use the CLI to manage received webmention. But no new mentions can be received while disabled.

    - If `expose_data` is set to `true`, the plugin will expose to the Grav system via the `config.plugins.webmention.data` namespace the details about the *verified and approved* webmention received, ready for use in twig files or other plugins. The format is as follows:

      ```
      data:
        {pageid}:
          - mentioner_url: {mentioner URL}
            mentioner_name: {mentioner name, if mapped}
            date_received: {date received}
            date_verified: {date verified (will change over time)}
            voucher_url: {voucher URL, if supplied}
            voucher_name: {voucher name, if supplied and mapped}
          - ...
      ```

    - The `advertise_method` field tells the plugin how you wish to advertise to external clients that you accept webmention. There are three methods recognized by the spec: in the HTTP header itself (`header`), as a link element in the head of each page (`link`), or as an anchor in the body of the document (`manual`). If you select `header` or `link`, the advertisement will be done automatically by the plugin. Any other value is interpreted as `manual` and means you will have to insert the link yourself.

    - `route` is the route external clients will need to contact to notify you. You can (and probably should) institute rate limiting and other security measures at the server level on this route.

    - `status_updates` determines whether the plugin will allow mentioners to request status updates. 

      `true` is the default and will return the [HTTP status code](https://en.wikipedia.org/wiki/List_of_HTTP_status_codes) `201 CREATED` and will create a special route under the `receiver.route` that the requester can refer back to later to find out if the request was ever processed. There's no harm to system performance as the response is cached.

      `false` will simply return the [HTTP status code](https://en.wikipedia.org/wiki/List_of_HTTP_status_codes) `202 ACCEPTED`. The request is logged and can be processed via the CLI. No further status information is available to the mentioner.

      Synchronous verification is *not* supported by this plugin because it should never be allowed! It opens you up to become a vector in a DDoS attack.

    - The `ignore_routes` field lists routes you will not accept webmention for nor advertise webmention functionality (*see* `advertise_method`).

    - `file_data` is the name of the core data file for received notifications. It lists each page id along with information on the mentioner, voucher, and received and last verified dates. **This data cannot be simply regenerated or derived!** It is strongly recommended that you periodically back this file up to prevent loss or corruption.

    - `whitelist` is a list of domain/path patterns for receivers you wish to accept without vouches. This is only meaningful if `vouch.required` is set to true.

    - `blacklist` list of domain/path patterns for receivers you never wish to acknowledge webmentions from.

    - The `blacklist_silently` field tells the plugin how to handle blacklisted requests. If set to `true`, a 200, 201, or 202 will be returned but the request will be automatically rejected (which will be noted on the status page if you set a `status_mode` of `201`). If set to `false`, the requester will receive a `500 INTERNAL SERVER ERROR` (as per the spec) and deliver a message as defined in the `languages.yaml` file (as honest or as vague as you want it to be).

  - The `vouch` module implements [the Vouch extension](https://indieweb.org/Vouch) of the original spec.

    - `enabled` turns the module off and on. Note that enabling it does *not* mean that all mentions require vouches! It simply means that the system will accept and process them when they come in.

    - `required` makes the submission of valid vouches mandatory for

    - If `auto_approve` is set to `true`, then any mentions that come in with a verified or whitelisted vouch will be automatically approved. Otherwise you have to use the admin page or the CLI to approve, as usual.

    - `sender_map` is a YAML file that contains domain/path patterns that are matched by the `sender` module against external links. If and only if a match is found, the `sender` module will include the mapped vouch URL in the webmention notification.

      For example, you could tell the vouch system to send a link to Bob's cat post whenever you send a mention to Alice (because you and Alice have not interacted before, but Alice and Bob have interacted before, and you and Bob have interacted before). You're asserting to Alice that you have mutual "acquaintances":


      ```
      - '/\/\/alice.example.com/': 'http://bob.example.com/i/love/my/cat'
      ```

    - `whitelist` is a list of domain/path patterns matching URLs you accept as valid vouches without doing any actual checks. This is only meaningful if `auto_approve` is set to `true`. The `lastchecked` field for this link will remain blank until you actually verify it. But the `valid` and possibly `visible` fields will be set to `true`.

    - `blacklist` is a list of domain/path patterns matching URLs you will never accept as valid vouches. If a blacklisted vouch is given, it is ignored, and the webmention is dealt with as if no vouch was sent.


## Usage

### Sender

### Receiver

### Vouch

### Admin Page

Unfortunately I have no experience with the Grav admin system, nor do I ever plan on using it, so this plugin currently only supports command-line interaction. I warmly welcome pull requests that would provide HTML interaction with the config and data.

### Command-Line Interface

## Customization

## File Formats

The plugin works with data files in the `user/data` folder. Here's a list of each file (by config name) and how the plugin expects them to be formatted.

### sender.file_data

This is where all the data about sent webmention is stored. It's in the following format:

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
{route}:
  - source_url: {URL that mentioned you} # this is the key
    hash: {md5 of slug, source, and received; only used for 201 responses}
    vouch_url: {vouch URL, if it was provided}
    received: {int timestamp}
    lastchecked: {int timestamp}
    valid: {true | false}
    visible: {true | false}
```

## Credits

I've incorporated the following libraries into this plugin:

  - [IndieWeb/MentionClient](https://github.com/indieweb/mention-client-php) for discovering endpoints and sending notifications.

  - [php-mf2](https://github.com/indieweb/php-mf2) used by IndieWeb/MentionClient to resolve relative URLs.
