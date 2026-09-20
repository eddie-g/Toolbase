<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Whose X-Forwarded-* headers are believed. Production sits behind a load
| balancer or ingress that terminates TLS: without trusting it the app never
| sees https, secure cookies are not sent, and every client shares the
| proxy's address in the rate limiters.
|
| Not "*". In Laravel that means "believe whoever connects directly". Behind
| exactly one proxy, with no other way in, that happens to be right. But if
| the app can be reached directly (an open port, a second internal path), the
| visitor is then "the proxy": their own X-Forwarded-For is believed, and they
| can pick an address and step around every per-IP limit (the login throttle,
| guest uploads) and forge the audit trail. And behind two hops (a CDN before
| the ingress) every visitor gets the first hop's address. With the proxies
| named, the header is read from the right, trusted hops are skipped, and the
| first address that is not a proxy is the visitor, however many hops there
| are; a connection from anywhere else is not believed at all.
|
| The default trusts loopback and the private ranges: an ingress (Azure
| Container Apps, a Kubernetes ingress, an ALB) reaches the app from a
| private subnet, and nothing on the public internet can. Narrow it to the
| ingress's own subnet when that is known: TRUSTED_PROXIES=10.0.0.0/23
|
*/

return [
    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7'),
];
