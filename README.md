# Webmentions Plugin

The **Webmentions** Plugin is for [Grav CMS](http://github.com/getgrav/grav). It implements the [Webmention protocol](https://www.w3.org/TR/webmention/) with [the Vouch extension](https://indieweb.org/Vouch).

## Installation

## Configuration

Below is the default configuration and an explanation of the different settings. To customize, first copy `webmentions.yaml` to your `user/config/plugins` folder and edit that copy.

```
enabled: true
datadir: webmentions
file_url_name_map: url_name_map.yaml

sender:
  enabled: true
  page_only: true # true | *false*
  automatic: false
  ignore_routes:
    - /random
  file_data: data_sent.yaml
  file_blacklist: sender_blacklist.yaml

receiver:
  enabled: true
  expose_data: true
  advertise_method: header # header | link | *manual*
  route: /mentions
  async: true       # DO NOT SET THIS TO FALSE HASTILY! It opens things up for a DDOS attack.
  status_mode: 202  # 201 | *202*
  ignore_routes:
    - /random
  file_data: data_received.yaml
  file_blacklist: receiver_blacklist.yaml
  blacklist_silently: true

vouch:
  enabled: true
  auto_approve: true
  file_sender_map: vouch_sender_map.yaml
  file_receiver_whitelist: vouch_receiver_whitelist.yaml
  file_receiver_blacklist: vouch_reciever_blacklist.yaml
```

- Grav requires a top-level `enabled` field. This is how you completely disable the plugin.

- The `datadir` field is the name of the subdirectory that will be created in the `user/data` folder that will contain the data files.

- `file_url_name_map` is a file that maps domain/path patterns to human-readable names. It is used by both the `receiver` and `vouch` modules.

- The plugin has four modules, each with its specific config:

  - `sender` is the module that detects external links in your own posts and notifies them of the link.

    - The `enabled` field lets you disable just this module. Note that this does *not* disable the CLI interface! You can still scan for and send webmentions manually via the CLI.

      If set to `true`, however, then whenever a page is rebuilt (cache miss), the plugin checks the data file. If the page hasn't been processed before, or if the `\Grav\Common\Page\Page::modified()` timestamp is later than what plugin has recorded, the page will automatically be processed and the data file updated.

    - The `page_only` field determines what output will actually be scanned for links. If `true`, the scan will happen after the `onPageContentProcessed` event and will only scan the page content for links. If set to `false`, the scan happens after the `onOutputGenerated` event, which will scan the entire content of the &lt;body&gt; tag (for blog set ups, that will include the sidebars, footers, etc.).

    - If `automatic` is set to `true`, then after the page has been scanned and links found, mentions will be sent immediately, before rendering. If the page is link heavy, this could slow down the site.

      If set to `false`, notification will only be sent when triggered by the CLI.

    - The `ignore_routes` field lets you disable the sender module for specific routes.

    - `file_data` is the name of the core data file for sent notifications. It lists all the page ids and their last modified dates as well as all the external links and their status.

    - `file_blacklist` lists domain and path patterns that represent links you never want the plugin to notify.

  - `receiver` is the module that accepts notifications of links to your site.

    - The `enabled` field lets you disable just this module. You can still use the CLI to manage received webmentions. But no new mentions can be received while disabled.

    - If `expose_data` is set to `true`, the plugin will expose to the Grav system via the `config.plugins.webmentions.data` namespace the details about the *verified and approved* webmentions received, ready for use in twig files or other plugins. The format is as follows:

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

    - The `advertise_method` field tells the plugin how you wish to advertise to external clients that you accept webmentions. There are three methods recognized by the spec: in the HTTP header itself (`header`), as a link element in the head of each page (`link`), or as an anchor in the body of the document (`manual`). If you select `header` or `link`, the advertisement will be done automatically by the plugin. Any other value is interpreted as `manual` and means you will have to insert the link yourself.

    - `route` is the route external clients will need to contact to notify you. You can (and probably should) institute rate limiting and other security measures at the server level on this route.

    - `async` determines whether you handle notifications asynchronously or not. **It is strongly recommended that you leave this as `true`!** Otherwise you open yourself up to denial-of-service attacks. 

      If set to `true`, the plugin will store the webmention request but not action it. You will need to use the admin page or the CLI to process incoming requests.

      If set to `false`, the plugin will immediately try to verify the webmention. This opens you up to being used in a DDOS attack! There's really no good reason to ever do this. The option is here purely for completeness.

    - `status_mode` only has meaning if `async` is set to `true`. It determines how the plugin will respond to the request. 

      `202` is the default and it refers to the [HTTP status code](https://en.wikipedia.org/wiki/List_of_HTTP_status_codes) `202 ACCEPTED`. The request is logged and no further status information is available to the mentioner.

      `201` refers to the [HTTP status code](https://en.wikipedia.org/wiki/List_of_HTTP_status_codes) `201 CREATED`. If set, the plugin will create a special route under the `receiver.route` that the requester can refer back to later to find out if the request was ever processed.

      If `async` is set to `true`, then the system returns an appropriate error code after verifying the mention (200, 400, or 500, as per the spec).

    - The `ignore_routes` field lists routes you will not accept webmentions for nor advertise webmention functionality (*see* `advertise_method`).

    - `file_data` is the name of the core data file for received notifications. It lists each page id along with information on the mentioner, voucher, and received and last verified dates.

    - `file_blacklist` lists domain/path patterns from the receiver will automatically deny webmentions from.

    - The `blacklist_silently` field tells the plugin how to handle blacklisted requests. If set to `true`, a 200, 201, or 202 will be returned but the request will be automatically rejected (which will be noted on the status page if you set a `status_mode` of `201`). If set to `false`, the requester will receive a `500 INTERNAL SERVER ERROR` (as per the spec) and deliver a message as defined in the `languages.yaml` file (so as honest or as vague as you want it to be).

  - The `vouch` module implements [the Vouch extension](https://indieweb.org/Vouch) of the original spec.

    - `enabled` turns the module off and on. Note that enabling it does *not* mean that all mentions require vouches! It simply means that the system will accept and process them when they come in.

    - If `auto_approve` is set to `true`, then any mentions that come in with a verified or whitelisted vouch will be automatically approved. Otherwise you have to use the admin page or the CLI to approve, as usual.

    - `file_sender_map` is a YAML file that contains domain/path patterns that are matched by the `sender` module against external links. If and only if a match is found, the `sender` module will include the mapped vouch URL in the webmention notification.

    - `file_receiver_whitelist` lists domain/path patterns of vouch URLs you are willing to accept without any further verification.

    - `file_receiver_blacklist` lists domain/path patterns of vouch URLs that you will never accept.

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

> General note: When inputting regular expressions into YAML files, be sure to use single quotes! This regular expressions are passed directly to the PHP regex engine, so include the leading and trailing forward slash and include any flags at the end (e.g., `/pattern/i` for case insensitive).

### file_url_name_map

This file maps URLs to human-friendly names, useful when displaying mentions in your templates. It should be formatted as follows:

```
- '{regex}': {name}
```

For example, this would map `http://test.example.com` to `Alice's example site`:

```
- '/\/\/alice.example.com/': "Alice's example site"
```

The plugin processes the map file from top to bottom. The first pattern to match wins.

### sender.file_data

This is where all the data about sent webmentions is stored. It's in the following format:

```
{slug}:
  lastmodified: {int from \Grav\Common\Page\Page::modifed()}
  links:
    - url: {full url}
      inpage: {true | false}
      lastnotified: {int timestamp; if blank, notification is pending}
      laststatus: {HTTP status code returned at last check}
      lastmessage: {Any message returned by the receiver when last checked}
```

### sender.file_blacklist

This lists URLs that you do *not* wish to notify of mentions.

```
- '{regex}'
```

For example, you could omit all `wordpress.com` domains as follows:

```
- '/\/\/.*?wordpress\.com/'
```

### receiver.file_data

This stores all the data about webmentions you've received. This data can be exposed to your twig templates by setting `receiver.expose_data` to `true`.

```
{slug}:
  received: {int timestamp}
  source_url: {URL that mentioned you}
  vouch_url: {vouch URL, if it was provided}
  lastchecked: {int timestamp}
  valid: {true | false}
  visible: {true | false}
```

### receiver.file_blacklist

This lists URLs that you never wish to receive mentions from.

```
- '{regex}'
```

For example, you could ignore all mentions from `wordpress.com` domains as follows:

```
- '/\/\/.*?wordpress\.com/'
```

### vouch.file_sender_map

This file maps URLs with a hand-selected vouch URL. This must be manually done.

```
- '{regex}': {vouch URL}
```

For example, you could tell the vouch system to send a link to Bob's cat post whenever you send a mention to Alice (because you and Alice have interacted before, and Alice and Bob have interacted before, but you and Bob haven't interacted before):


```
- '/\/\/alice.example.com/': 'http://bob.example.com/i/love/my/cat'
```

### vouch.file_receiver_whitelist

List of URLs you accept as valid vouches without doing any actual checks. The `lastchecked` field for this link will remain blank until you actually verify it. But the `valid` and possibly `visible` fields will be set to `true`.

```
- '{regex}'
```

### vouch.file_receiver_blacklist

List of URLs you will never accept as valid vouches. The `lastchecked` field for this link will remain blank unless you actually trigger a check. But the `valid` and `visible` fields will be set to `false`.

```
- '{regex}'
```
