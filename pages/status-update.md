---
title: Status Update
robots: noindex,nofollow
template: webmention
routable: true
http_response_code: 200
twig_first: true
process:
  twig: true
---

# {{ 'PLUGIN_WEBMENTION.MESSAGES.STATUS_UPDATE.HEADING'|t }}

|  |  |
| ----- | ----- |
| **{{ 'PLUGIN_WEBMENTION.MESSAGES.STATUS_UPDATE.MENTIONER'|t }}** | {{ config.plugins.webmention._msg.mentioner}} |
| **{{ 'PLUGIN_WEBMENTION.MESSAGES.STATUS_UPDATE.MENTIONEE'|t }}** | {{ config.plugins.webmention._msg.mentionee}} |
| **{{ 'PLUGIN_WEBMENTION.MESSAGES.STATUS_UPDATE.DATE_RECEIVED'|t }}** | {{ config.plugins.webmention._msg.date_received|date("c")}} |
| **{{ 'PLUGIN_WEBMENTION.MESSAGES.STATUS_UPDATE.VALID'|t }}** | {{ config.plugins.webmention._msg.valid}} |
| **{{ 'PLUGIN_WEBMENTION.MESSAGES.STATUS_UPDATE.APPROVED'|t }}** | {{ config.plugins.webmention._msg.approved}} |
