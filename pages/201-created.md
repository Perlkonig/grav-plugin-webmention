---
title: Created
robots: noindex,nofollow
template: webmention
routable: true
http_response_code: 201
twig_first: true
process:
  twig: true
---

{{ 'PLUGIN_WEBMENTION.MESSAGES.CREATED'|t }}

You can check the following URL for a status update: {{ config.plugins.webmention._msg }}

