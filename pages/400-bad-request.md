---
title: Bad Request
robots: noindex,nofollow
template: default
routable: false
http_response_code: 400
twig_first: true
process:
  twig: true
---

{{ 'PLUGIN_WEBMENTION.MESSAGES.BAD_REQUEST'|t }}

{{ config.plugins.webmention._msg }}
