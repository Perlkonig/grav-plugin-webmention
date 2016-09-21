---
title: Bad Request
robots: noindex,nofollow
template: webmention
routable: true
http_response_code: 400
twig_first: true
process:
  twig: true
---

{{ 'PLUGIN_WEBMENTION.MESSAGES.BAD_REQUEST'|t }}

{{ config.plugins.webmention._msg }}
