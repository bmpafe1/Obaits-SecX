# Component & Licence Manifest

Third-party capability services deployed alongside the Obaits control plane.
Required by the Open-Source Compliance & Architecture Guideline v3.0, section 9
and release gate 14.

**This file must be updated whenever any component is upgraded, and a licence
re-review performed at the same time.**

Last verified: 2026-08-24

## Capability services

| Component | Version | Image / package | Licence |
|---|---|---|---|
| Fleet (Free) | 4.88.0 | `fleetdm/fleet:latest` UNPINNED | MIT for most of the repo; `ee/` separately commercially licensed; Free portion only |
| Wazuh | 4.9.2-1 | `wazuh-manager`, `wazuh-indexer`, `wazuh-dashboard` (deb) | GPLv2, plus Wazuh's own derivative-work interpretation on redistribution (G-09) |
| Zabbix | 7.0 | `zabbix/zabbix-server-pgsql:alpine-7.0-latest` UNPINNED | AGPLv3, deployed unmodified |
| CISO Assistant | latest UNPINNED | `ghcr.io/intuitem/ciso-assistant-community/backend:latest` | AGPLv3 Community only; `enterprise/` NOT used |
| GVM / OpenVAS | stable UNPINNED | `registry.community.greenbone.net/community/gvmd:stable` | Mixed; Community Feed is a SEPARATE licensing object (G-11) |
| DFIR-IRIS | v2.4.29 | `ghcr.io/dfir-iris/iriswebapp_{app,nginx,db}:v2.4.29` | LGPLv3 for iris-web; modules NOT automatically covered (G-15) |
| lightNVR | latest UNPINNED | `ghcr.io/opensensor/lightnvr:latest` | GPLv3; commercial route also exists upstream |
| OWASP ZAP | TO RECORD | TO RECORD | Apache 2.0; bundled deps carry their own notices (G-12) |

## Supporting infrastructure

| Component | Version | Image | Licence |
|---|---|---|---|
| PostgreSQL | 16 | `postgres:16`, `postgres:16-alpine` | PostgreSQL Licence |
| MySQL | 8.0.44 | `mysql:8.0.44` | GPLv2 with FOSS exception |
| RabbitMQ | 3 | `rabbitmq:3-management-alpine` UNPINNED | MPL 2.0 |
| Keycloak | latest UNPINNED | `quay.io/keycloak/keycloak:latest` | Apache 2.0 |
| Keycloak DB | UNIDENTIFIED | image id `fe03a7605299`, no tag | UNKNOWN until identified |
| acme-dns | latest UNPINNED | `joohoi/acme-dns:latest` | MIT |
| Headscale | TO RECORD | per-tenant instances | BSD-3-Clause |

## Datasets — separate licensing objects from application code

Guideline section 9 requires feeds and datasets to be treated as distinct
compliance objects from the software that consumes them. The following are
fetched by the platform and their contents redistributed to enrolled customer
endpoints as part of a commercial service.

**Redistribution terms have NOT been reviewed for any of these. See gap G-14.**

| Feed | Used for | Source |
|---|---|---|
| Spamhaus DROP | IP block ranges | https://www.spamhaus.org/drop/drop.txt |
| URLhaus | Domain blocking | https://urlhaus.abuse.ch/ |
| hagezi Threat Intelligence Feeds | Domain blocking | https://github.com/hagezi/dns-blocklists |
| ThreatFox (abuse.ch) | IPs and domains | https://threatfox.abuse.ch/ |
| Greenbone Community Feed | Vulnerability scanning | https://www.greenbone.net/en/license-information/ |

## Deployed images — digests as running on 2026-08-24

Captured from the running system. A digest identifies the exact image
regardless of what a floating tag later points to. Compose files should
pin to these; until they do, a restart may silently change versions.

| Container | Tag reference | Digest |
|---|---|---|
| `acme-dns` | `joohoi/acme-dns:latest` | `sha256:8d327df58b1c9f6361c76734f9761a108fa4f12cf7ca94440a0404a28f75de62` |
| `acme-dns-db` | `postgres:16-alpine` | `sha256:57c72fd2a128e416c7fcc499958864df5301e940bca0a56f58fddf30ffc07777` |
| `lightnvr-nofia-sa` | `ghcr.io/opensensor/lightnvr:latest` | `sha256:8ef50c422f4d04fa61f2c1323238371c12bf1ce85e6a9fd1019428fea7b9b930` |
| `lightnvr-aicexperts` | `ghcr.io/opensensor/lightnvr:latest` | `sha256:8ef50c422f4d04fa61f2c1323238371c12bf1ce85e6a9fd1019428fea7b9b930` |
| `iriswebapp_worker` | `ghcr.io/dfir-iris/iriswebapp_app:v2.4.29` | `sha256:d7d23026bdde593278075821d3abc8649fe28d618d5784cc9875bd7a3d05699b` |
| `iriswebapp_nginx` | `ghcr.io/dfir-iris/iriswebapp_nginx:v2.4.29` | `sha256:887b1eb8ceae5b1e1483a4a7601d79a6b070e314db5772a617e0a9190c556644` |
| `iriswebapp_app` | `ghcr.io/dfir-iris/iriswebapp_app:v2.4.29` | `sha256:d7d23026bdde593278075821d3abc8649fe28d618d5784cc9875bd7a3d05699b` |
| `iriswebapp_rabbitmq` | `rabbitmq:3-management-alpine` | `sha256:606d8c0d6b3c18d1da9afc53bc7cdb2a8d5486df91b5a9830e9e07626c9ae281` |
| `iriswebapp_db` | `ghcr.io/dfir-iris/iriswebapp_db:v2.4.29` | `sha256:081ad194332887ca584a9240e8e9dea94129a235e8b38ff42c1938358be432f5` |
| `zabbix-server` | `zabbix/zabbix-server-pgsql:alpine-7.0-latest` | `sha256:97cac0f85f85e82cc5f584ce7958bd3de2b6eec6c5ce7b0df49626471024cad2` |
| `zabbix-web` | `zabbix/zabbix-web-nginx-pgsql:alpine-7.0-latest` | `sha256:f8c2638904def2b610045726d37504271f31ec2ff1093d37d133ebdc24acfb0f` |
| `zabbix-postgres` | `postgres:16-alpine` | `sha256:57c72fd2a128e416c7fcc499958864df5301e940bca0a56f58fddf30ffc07777` |
| `fleet` | `fleetdm/fleet:latest` | `sha256:58c8b5e385329037d48e17c21a9274527854e772749cb0fd7ea0b4a4173f696e` |
| `fleet-mysql` | `mysql:8.0.44` | `sha256:9c3380eac945af0736031b200027f581925927c81e010056214a4bd6b6693714` |
| `huey` | `ghcr.io/intuitem/ciso-assistant-community/backend:latest` | `sha256:410a85453668a78d846b66994bd00fef5e189205e86d0f8e573c075ed98daa20` |
| `backend` | `ghcr.io/intuitem/ciso-assistant-community/backend:latest` | `sha256:410a85453668a78d846b66994bd00fef5e189205e86d0f8e573c075ed98daa20` |
| `postgres` | `postgres:16` | `sha256:33f923b05f64ca54ac4401c01126a6b92afe839a0aa0a52bc5aeb5cc958e5f20` |
| `keycloak` | `quay.io/keycloak/keycloak:latest` | `sha256:0aae0de7fca85525f727d3354df17896092de8bb26ae4c12d89c77e5df8cbce4` |
| `keycloak-db` | `fe03a7605299` | `LOCAL-ONLY` |
| `greenbone-community-edition-gvmd-1` | `registry.community.greenbone.net/community/gvmd:stable` | `sha256:0c6357d0c4b6da462edf0c1d1429949e920a00693dc2aa18bbc85a9e17e34138` |
| `greenbone-community-edition-nginx-1` | `registry.community.greenbone.net/community/nginx:latest` | `sha256:a1606ec2332eb69bb952ac6b6a22a06c450d05ca1bb70d6d4f880b519d1657a5` |
| `greenbone-community-edition-gsad-1` | `registry.community.greenbone.net/community/gsad:stable` | `sha256:8b9e89c4b722ab1891647e048fd5190b05f61a2dfd89cd26f9fc4a6df49547ad` |
| `greenbone-community-edition-ospd-openvas-1` | `registry.community.greenbone.net/community/ospd-openvas:stable` | `sha256:b8507be8e4cf34a84cc02c216817f86b347a984ecdbe0b68c759b094efd626c9` |
| `greenbone-community-edition-pg-gvm-1` | `registry.community.greenbone.net/community/pg-gvm:stable` | `sha256:9f33165496bfa2676cb37137bb9dfbc72438ecb92e4a7c07bcd18a0eedbac1a6` |
| `greenbone-community-edition-openvasd-1` | `registry.community.greenbone.net/community/openvas-scanner:stable` | `sha256:07bdafd3ae6cf20695b7e2afe0d073a0ab82860ecef4d3e300e677ebf70edcce` |
| `greenbone-community-edition-redis-server-1` | `registry.community.greenbone.net/community/redis-server` | `sha256:6da6a7c7221aa3ab231961fd6ca001d552b6a2c7727ac6201c6d8a360baa963a` |
| `caddy` | `caddy:2.11.2` | `sha256:25cdc846626b62d05f6b633b9b40c2c9f6ef89b515dc76133cefd920f7dbe562` |
| `frontend` | `ghcr.io/intuitem/ciso-assistant-community/frontend:latest` | `sha256:d0291e0b8e23aa943e8f38b073539640f8df308ad8deeee1784a0f2cc3c6184f` |
| `fleet-redis` | `redis:7` | `sha256:b2b95679e3b46fb51864949ed25ea976fc3a6bcc00a40a1bc00d568cb2822e50` |

## Known manifest gaps

- Digests above record what is running as of 2026-08-24. Compose files do NOT
  yet pin to them, so a restart of any floating-tag service may silently change
  its version. Pinning the compose files is the remaining half of G-06.
- `keycloak-db` is `postgres:16` (identified 2026-08-24; the container had lost
  its tag reference, it was never an unknown image).
- OWASP ZAP and Headscale are not deployed as containers and are not listed in
  the digest table above; their versions still need recording.
- UNPINNED marks a floating image tag. Release gate 1 requires pinning by
  digest, and an unpinned image means the deployed version cannot be stated
  with certainty. See gap G-06.
- `keycloak-db` runs an untagged image and must be identified.
- OWASP ZAP and Headscale versions are not yet recorded.

## DFIR-IRIS module and dependency position (G-15)

Checked live on 2026-08-24 inside the running `iriswebapp_app` container.

**No third-party IRIS modules are installed.** The only `iris_*` directories
present are `app/iris_engine` and `app/datamgmt/iris_engine`, both part of the
core application rather than pluggable modules. The Guideline's warning at
section 5 — that the repository licence does not automatically cover every
module — therefore has nothing outstanding to apply to today. It becomes a
standing check: any module added later needs its own licence review before it
is deployed or redistributed.

**139 Python dependencies** are installed in the IRIS application image, listed
at pinned versions in `dfir-iris-2.4.29-python-deps.txt`. These are NOT covered
by the iris-web LGPLv3 licence and are the substantive part of G-15. The list
is the concrete input for that review.
