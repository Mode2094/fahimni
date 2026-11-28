# Optional Nginx server blocks (TLS).

Copy `https.conf.example` → `https.conf` to enable a TLS listener, and place

    fullchain.pem   (public certificate chain)
    privkey.pem     (private key)

into `./certs/` (see `CERTS_DIR` in docker-compose.yml) BEFORE starting.

Without a `*.conf` file here nginx boots plain-HTTP only — the enabled state
never breaks the stack when certificates are missing.

For the Caddy-fronted launch (`deploy/caddy/fahimni-standalone.caddy`), keep this
directory free of `*.conf`: the Caddy edge terminates TLS on 80/443 and proxies
to nginx on loopback. Enabling a direct nginx TLS listener is only for
deployments without Caddy.