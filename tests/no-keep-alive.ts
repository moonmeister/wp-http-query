import { Agent, setGlobalDispatcher } from 'undici';

/**
 * Every fetch in the suite on a fresh connection. Tests spend seconds in
 * WP-CLI between requests, and a pooled socket Apache closed in the meantime
 * fails the next fetch with "other side closed". A network's sites share one
 * origin, so one pool: there it bites on a request to the other site.
 * pipelining: 0 is undici's switch for no keep-alive.
 */
setGlobalDispatcher( new Agent( { pipelining: 0 } ) );
