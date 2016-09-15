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
  automatic: false
  ignore_routes:
    - /random
  file_data: data_sent.yaml
  file_blacklist: sender_blacklist.yaml

receiver:
  enabled: true
  exposed: true
  advertise_method: header # *header* | link | manual
  route: /mentions
  async: true       #DO NOT SET THIS TO FALSE HASTILY! It opens things up for a DOS attack.
  status_mode: 202 # 201 | *202*
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

admin:
  enabled: true
  route: /mentions/admin
```

- Grav requires a top-level `enabled` field. This is how you completely disable the plugin.

- The `datadir` field is the name of the subdirectory that will be created in the `user/data` folder that will contain the data files.

- `file_url_name_map` is a file that maps domain/path patterns to human-readable names. It is used by both the `receiver` and `vouch` modules.

- The plugin has four modules, each with its specific config:

  - `sender` is the module that detects external links in your own posts and notifies them of the link.

    - The `enabled` field lets you disable just this module.

    - If `automatic` is set to `true`, then whenever a page is rebuilt (cache miss), the plugin checks the data file. If the page hasn't been processed before, or if the `\Grav\Common\Page\Page::modified()` timestamp is later than what plugin has recorded, the page will automatically be processed and notifications sent.

      If set to `false`, pages will only be scanned when triggered from the admin page or from the CLI.

    - The `ignore_routes` field lets you disable the sender module for specific routes.

    - `file_data` is the name of the core data file for sent notifications. It lists all the page ids and their last modified dates as well as all the external links and their status.

    - `file_blacklist` lists domain and path patterns that represent links you never want the plugin to notify.

  - `receiver` is the module that accepts notifications of links to your site.

    - The `enabled` field lets you disable just this module.

    - If `exposed` is set to `true`, the plugin will expose to the Grav system via the `config.plugins.webmentions.data` namespace the details about the *verified and approved* webmentions received, ready for use in twig files or other plugins. The format is as follows:

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

      If set to `false`, the plugin will immediately try to verify the webmention. This opens you up to a denial-of-service attack! There's really no good reason to ever do this. The option is here purely for completeness.

    - `status_mode` only has meaning if `async` is set to `true`. It determines how the plugin will respond to the request. 

      `202` is the default and it refers to the [HTTP status code](https://en.wikipedia.org/wiki/List_of_HTTP_status_codes) `202 ACCEPTED`. The request is logged and no further status information is available to the mentioner.

      `201` refers to the [HTTP status code](https://en.wikipedia.org/wiki/List_of_HTTP_status_codes) `201 CREATED`. If set, the plugin will create a special route under the `receiver.route` that the requester can refer back to later to find out if the request was ever processed.

      If `async` is set to `true`, then the system returns an appropriate error code after verifying the mention (200, 400, or 500, as per the spec).

    - The `ignore_routes` field lists routes you will not accept webmentions for nor advertise webmention functionality (*see* `advertise_method`).

    - `file_data` is the name of the core data file for received notifications. It lists each page id along with information on the mentioner, voucher, and received and last verified dates.

    - `file_blacklist` lists domain/path patterns from the receiver will automatically deny webmentions from.

    - The `blacklist_silently` field tells the plugin how to handle blacklisted requests. If set to `true`, a 200, 201, or 202 will be returned but the request will be automatically rejected (which will be noted on the status page if you set a `status_mode` of `201`). If set to `false`, the requester will receive a `500 INTERNAL SERVER ERROR` (as per the spec) and deliver a message as defined in the `languages.yaml` file (so as honest or as vague as you want it to be).

  - The `vouch` module implements [the Vouch extension](https://indieweb.org/Vouch) of the original spec.

    - `enabled` turns the module off and on. Note that enabling it does **not** mean that all mentions require vouches! It simply means that the system will accept and process them when they come in.

    - If `auto_approve` is set to `true`, then any mentions that come in with a verified or whitelisted vouch will be automatically approved. Otherwise you have to use the admin page or the CLI to approve, as usual.

    - `file_sender_map` is a YAML file that contains domain/path patterns that are matched by the `sender` module against external links. If and only if a match is found, the `sender` module will include the mapped vouch URL in the webmention notification.

    - `file_receiver_whitelist` lists domain/path patterns of vouch URLs you are willing to accept without any further verification.

    - `file_receiver_blacklist` lists domain/path patterns of vouch URLs that you will never accept.

  - Finally there is the `admin` module, which lets you see and manipulate the data.

    - Use the `enabled` field to turn this module off an on. Even if `false`, the CLI will still function normally.

    - The `route` is where you point your browser to access the admin page. **This route must be access controlled in some way!!** There are numerous Grav plugins that can do this, or you can do it at the server level. **This plugin, though, does not enforce any access controls itself!!**

## Usage

### Sender

### Receiver

### Vouch

### Admin page

### Command-Line Interface

## Customization


