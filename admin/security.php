<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (!rb_is_authenticated()) { header('Location: ../login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admin access required.'); }

$db = db();
$base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

function sec_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function sec_setup(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS security_scopes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        target VARCHAR(255) NOT NULL,
        target_type ENUM('ip','hostname') NOT NULL DEFAULT 'ip',
        authorized TINYINT(1) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_security_scope_target(target)
    ) ENGINE=InnoDB");
    $db->query("CREATE TABLE IF NOT EXISTS security_scans (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        scope_id BIGINT UNSIGNED NOT NULL,
        scan_type VARCHAR(60) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'running',
        started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        summary TEXT NULL,
        INDEX idx_security_scan_scope(scope_id)
    ) ENGINE=InnoDB");
    $db->query("CREATE TABLE IF NOT EXISTS security_findings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        scan_id BIGINT UNSIGNED NOT NULL,
        severity ENUM('Critical','High','Medium','Low','Info') NOT NULL DEFAULT 'Info',
        title VARCHAR(255) NOT NULL,
        asset VARCHAR(255) NOT NULL,
        evidence TEXT NULL,
        remediation TEXT NULL,
        status ENUM('Open','Resolved','Accepted') NOT NULL DEFAULT 'Open',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_security_finding_scan(scan_id),
        INDEX idx_security_finding_status(status),
        INDEX idx_security_finding_severity(severity)
    ) ENGINE=InnoDB");
    $db->query("CREATE TABLE IF NOT EXISTS security_scan_ports (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        scan_id BIGINT UNSIGNED NOT NULL,
        port INT UNSIGNED NOT NULL,
        service VARCHAR(80) NOT NULL,
        state VARCHAR(20) NOT NULL,
        evidence VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_security_port_scan(scan_id)
    ) ENGINE=InnoDB");
}
sec_setup($db);

function sec_json(array $data, int $code=200): never {
    http_response_code($code); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES); exit;
}
function sec_body(): array {
    $x=json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($x)?$x:[];
}
function sec_ports(): array {
    return [21=>'FTP',22=>'SSH',23=>'Telnet',25=>'SMTP',53=>'DNS',80=>'HTTP',110=>'POP3',139=>'NetBIOS',143=>'IMAP',443=>'HTTPS',445=>'SMB',3306=>'MySQL',3389=>'RDP',5432=>'PostgreSQL',5900=>'VNC',8080=>'HTTP-Alt',8443=>'HTTPS-Alt'];
}
function sec_target(string $target): ?string {
    $target=trim($target);
    if (filter_var($target,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) return $target;
    if (preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/',$target)) {
        $ip=gethostbyname($target);
        return $ip !== $target ? $ip : null;
    }
    if ($target==='localhost') return '127.0.0.1';
    return null;
}
function sec_add_finding(mysqli $db,int $scan,string $severity,string $title,string $asset,string $evidence,string $remediation):void{
    $q=$db->prepare("INSERT INTO security_findings(scan_id,severity,title,asset,evidence,remediation) VALUES(?,?,?,?,?,?)");
    $q->bind_param('isssss',$scan,$severity,$title,$asset,$evidence,$remediation);$q->execute();
}
function sec_http_audit(string $host,int $port,bool $tls): array {
    if (!function_exists('curl_init')) return [['Low','HTTP audit unavailable','PHP cURL extension is not enabled.','Enable PHP cURL and rerun the audit.']];
    $scheme=$tls?'https':'http'; $url=$scheme.'://'.$host.':'.$port.'/';
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_NOBODY=>true,CURLOPT_TIMEOUT=>5,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0,CURLOPT_USERAGENT=>'RemoteBridge Defensive Auditor/1.0']);
    $raw=curl_exec($ch); $err=curl_error($ch); curl_close($ch);
    if($raw===false) return [['Info','HTTP audit could not complete',$err?:'Connection failed','Confirm the service is HTTP/HTTPS and reachable.']];
    $headers=[];
    foreach(preg_split("/\r\n/",$raw) as $line){$pos=strpos($line,':');if($pos!==false)$headers[strtolower(trim(substr($line,0,$pos)))]=trim(substr($line,$pos+1));}
    $out=[];
    if(empty($headers['strict-transport-security']) && $tls) $out[]=['Medium','Missing HSTS header',$url,'Enable Strict-Transport-Security for HTTPS sites when appropriate.'];
    if(empty($headers['content-security-policy'])) $out[]=['Low','Missing Content-Security-Policy header',$url,'Add a restrictive Content-Security-Policy appropriate to the application.'];
    if(empty($headers['x-content-type-options']) || strtolower($headers['x-content-type-options'])!=='nosniff') $out[]=['Low','Missing X-Content-Type-Options: nosniff',$url,'Set X-Content-Type-Options: nosniff.'];
    if(empty($headers['referrer-policy'])) $out[]=['Low','Missing Referrer-Policy header',$url,'Set a deliberate Referrer-Policy such as strict-origin-when-cross-origin.'];
    if(empty($headers['permissions-policy'])) $out[]=['Info','Missing Permissions-Policy header',$url,'Consider restricting browser features not required by the application.'];
    if(!empty($headers['server'])) $out[]=['Info','Server header disclosed',$headers['server'],'Minimize unnecessary server/version disclosure where practical.'];
    if(!$out)$out[]=['Info','Basic HTTP security headers look good','Checked response headers on '.$url,'Continue periodic review and test application-specific controls.'];
    return $out;
}
function sec_tls_expiry(string $host,int $port): ?array {
    $ctx=stream_context_create(['ssl'=>['capture_peer_cert'=>true,'verify_peer'=>false,'verify_peer_name'=>false,'SNI_enabled'=>true,'peer_name'=>$host]]);
    $fp=@stream_socket_client('ssl://'.$host.':'.$port,$errno,$errstr,4,STREAM_CLIENT_CONNECT,$ctx);
    if(!$fp)return null;
    $params=stream_context_get_params($fp);fclose($fp);
    $cert=$params['options']['ssl']['peer_certificate']??null;
    if(!$cert)return null;
    $info=@openssl_x509_parse($cert); if(!$info || empty($info['validTo_time_t']))return null;
    $days=(int)floor(($info['validTo_time_t']-time())/86400);
    return [$days,$info['subject']['CN']??$host];
}

/* JSON API is kept in this single admin page so it can be dropped into the
 * existing project without modifying the central router. */
if (isset($_GET['api'])) {
    $api=(string)$_GET['api'];
    if($api==='inventory'){
        $rows=$db->query("SELECT d.remote_id,d.device_name,d.ip_address,d.is_online,d.last_seen_at,u.username,u.display_name
                          FROM devices d LEFT JOIN users u ON u.id=d.user_id
                          WHERE d.ip_address IS NOT NULL AND d.ip_address<>'' ORDER BY d.is_online DESC,d.last_seen_at DESC")->fetch_all(MYSQLI_ASSOC);
        sec_json(['devices'=>$rows]);
    }
    if($api==='scopes'){
        $rows=$db->query("SELECT * FROM security_scopes ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
        sec_json(['scopes'=>$rows]);
    }
    if($api==='findings'){
        $q=$db->query("SELECT f.*,s.started_at,s.scope_id FROM security_findings f JOIN security_scans s ON s.id=f.scan_id ORDER BY f.id DESC LIMIT 300");
        sec_json(['findings'=>$q->fetch_all(MYSQLI_ASSOC)]);
    }
    if($api==='stats'){
        $r=$db->query("SELECT COUNT(*) total, SUM(status='Open') open_count, SUM(severity='Critical' AND status='Open') critical, SUM(status='High' AND status='Open') high FROM security_findings")->fetch_assoc();
        $s=$db->query("SELECT COUNT(*) scans FROM security_scans")->fetch_assoc();
        sec_json(['findings'=>$r,'scans'=>(int)$s['scans']]);
    }
    if($api==='add_scope' && $_SERVER['REQUEST_METHOD']==='POST'){
        $b=sec_body();$name=trim((string)($b['name']??''));$target=trim((string)($b['target']??''));$notes=trim((string)($b['notes']??''));$authorized=!empty($b['authorized'])?1:0;
        if($name===''||$target==='')sec_json(['error'=>'Name and target are required'],400);
        if(!sec_target($target))sec_json(['error'=>'Use a valid IPv4 address or DNS hostname'],400);
        $type=filter_var($target,FILTER_VALIDATE_IP)?'ip':'hostname';
        $q=$db->prepare("INSERT INTO security_scopes(name,target,target_type,authorized,notes,created_by) VALUES(?,?,?,?,?,?)");
        $uid=(int)$_SESSION['user_id'];$q->bind_param('sssisi',$name,$target,$type,$authorized,$notes,$uid);$q->execute();
        rb_audit_log($db,'security_scope_created',null,['name'=>$name,'target'=>$target,'authorized'=>$authorized]);
        sec_json(['ok'=>true]);
    }
    if($api==='scan' && $_SERVER['REQUEST_METHOD']==='POST'){
        $b=sec_body();$scopeId=(int)($b['scope_id']??0);
        $q=$db->prepare("SELECT * FROM security_scopes WHERE id=? AND authorized=1 LIMIT 1");$q->bind_param('i',$scopeId);$q->execute();$scope=$q->get_result()->fetch_assoc();
        if(!$scope)sec_json(['error'=>'Only an explicitly authorized scope can be scanned'],403);
        $ip=sec_target($scope['target']);if(!$ip)sec_json(['error'=>'Target could not be resolved'],400);
        $q=$db->prepare("INSERT INTO security_scans(scope_id,scan_type,status,summary) VALUES(?,?,'running','')");$type='defensive-network-and-web';$q->bind_param('is',$scopeId,$type);$q->execute();$scanId=(int)$q->insert_id;
        $ports=sec_ports();$open=0;$checks=0;
        foreach($ports as $port=>$service){
            $checks++;$errno=0;$err='';$fp=@fsockopen($ip,$port,$errno,$err,0.8);
            if($fp){fclose($fp);$open++;$q=$db->prepare("INSERT INTO security_scan_ports(scan_id,port,service,state,evidence) VALUES(?,?,?,?,?)");$state='open';$ev='TCP connection accepted';$q->bind_param('iisss',$scanId,$port,$service,$state,$ev);$q->execute();
                $sev=in_array($port,[23,139,445,3389,5900],true)?'Medium':'Info';
                $title="Open {$service} port ({$port})";$rem=in_array($port,[23,139,445,3389,5900],true)?'Verify the service is required, restrict it to trusted networks, and keep it patched.':'Confirm the service is required and restrict exposure to trusted networks.';
                sec_add_finding($db,$scanId,$sev,$title,$scope['target'].':'.$port,'TCP connection accepted on port '.$port,$rem);
                if(in_array($port,[80,8080,443,8443],true)){
                    $aud=sec_http_audit($scope['target'],$port,in_array($port,[443,8443],true));
                    foreach($aud as [$sv,$ti,$evd,$remd])sec_add_finding($db,$scanId,$sv,$ti,$scope['target'].':'.$port,$evd,$remd);
                }
                if(in_array($port,[443,8443],true)){
                    $tls=sec_tls_expiry($scope['target'],$port);
                    if($tls){[$days,$cn]=$tls;if($days<0)sec_add_finding($db,$scanId,'High','TLS certificate is expired',$scope['target'].':'.$port,'Certificate CN '.$cn.' expired '.abs($days).' day(s) ago.','Renew and deploy a valid certificate.');elseif($days<30)sec_add_finding($db,$scanId,'Medium','TLS certificate expires soon',$scope['target'].':'.$port,'Certificate CN '.$cn.' expires in '.$days.' day(s).','Renew the certificate before expiry and verify automated renewal.');}
                }
            }
        }
        $summary="Checked {$checks} TCP services on {$scope['target']} ({$ip}); {$open} port(s) accepted connections.";
        $q=$db->prepare("UPDATE security_scans SET status='completed',finished_at=NOW(),summary=? WHERE id=?");$q->bind_param('si',$summary,$scanId);$q->execute();
        rb_audit_log($db,'security_scan_completed',null,['scan_id'=>$scanId,'scope'=>$scope['target'],'summary'=>$summary]);
        sec_json(['ok'=>true,'scan_id'=>$scanId,'summary'=>$summary]);
    }
    if($api==='resolve' && $_SERVER['REQUEST_METHOD']==='POST'){
        $b=sec_body();$id=(int)($b['id']??0);if($id<=0)sec_json(['error'=>'Invalid finding'],400);
        $q=$db->prepare("UPDATE security_findings SET status='Resolved' WHERE id=?");$q->bind_param('i',$id);$q->execute();
        rb_audit_log($db,'security_finding_resolved',null,['finding_id'=>$id]);sec_json(['ok'=>true]);
    }
    sec_json(['error'=>'Unknown security API'],404);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>RemoteBridge | Security Center</title>
<link rel="icon" href="../logo/favicon.ico">
<style>
:root{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:#eaf1ff;background:#07101f}*{box-sizing:border-box}
body{margin:0;background:radial-gradient(circle at 20% 5%,#15315a 0,#07101f 45%,#050a13 100%);min-height:100vh}.nav{height:64px;background:rgba(8,20,37,.94);border-bottom:1px solid #1c304b;display:flex;align-items:center;justify-content:space-between;padding:0 24px;position:sticky;top:0;z-index:10}.brand{font-weight:800}.nav a{color:#bcd3f2;text-decoration:none;font-size:13px}.wrap{max-width:1250px;margin:26px auto 60px;padding:0 18px}.head{display:flex;justify-content:space-between;align-items:end;gap:15px;margin-bottom:18px}.head h1{margin:0;font-size:25px}.muted{color:#91a6c0;font-size:13px}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.card{background:rgba(14,27,47,.9);border:1px solid #253b5a;border-radius:14px;padding:18px}.metric{font-size:30px;font-weight:800}.metric-label{font-size:11px;color:#8097b2;text-transform:uppercase;letter-spacing:.5px}.panel{margin-top:16px;background:rgba(14,27,47,.9);border:1px solid #253b5a;border-radius:14px;padding:20px}.tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:15px}.tab{cursor:pointer;background:#0e1b2f;border:1px solid #253b5a;color:#cfe0f7;border-radius:9px;padding:9px 13px;font-weight:700;font-size:12px}.tab.active{background:#2b6de8;border-color:#2b6de8;color:#fff}.section{display:none}.section.active{display:block}table{width:100%;border-collapse:collapse;font-size:12.5px}th{text-align:left;color:#7f95b4;text-transform:uppercase;font-size:10px;letter-spacing:.4px;padding:9px;border-bottom:1px solid #1e3049}td{padding:10px 9px;border-bottom:1px solid #16273f;vertical-align:top}.pill{display:inline-block;padding:3px 8px;border-radius:999px;font-size:10px;font-weight:800}.Critical{background:#5a1823;color:#ffabb5}.High{background:#5b3315;color:#ffd19a}.Medium{background:#514b13;color:#f5e993}.Low{background:#17442f;color:#9ee5c0}.Info{background:#173c59;color:#a9d9ff}.Open{color:#ffbd91}.Resolved{color:#8fe3a3}.formgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.field label{display:block;color:#90a4bd;font-size:11px;font-weight:700;margin-bottom:5px}.field.full{grid-column:1/-1}input,textarea,select{width:100%;background:#07101c;border:1px solid #253b5a;color:#eaf1ff;border-radius:8px;padding:10px}textarea{min-height:75px}.actions{display:flex;gap:7px;flex-wrap:wrap}.btn{border:0;background:#2b6de8;color:#fff;padding:9px 13px;border-radius:8px;font-weight:700;cursor:pointer}.btn.secondary{background:#20324d}.btn.danger{background:#7c3030}.notice{background:#0b1c31;border:1px solid #31557d;padding:12px;border-radius:10px;color:#c6d6e9;font-size:12.5px;margin-bottom:12px}.online{color:#8fe3a3}.offline{color:#f29a9a}.empty{color:#7187a2;padding:18px;text-align:center}@media(max-width:800px){.grid{grid-template-columns:1fr 1fr}.formgrid{grid-template-columns:1fr}.head{align-items:start;flex-direction:column}}@media(max-width:500px){.grid{grid-template-columns:1fr}.nav{padding:0 13px}.nav .backtext{display:none}}
</style>
</head>
<body>
<header class="nav"><div class="brand">RemoteBridge · Security Center</div><div style="display:flex;gap:12px;align-items:center"><a href="security_lab.php">Kali / VMware Lab</a><a href="<?=sec_h($base.'/index.php')?>">← Back to RemoteBridge</a></div></header>
<main class="wrap">
<div class="head"><div><h1>Defensive Security Center</h1><div class="muted">Integrated with your existing users, devices and audit log.</div></div><div class="muted">Admin only · safe checks</div></div>
<div class="grid">
<div class="card"><div class="metric" id="mScopes">—</div><div class="metric-label">Authorized scopes</div></div>
<div class="card"><div class="metric" id="mOpen">—</div><div class="metric-label">Open findings</div></div>
<div class="card"><div class="metric" id="mCritical">—</div><div class="metric-label">Critical findings</div></div>
<div class="card"><div class="metric" id="mScans">—</div><div class="metric-label">Security scans</div></div>
</div>
<div class="panel">
<div class="tabs"><button class="tab active" data-s="overview">Overview</button><button class="tab" data-s="assets">RemoteBridge assets</button><button class="tab" data-s="scope">Authorized scope</button><button class="tab" data-s="findings">Findings</button><button class="tab" data-s="run">Run audit</button></div>
<section class="section active" id="s-overview"><div class="notice"><b>Safe-by-default:</b> the integrated scanner only performs bounded TCP connection checks, HTTP security-header checks and TLS certificate expiry checks. It does not exploit services, brute-force credentials, execute payloads, or perform denial-of-service actions.</div><h3>What is connected</h3><p class="muted">The asset view reads your existing <code>devices</code> table, so Remote IDs, device names, IPs and online status are reused instead of creating a second device inventory.</p></section>
<section class="section" id="s-assets"><div class="actions" style="margin-bottom:12px"><button class="btn secondary" onclick="loadAssets()">Refresh assets</button></div><div style="overflow:auto"><table><thead><tr><th>Remote ID</th><th>Device</th><th>User</th><th>IP</th><th>Status</th><th>Last seen</th><th>Action</th></tr></thead><tbody id="assetsBody"><tr><td colspan="7" class="empty">Loading…</td></tr></tbody></table></div></section>
<section class="section" id="s-scope"><div class="notice">Only scopes marked authorized can be scanned. Add assets you own/manage or have explicit permission to assess.</div><form id="scopeForm"><div class="formgrid"><div class="field"><label>Name</label><input id="scopeName" required placeholder="Production RemoteBridge host"></div><div class="field"><label>IP / hostname</label><input id="scopeTarget" required placeholder="192.168.1.20 or server.example.com"></div><div class="field full"><label>Notes</label><textarea id="scopeNotes" placeholder="Owner, purpose, authorization reference"></textarea></div><label class="field full"><input id="scopeAuth" type="checkbox" style="width:auto"> I confirm this target is authorized.</label></div><button class="btn" style="margin-top:10px">Save authorized scope</button></form><div style="overflow:auto;margin-top:18px"><table><thead><tr><th>Name</th><th>Target</th><th>Authorized</th><th>Notes</th><th>Created</th></tr></thead><tbody id="scopeBody"></tbody></table></div></section>
<section class="section" id="s-findings"><div class="actions" style="margin-bottom:12px"><button class="btn secondary" onclick="loadFindings()">Refresh findings</button></div><div style="overflow:auto"><table><thead><tr><th>Severity</th><th>Finding</th><th>Asset</th><th>Evidence</th><th>Remediation</th><th>Status</th><th></th></tr></thead><tbody id="findingsBody"></tbody></table></div></section>
<section class="section" id="s-run"><div class="notice">Select an authorized scope. A scan normally completes in seconds for one host because the port set is intentionally bounded.</div><div class="formgrid"><div class="field full"><label>Authorized scope</label><select id="scanScope"></select></div></div><button class="btn" style="margin-top:10px" onclick="runScan()">Start defensive audit</button><div id="scanMsg" class="muted" style="margin-top:12px"></div></section>
</div></main>
<script>
const API='<?=sec_h($_SERVER['PHP_SELF'])?>';
const esc=s=>{const d=document.createElement('div');d.textContent=s??'';return d.innerHTML};
async function api(name,opts={}){const u=API+'?api='+encodeURIComponent(name);const r=await fetch(u,Object.assign({headers:{'Content-Type':'application/json'}},opts));const x=await r.json().catch(()=>({}));if(!r.ok)throw Error(x.error||'Request failed');return x}
function tabs(){document.querySelectorAll('.tab').forEach(b=>b.onclick=()=>{document.querySelectorAll('.tab').forEach(x=>x.classList.toggle('active',x===b));document.querySelectorAll('.section').forEach(s=>s.classList.toggle('active',s.id==='s-'+b.dataset.s));if(b.dataset.s==='assets')loadAssets();if(b.dataset.s==='scope')loadScopes();if(b.dataset.s==='findings')loadFindings();if(b.dataset.s==='run')loadScopes();});}
async function stats(){const x=await api('stats');document.getElementById('mOpen').textContent=x.findings.open_count||0;document.getElementById('mCritical').textContent=x.findings.critical||0;document.getElementById('mScans').textContent=x.scans;const s=await api('scopes');document.getElementById('mScopes').textContent=s.scopes.filter(x=>Number(x.authorized)===1).length}
async function loadAssets(){const x=await api('inventory');document.getElementById('assetsBody').innerHTML=x.devices.length?x.devices.map(d=>`<tr><td>${esc(d.remote_id)}</td><td><b>${esc(d.device_name||'Unknown')}</b></td><td>${esc(d.display_name||d.username||'—')}</td><td>${esc(d.ip_address||'—')}</td><td class="${d.is_online?'online':'offline'}">${d.is_online?'Online':'Offline'}</td><td>${esc(d.last_seen_at||'—')}</td><td><button class="btn secondary" onclick="prefill('${esc(d.device_name||d.remote_id)}','${esc(d.ip_address||'')}')">Use as scope</button></td></tr>`).join(''):'<tr><td colspan="7" class="empty">No devices with an IP address.</td></tr>'}
function prefill(n,t){document.querySelector('[data-s="scope"]').click();document.getElementById('scopeName').value=n;document.getElementById('scopeTarget').value=t;document.getElementById('scopeAuth').focus()}
async function loadScopes(){const x=await api('scopes');document.getElementById('scopeBody').innerHTML=x.scopes.length?x.scopes.map(s=>`<tr><td>${esc(s.name)}</td><td>${esc(s.target)}</td><td>${s.authorized?'<span class="online">YES</span>':'<span class="offline">NO</span>'}</td><td>${esc(s.notes||'')}</td><td>${esc(s.created_at)}</td></tr>`).join(''):'<tr><td colspan="5" class="empty">No scopes yet.</td></tr>';const sel=document.getElementById('scanScope');sel.innerHTML=x.scopes.filter(s=>Number(s.authorized)===1).map(s=>`<option value="${s.id}">${esc(s.name)} — ${esc(s.target)}</option>`).join('')||'<option value="">No authorized scopes</option>'}
document.getElementById('scopeForm').onsubmit=async e=>{e.preventDefault();try{await api('add_scope',{method:'POST',body:JSON.stringify({name:scopeName.value,target:scopeTarget.value,notes:scopeNotes.value,authorized:scopeAuth.checked})});e.target.reset();await loadScopes();await stats();alert('Authorized scope saved.')}catch(x){alert(x.message)}}
async function runScan(){const id=Number(scanScope.value);if(!id){alert('Create an authorized scope first.');return}scanMsg.textContent='Running safe audit…';try{const x=await api('scan',{method:'POST',body:JSON.stringify({scope_id:id})});scanMsg.textContent=x.summary;await stats();await loadFindings()}catch(x){scanMsg.textContent=x.message}}
async function loadFindings(){const x=await api('findings');document.getElementById('findingsBody').innerHTML=x.findings.length?x.findings.map(f=>`<tr><td><span class="pill ${esc(f.severity)}">${esc(f.severity)}</span></td><td><b>${esc(f.title)}</b></td><td>${esc(f.asset)}</td><td>${esc(f.evidence)}</td><td>${esc(f.remediation)}</td><td class="${esc(f.status)}">${esc(f.status)}</td><td>${f.status==='Open'?`<button class="btn secondary" onclick="resolveFinding(${f.id})">Resolve</button>`:''}</td></tr>`).join(''):'<tr><td colspan="7" class="empty">No findings yet.</td></tr>'}
async function resolveFinding(id){try{await api('resolve',{method:'POST',body:JSON.stringify({id})});loadFindings();stats()}catch(x){alert(x.message)}}
tabs();stats();loadAssets();loadScopes();loadFindings();
</script></body></html>
