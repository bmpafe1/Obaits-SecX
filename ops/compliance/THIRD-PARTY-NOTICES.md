# Third-Party Notices

The Obaits platform is distributed as an aggregation of separately licensed
components. Each retains its own licence terms; the platform's own AGPLv3
licence in `/LICENSE` applies only to Obaits' own code and does NOT extend to
any component listed here (Guideline section 12).

Full licence texts for each component are collected under
`ops/compliance/licences/` by `collect-licences.sh` and are bundled into the
on-premise ISO.

See `COMPONENT-MANIFEST.md` for deployed versions.

## Capability services

- **Fleet** (Free portion) — MIT for the majority of the repository. The `ee/`
  directory is separately commercially licensed and is not used or distributed.
  https://github.com/fleetdm/fleet
- **Wazuh** — GPLv2. https://github.com/wazuh/wazuh
- **Zabbix** — AGPLv3. https://github.com/zabbix/zabbix
- **CISO Assistant Community** — AGPLv3. The `enterprise/` directory is
  separately commercially licensed and is not used or distributed.
  https://github.com/intuitem/ciso-assistant-community
- **GVM / OpenVAS (Greenbone)** — mixed licences; see upstream.
  https://www.greenbone.net/en/license-information/
- **DFIR-IRIS** — LGPLv3 for the iris-web repository. Individual modules carry
  their own licences. https://github.com/dfir-iris/iris-web
- **lightNVR** — GPLv3. https://github.com/opensensor/lightNVR
- **OWASP ZAP** — Apache 2.0, with bundled third-party components under their
  own licences and notices. https://www.zaproxy.org/

## Supporting infrastructure

- **PostgreSQL** — PostgreSQL Licence
- **MySQL** — GPLv2 with FOSS exception
- **RabbitMQ** — MPL 2.0
- **Keycloak** — Apache 2.0
- **acme-dns** — MIT
- **Headscale** — BSD-3-Clause

## Corresponding source

Several components above are licensed under the GPL, AGPL or LGPL. Where the
platform is distributed as an on-premise appliance, corresponding source for
those components is provided per gap G-08.

**This mechanism is not yet built. It must exist and be tested before the first
ISO is distributed.**

## PHP and JavaScript dependencies

Application dependencies are recorded in the per-release SBOM rather than
enumerated here. See `generate-sbom.sh`.
