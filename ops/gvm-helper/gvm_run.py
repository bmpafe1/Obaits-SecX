#!/usr/bin/env python3
#
# GVM helper -- the ONLY thing on this platform that speaks GMP.
#
# App code never talks to gvmd directly. GvmClient shells out to this script,
# passing the action and a JSON payload as arguments and any secrets on stdin.
# Arguments are readable by any local user via ps for the life of the command;
# stdin is not. That is why the GVM password arrives on stdin and never as an
# argument.
#
# BROUGHT INTO THE REPO 2026-08-25. It previously lived only at
# /home/administrator/platform/gvm-helper/ on the VPS, version-controlled
# nowhere, while the platform actively depended on it. Six edits were made to it
# in a single day -- including the four scanner actions the multi-tenant pool
# needs -- and a host rebuild would have lost all of them.
#
# It now runs FROM THE REPO, so a git pull deploys helper changes like any other
# code. Both GVM_HELPER in .env and the sudoers grant point at the repo path.
# Do not reintroduce a copy under /home/administrator: that split is exactly how
# this drifted out of version control in the first place.
#
import sys, json, os
from gvm.connections import UnixSocketConnection
from gvm.protocols.gmp import Gmp
from gvm.transforms import EtreeTransform
 
SOCKET = os.environ.get('GVM_SOCKET',
    '/var/lib/docker/volumes/greenbone-community-edition_gvmd_socket_vol/_data/gvmd.sock')
USER = os.environ.get('GVM_USER', 'admin')

 
def main():
    action = sys.argv[1]
    payload = json.loads(sys.argv[2]) if len(sys.argv) > 2 else {}

    # Secrets arrive on stdin, never as arguments. Command arguments are
    # readable by any user on this host via `ps` for the life of the command.
    raw = sys.stdin.read()
    secrets = json.loads(raw) if raw.strip() else {}
    PW = secrets.get('gvm_password', '')   
    conn = UnixSocketConnection(path=SOCKET)
    with Gmp(conn, transform=EtreeTransform()) as gmp:
        gmp.authenticate(USER, PW)
 
        if action == 'version':
            r = gmp.get_version()
            print(json.dumps({'version': r.findtext('version')}))
 
        elif action == 'create_credential':
            # Client certificate credential for gvmd to authenticate to a
            # networked scanner. certificate/private_key arrive as PEM text.
            from gvm.protocols.gmp.requests.v224 import CredentialType
            r = gmp.create_credential(
                name=payload['name'],
                credential_type=CredentialType.CLIENT_CERTIFICATE,
                certificate=payload['certificate'],
                private_key=payload['private_key'],
                comment=payload.get('comment', 'Obaits scanner pool'),
            )
            print(json.dumps({'id': r.get('id'),
                              'status': r.get('status'),
                              'status_text': r.get('status_text')}))

        elif action == 'create_scanner':
            from gvm.protocols.gmp.requests.v224 import ScannerType
            r = gmp.create_scanner(
                name=payload['name'],
                host=payload['host'],
                port=int(payload['port']),
                scanner_type=ScannerType.OPENVAS_SCANNER_TYPE,
                credential_id=payload['credential_id'],
                ca_pub=payload.get('ca_pub'),
                comment=payload.get('comment', 'Obaits scanner pool member'),
            )
            print(json.dumps({'id': r.get('id'),
                              'status': r.get('status'),
                              'status_text': r.get('status_text')}))

        elif action == 'verify_scanner':
            r = gmp.verify_scanner(payload['scanner_id'])
            print(json.dumps({'status': r.get('status'),
                              'status_text': r.get('status_text'),
                              'version': r.findtext('version')}))

        elif action == 'delete_scanner':
            r = gmp.delete_scanner(payload['scanner_id'])
            print(json.dumps({'status': r.get('status'),
                              'status_text': r.get('status_text')}))

        elif action == 'get_scanners':
            r = gmp.get_scanners()
            out = []
            for sc in r.findall('scanner'):
                out.append({
                    'id': sc.get('id'),
                    'name': sc.findtext('name'),
                    'host': sc.findtext('host'),
                    'port': sc.findtext('port'),
                    'type': sc.findtext('type'),
                })
            print(json.dumps(out))

        elif action == 'create_target':
            r = gmp.create_target(name=payload['name'], hosts=[payload['host']],
                                  port_range='1-65535')
            # Surface GVM's own status when it refuses. Returning a bare null id
            # discarded the reason and produced a TypeError in the PHP client
            # that said nothing about what GVM actually objected to.
            tid = r.get('id')
            if not tid:
                print(json.dumps({'id': None,
                                  'status': r.get('status'),
                                  'status_text': r.get('status_text')}))
            else:
                print(json.dumps({'id': tid}))
 
        elif action == 'create_task':
            r = gmp.create_task(name=payload['name'], config_id=payload['config_id'],
                                target_id=payload['target_id'], scanner_id=payload['scanner_id'])
            print(json.dumps({'id': r.get('id')}))
 
        elif action == 'start_task':
            r = gmp.start_task(payload['task_id'])
            print(json.dumps({'ok': True}))
 
        elif action == 'task_status':
            r = gmp.get_task(payload['task_id'])
            status = r.find('.//status')
            progress = r.find('.//progress')
            print(json.dumps({
                'status': status.text if status is not None else 'Unknown',
                'progress': progress.text if progress is not None else '0'
            }))
 
        elif action == 'get_results':
            r = gmp.get_results(task_id=payload['task_id'], details=True)
            findings = []
            for res in r.findall('.//result'):
                nvt = res.find('nvt')
                findings.append({
                    'name': res.findtext('name'),
                    'host': res.findtext('host'),
                    'port': res.findtext('port'),
                    'severity': res.findtext('severity'),
                    'threat': res.findtext('threat'),
                    'cve': (nvt.findtext('cve') if nvt is not None else None),
                    'description': (res.findtext('description') or '')[:2000],
                })
            print(json.dumps({'findings': findings}))

        elif action == 'stop_task':
            gmp.stop_task(payload['task_id'])
            print(json.dumps({'ok': True}))

        elif action == 'delete_task':
            gmp.delete_task(payload['task_id'], ultimate=True)
            print(json.dumps({'ok': True}))
 
        elif action == 'delete_target':
            gmp.delete_target(payload['target_id'], ultimate=True)
            print(json.dumps({'ok': True}))

        else:
            print(json.dumps({'error': 'unknown action'}))
 
if __name__ == '__main__':
    main()
