<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (!rb_is_authenticated()) { header('Location: ../login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admin access required.'); }

$config = require __DIR__ . '/../config.php';
$db = db();
$lab = $config['security_lab'] ?? [];

function lab_json(array $d, int $c=200): never {
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_SLASHES);
    exit;
}
function lab_local_only(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1','::1'], true)) lab_json(['error'=>'Security Lab control is local-machine only.'],403);
}
function lab_scope(mysqli $db, int $id): array {
    $q=$db->prepare("SELECT * FROM security_scopes WHERE id=? AND authorized=1 LIMIT 1");
    $q->bind_param('i',$id); $q->execute();
    $r=$q->get_result()->fetch_assoc();
    if (!$r) lab_json(['error'=>'Only an explicitly authorized scope may be used.'],403);
    $target=trim((string)$r['target']);
    $ip=filter_var($target,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)?$target:gethostbyname($target);
    if (!$ip || $ip===$target && !filter_var($target,FILTER_VALIDATE_IP)) lab_json(['error'=>'Scope target could not be resolved.'],400);
    return ['scope'=>$r,'target'=>$target,'ip'=>$ip];
}
function lab_cmd(string $program, array $args, int $timeout=45): array {
    $cmd = escapeshellarg($program);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
    $desc=[1=>['pipe','w'],2=>['pipe','w']];
    $p=proc_open($cmd,$desc,$pipes);
    if (!is_resource($p)) return ['code'=>1,'stdout'=>'','stderr'=>'Could not start process.'];
    stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
    $out=''; $err=''; $start=microtime(true);
    while (true) {
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $st=proc_get_status($p);
        if (!$st['running']) { $code=(int)$st['exitcode']; break; }
        if ((microtime(true)-$start) > $timeout) {
            proc_terminate($p);
            $code=124; $err.="Timed out after {$timeout}s.";
            break;
        }
        usleep(100000);
    }
    $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return ['code'=>$code,'stdout'=>$out,'stderr'=>$err];
}
function lab_detect_vmrun(string $configured): string {
    if ($configured && is_file($configured)) return $configured;
    $candidates=[];
    if (PHP_OS_FAMILY==='Windows') {
        $candidates=[
          'C:\\Program Files (x86)\\VMware\\VMware Workstation\\vmrun.exe',
          'C:\\Program Files\\VMware\\VMware Workstation\\vmrun.exe'
        ];
    } else {
        $candidates=['/usr/bin/vmrun','/usr/local/bin/vmrun'];
    }
    foreach ($candidates as $p) if (is_file($p)) return $p;
    return $configured;
}
function lab_vmrun(string $vmrun, string $action, string $vmx=''): array {

    if ($vmrun==='') return ['code'=>2,'stdout'=>'','stderr'=>'VMware vmrun is not configured.'];
    if (!is_file($vmrun)) return ['code'=>2,'stdout'=>'','stderr'=>'Configured vmrun path does not exist.'];
    $args=['-T','ws'];
    if ($action==='list') $args[]='list';
    else { $args[]=$action; $args[]=$vmx; }
    return lab_cmd($vmrun,$args,30);
}
function lab_guest_command(array $lab, string $command): array {
    /* Prefer SSH when a key is configured. This keeps guest credentials out
       of process arguments. Otherwise fall back to VMware runProgramInGuest. */
    if (!empty($lab['ssh_key']) && !empty($lab['ssh_host']) && !empty($lab['ssh_user'])) {
        $ssh=PHP_OS_FAMILY==='Windows' ? 'ssh.exe' : 'ssh';
        $args=['-i',$lab['ssh_key'],'-p',(string)$lab['ssh_port'],'-o','BatchMode=yes','-o','StrictHostKeyChecking=accept-new',
               $lab['ssh_user'].'@'.$lab['ssh_host'],$command];
        return lab_cmd($ssh,$args,(int)$lab['timeout']);
    }
    if (!empty($lab['vmrun']) && !empty($lab['vmx']) && !empty($lab['guest_user']) && !empty($lab['guest_password'])) {
        $encoded=base64_encode($command);
        $args=['-T','ws','-gu',$lab['guest_user'],'-gp',$lab['guest_password'],'runProgramInGuest',$lab['vmx'],
               '/bin/bash','-lc',"echo ".escapeshellarg($encoded)." | base64 -d | bash"];
        return lab_cmd($lab['vmrun'],$args,(int)$lab['timeout']);
    }
    return ['code'=>2,'stdout'=>'','stderr'=>'Configure RB_KALI_SSH_KEY + RB_KALI_SSH_HOST/USER or VMware vmrun + guest credentials.'];
}
function lab_require_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') lab_json(['error'=>'POST required.'],405);
}
function lab_guest_health(array $lab): array {
    $checks = [
      'os' => 'uname -a',
      'hostname' => 'hostname',
      'ip' => 'ip -brief addr',
      'route' => 'ip route show default',
      'nmap' => 'nmap --version | head -n 1',
      'ssh' => 'systemctl is-active ssh 2>/dev/null || true'
    ];
    $out=[]; $ok=true;
    foreach($checks as $name=>$command){
        $r=lab_guest_command($lab,$command);
        $value=trim($r['stdout'] ?: $r['stderr']);
        $out[$name]=['ok'=>$r['code']===0,'value'=>$value,'code'=>$r['code']];
        if(in_array($name,['os','hostname','nmap'],true) && $r['code']!==0) $ok=false;
    }
    return ['ok'=>$ok,'checks'=>$out];
}
function lab_parse_nmap(string $output): array {
    $ports=[]; $summary='';
    foreach(preg_split('/\R/', $output) as $line){
        $line=trim($line);
        if(preg_match('/^(\d+)\/(tcp|udp)\s+open\s+([^\s]+)(?:\s+(.*))?$/i',$line,$m)){
            $ports[]=['port'=>(int)$m[1],'protocol'=>strtolower($m[2]),'service'=>$m[3],'details'=>trim($m[4]??'')];
        }
        if(str_starts_with($line,'Nmap done:')) $summary=$line;
    }
    return ['open_ports'=>$ports,'summary'=>$summary,'open_count'=>count($ports)];
}
function lab_redact_command(string $command): string {
    return preg_replace('/(-gp\s+)([^\s]+)/i','$1********',$command) ?? $command;
}
function lab_ensure_run_columns(mysqli $db): void {
    $cols=[]; $r=$db->query('SHOW COLUMNS FROM security_lab_runs');
    while($x=$r->fetch_assoc()) $cols[$x['Field']]=true;
    if(!isset($cols['duration_ms'])) $db->query('ALTER TABLE security_lab_runs ADD COLUMN duration_ms INT NULL AFTER finished_at');
    if(!isset($cols['summary_json'])) $db->query('ALTER TABLE security_lab_runs ADD COLUMN summary_json JSON NULL AFTER output');
}
function lab_allowed_profile(string $p): array {
    $profiles=[
      'quick'=>['-Pn','-sV','--top-ports','100'],
      'service'=>['-Pn','-sV','-sC','--top-ports','100'],
      'safe-vuln'=>['-Pn','-sV','--script','safe','--top-ports','100'],
      'vuln-lab'=>['-Pn','-sV','--script','vuln','--top-ports','100'],
    ];
    return $profiles[$p] ?? $profiles['quick'];
}
function lab_ensure_tables(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS security_lab_runs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      scope_id BIGINT UNSIGNED NULL, tool VARCHAR(40) NOT NULL,
      profile VARCHAR(60) NOT NULL, command_text TEXT NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'running', exit_code INT NULL,
      output MEDIUMTEXT NULL, created_by BIGINT UNSIGNED NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      finished_at DATETIME NULL, INDEX(scope_id), INDEX(created_at)
    ) ENGINE=InnoDB");
}
lab_ensure_tables($db);
lab_ensure_run_columns($db);

if (isset($_GET['api'])) {
    lab_local_only();
    $api=(string)$_GET['api'];

    if (!$lab['enabled']) lab_json(['error'=>'Security Lab is disabled. Set RB_SECURITY_LAB_ENABLED=1.'],403);

    if ($api==='config') {
        $vmrun=lab_detect_vmrun((string)$lab['vmrun']);
        lab_json([
          'enabled'=>true,
          'vmware_configured'=>$vmrun!=='' && is_file($vmrun) && !empty($lab['vmx']),
          'vmrun'=>$vmrun ?: null,
          'vmx'=>$lab['vmx'] ?: null,
          'ssh_configured'=>!empty($lab['ssh_key']) && !empty($lab['ssh_host']) && !empty($lab['ssh_user']),
          'ssh_host'=>$lab['ssh_host'] ?: null,
          'ssh_port'=>(int)$lab['ssh_port'],
          'target_timeout'=>(int)$lab['timeout'],
          'profiles'=>['quick','service','safe-vuln','vuln-lab']
        ]);
    }
    if ($api==='health') {
        $h=lab_guest_health($lab);
        rb_audit_log($db,'security_lab_health',null,['ok'=>$h['ok']]);
        lab_json($h);
    }
    if ($api==='resolve' && $_SERVER['REQUEST_METHOD']==='POST') {
        $b=json_decode(file_get_contents('php://input') ?: '',true) ?: [];
        $target=trim((string)($b['target']??''));
        if($target==='') lab_json(['error'=>'Target is required.'],400);
        $ip=filter_var($target,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)?$target:gethostbyname($target);
        if(!$ip || ($ip===$target && !filter_var($target,FILTER_VALIDATE_IP))) lab_json(['ok'=>false,'target'=>$target,'error'=>'DNS resolution failed.'],400);
        lab_json(['ok'=>true,'target'=>$target,'ip'=>$ip,'hostname'=>gethostbyaddr($ip) ?: $target]);
    }
    if ($api==='vm_list') {
        $r=lab_vmrun(lab_detect_vmrun((string)$lab['vmrun']),'list');
        lab_json(['ok'=>$r['code']===0,'output'=>trim($r['stdout']?:$r['stderr'])]);
    }
    if ($api==='vm_start') { lab_require_post();
        $r=lab_vmrun(lab_detect_vmrun((string)$lab['vmrun']),'start',(string)$lab['vmx']);
        rb_audit_log($db,'security_lab_vm_start',null,['code'=>$r['code']]);
        lab_json(['ok'=>$r['code']===0,'output'=>trim($r['stdout']?:$r['stderr'])],$r['code']===0?200:500);
    }
    if ($api==='vm_stop') { lab_require_post();
        $r=lab_vmrun(lab_detect_vmrun((string)$lab['vmrun']),'stop',(string)$lab['vmx']);
        rb_audit_log($db,'security_lab_vm_stop',null,['code'=>$r['code']]);
        lab_json(['ok'=>$r['code']===0,'output'=>trim($r['stdout']?:$r['stderr'])],$r['code']===0?200:500);
    }
    if ($api==='console') { lab_require_post();
        $b=json_decode(file_get_contents('php://input') ?: '',true) ?: [];
        $kind=(string)($b['kind']??'');
        $allowed=['uname'=>'uname -a','ip'=>'ip addr','routes'=>'ip route','sockets'=>'ss -lntup',
          'whoami'=>'whoami','nmap-local'=>'nmap -Pn -sV --top-ports 100 127.0.0.1'];
        if (!isset($allowed[$kind])) lab_json(['error'=>'Unsupported console action.'],400);
        $r=lab_guest_command($lab,$allowed[$kind]);
        rb_audit_log($db,'security_lab_console',null,['action'=>$kind,'exit_code'=>$r['code']]);
        lab_json(['ok'=>$r['code']===0,'command'=>$allowed[$kind],'stdout'=>$r['stdout'],'stderr'=>$r['stderr'],'code'=>$r['code']]);
    }
    if ($api==='scan') {
        lab_require_post();
        $b=json_decode(file_get_contents('php://input') ?: '',true) ?: [];
        $scopeId=(int)($b['scope_id']??0); $profile=(string)($b['profile']??'quick');
        $x=lab_scope($db,$scopeId);
        if ($profile==='vuln-lab' && empty($b['lab_confirm'])) lab_json(['error'=>'Vulnerability profile requires explicit lab confirmation.'],400);
        $args=lab_allowed_profile($profile); $args[]=$x['ip'];
        $command='nmap '.implode(' ',array_map('escapeshellarg',$args));
        $q=$db->prepare("INSERT INTO security_lab_runs(scope_id,tool,profile,command_text,created_by) VALUES(?,?,?,?,?)");
        $tool='nmap'; $uid=(int)$_SESSION['user_id']; $q->bind_param('isssi',$scopeId,$tool,$profile,$command,$uid); $q->execute(); $runId=(int)$q->insert_id;
        $started=microtime(true);
        $r=lab_guest_command($lab,$command);
        $duration=(int)round((microtime(true)-$started)*1000);
        $status=$r['code']===0?'completed':($r['code']===124?'timeout':'failed');
        $summary=trim($r['stdout']."\n".$r['stderr']);
        $parsed=lab_parse_nmap($r['stdout']);
        $json=json_encode($parsed,JSON_UNESCAPED_SLASHES);
        $u=$db->prepare("UPDATE security_lab_runs SET status=?,exit_code=?,output=?,finished_at=NOW(),duration_ms=?,summary_json=? WHERE id=?");
        $u->bind_param('sisisi',$status,$r['code'],$summary,$duration,$json,$runId);$u->execute();
        rb_audit_log($db,'security_lab_nmap',null,['run_id'=>$runId,'scope'=>$x['target'],'profile'=>$profile,'status'=>$status,'duration_ms'=>$duration]);
        lab_json(['ok'=>$r['code']===0,'run_id'=>$runId,'command'=>lab_redact_command($command),'status'=>$status,'duration_ms'=>$duration,'output'=>$summary,'parsed'=>$parsed,'target'=>$x['target']]);
    }
    if ($api==='runs') {
        $rows=$db->query("SELECT r.id,r.scope_id,r.tool,r.profile,r.command_text,r.status,r.exit_code,r.output,r.summary_json,r.created_at,r.finished_at,r.duration_ms,s.name scope_name,s.target FROM security_lab_runs r LEFT JOIN security_scopes s ON s.id=r.scope_id ORDER BY r.id DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
        lab_json(['runs'=>$rows]);
    }
    lab_json(['error'=>'Unknown lab API'],404);
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>RemoteBridge | Kali Security Lab</title><link rel="icon" href="../logo/favicon.ico">
<style>
:root{font-family:Inter,system-ui,sans-serif;color:#eaf1ff;background:#060b13}*{box-sizing:border-box}
body{margin:0;background:radial-gradient(circle at 10% 0,#183557,#060b13 48%);min-height:100vh}.nav{height:64px;background:#081423ee;border-bottom:1px solid #203750;display:flex;align-items:center;justify-content:space-between;padding:0 22px;position:sticky;top:0;z-index:5}.nav a{color:#bcd4f3;text-decoration:none}.wrap{max-width:1450px;margin:25px auto;padding:0 18px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.card{background:#0d1a2aee;border:1px solid #243d59;border-radius:14px;padding:18px;box-shadow:0 10px 30px #0003}.wide{grid-column:1/-1}h1{margin:0 0 6px;font-size:25px}h2{margin:0 0 6px;font-size:17px}.muted{color:#8fa7c1;font-size:12.5px}.warn{border:1px solid #72591e;background:#2a210c;color:#f1d990;padding:12px;border-radius:10px;margin:12px 0}.ok{border:1px solid #24583c;background:#0c2a1d;color:#9ce4b8;padding:12px;border-radius:10px;margin:12px 0}.dangerbox{border:1px solid #6e2e38;background:#2a1017;color:#f1aeb8;padding:12px;border-radius:10px;font-size:12px}.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.btn{border:0;border-radius:8px;padding:9px 13px;background:#2d73e8;color:white;font-weight:700;cursor:pointer}.btn.secondary{background:#20364f}.btn.danger{background:#7c3030}.btn:disabled{opacity:.45;cursor:not-allowed}.field{display:flex;flex-direction:column;gap:5px;margin:9px 0}.field label{font-size:11px;color:#8fa7c1;font-weight:700}select,input{background:#07111e;color:#eaf1ff;border:1px solid #29415e;border-radius:8px;padding:10px;width:100%}input[type=checkbox]{width:auto}pre{margin:10px 0;background:#02070c;border:1px solid #1d3045;border-radius:10px;padding:13px;min-height:150px;max-height:430px;overflow:auto;white-space:pre-wrap;font:12px ui-monospace,monospace}.statusgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px}.stat{padding:10px;border:1px solid #203950;border-radius:9px;background:#08121e}.stat b{display:block;font-size:14px}.stat span{font-size:10px;color:#8fa7c1}.badge{padding:4px 8px;border-radius:999px;background:#183b59;font-size:10px}.online{color:#8be0ae}.offline{color:#ef9aa4}.tablewrap{overflow:auto;border:1px solid #203950;border-radius:10px}table{width:100%;border-collapse:collapse;font-size:12px;min-width:900px}th,td{padding:9px;border-bottom:1px solid #1d3045;text-align:left;vertical-align:top}th{color:#9bb7d6;background:#0a1522;position:sticky;top:0}.toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:end}.toolbar .field{min-width:190px;flex:1}.progress{height:5px;background:#09131f;border-radius:99px;overflow:hidden;margin-top:10px}.progress i{display:block;width:35%;height:100%;background:#2d73e8;animation:p 1.1s infinite ease-in-out}@keyframes p{0%{transform:translateX(-120%)}100%{transform:translateX(350%)}}@media(max-width:900px){.grid{grid-template-columns:1fr}.wide{grid-column:auto}.statusgrid{grid-template-columns:1fr 1fr}}
</style></head><body>
<header class="nav"><b>RemoteBridge · Kali Security Lab</b><a href="security.php">← Security Center</a></header>
<main class="wrap">
<h1>Authorized Kali / VMware Lab</h1><div class="muted">Admin-only lab controller for your explicitly authorized test assets.</div>
<div class="warn"><b>Safety boundary:</b> only assess systems you own or have explicit permission to test. Scans run inside the configured Kali guest and every run is logged.</div>
<section class="card" id="state">Checking lab configuration…<div class="progress"><i></i></div></section>
<div class="grid">
<section class="card"><h2>Lab readiness</h2><div class="muted">Verify the guest, SSH path and required Kali tooling before scanning.</div><div class="row" style="margin-top:12px"><button class="btn" onclick="health()">Run health check</button><button class="btn secondary" onclick="config()">Refresh config</button></div><div id="healthGrid" class="statusgrid"><div class="stat"><b>—</b><span>Guest OS</span></div><div class="stat"><b>—</b><span>Nmap</span></div><div class="stat"><b>—</b><span>SSH</span></div></div><pre id="healthOut">No health check executed.</pre></section>
<section class="card"><h2>VMware · Kali</h2><div class="muted">Control the existing VM without changing its virtual hardware.</div><div class="row" style="margin-top:12px"><button class="btn" onclick="vm('start')">Start VM</button><button class="btn secondary" onclick="vm('list')">VM status</button><button class="btn danger" onclick="vm('stop')">Stop VM</button></div><pre id="vmOut">No VMware command executed.</pre></section>
<section class="card"><h2>Kali operational checks</h2><div class="muted">Fixed, non-interactive diagnostics; no arbitrary shell is exposed here.</div><div class="row" style="margin-top:12px"><button class="btn secondary" onclick="consoleRun('uname')">uname</button><button class="btn secondary" onclick="consoleRun('ip')">IP</button><button class="btn secondary" onclick="consoleRun('routes')">Routes</button><button class="btn secondary" onclick="consoleRun('sockets')">Sockets</button><button class="btn secondary" onclick="consoleRun('whoami')">Whoami</button><button class="btn secondary" onclick="consoleRun('nmap-local')">Nmap localhost</button></div><pre id="consoleOut">Ready.</pre></section>
<section class="card"><h2>Target resolver</h2><div class="muted">Resolve an authorized hostname before selecting it for a scan.</div><div class="field"><label>IP / hostname</label><input id="resolveTarget" placeholder="192.168.56.10 or lab.local"></div><button class="btn secondary" onclick="resolveTarget()">Resolve</button><pre id="resolveOut">No target resolved.</pre></section>
<section class="card wide"><h2>Bounded Nmap assessment</h2><div class="muted">The selected target must already be marked authorized in Security Center. Profiles are intentionally bounded to the top 100 ports.</div>
<div class="toolbar"><div class="field"><label>Authorized scope</label><select id="scope"></select></div><div class="field"><label>Profile</label><select id="profile"><option value="quick">Quick · service detection</option><option value="service">Service · default scripts</option><option value="safe-vuln">Safe NSE · safe scripts</option><option value="vuln-lab">Vulnerability lab · vuln NSE</option></select></div></div>
<label class="muted"><input id="labConfirm" type="checkbox"> I confirm this is an authorized lab/test target.</label>
<div class="row" style="margin-top:10px"><button id="scanBtn" class="btn" onclick="scan()">Run Nmap</button><span id="scanMeta" class="badge">Idle</span></div><pre id="scanOut">No scan executed.</pre></section>
<section class="card wide"><div class="row" style="justify-content:space-between"><div><h2>Run history</h2><div class="muted">Latest 100 lab runs with parsed open-port summaries.</div></div><button class="btn secondary" onclick="runs()">Refresh</button></div><div class="tablewrap" style="margin-top:12px"><table><thead><tr><th>ID</th><th>Time</th><th>Scope</th><th>Profile</th><th>Status</th><th>Duration</th><th>Open ports</th><th>Command</th></tr></thead><tbody id="runsBody"></tbody></table></div></section>
</div></main>
<script>
const API='<?=htmlspecialchars($_SERVER['PHP_SELF'],ENT_QUOTES)?>';
const $=id=>document.getElementById(id);
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
async function api(n,o={}){const r=await fetch(API+'?api='+encodeURIComponent(n),Object.assign({headers:{'Content-Type':'application/json'},credentials:'same-origin'},o));const x=await r.json().catch(()=>({error:'Invalid response'}));if(!r.ok)throw Error(x.error||'Request failed');return x}
async function config(){try{const x=await api('config');$('state').innerHTML=`<div class="ok"><b>Security Lab enabled.</b> VMware: ${x.vmware_configured?'configured':'not ready'} · SSH: ${x.ssh_configured?'configured':'not ready'} · timeout: ${x.target_timeout}s</div><div class="muted">vmrun: ${esc(x.vmrun||'not detected')}<br>VMX: ${esc(x.vmx||'not configured')}<br>SSH: ${esc(x.ssh_host||'not configured')}:${x.ssh_port||''}</div>`;await scopes();}catch(e){$('state').innerHTML='<div class="dangerbox">'+esc(e.message)+'</div>'}}
async function scopes(){const x=await fetch('../admin/security.php?api=scopes',{credentials:'same-origin'});const d=await x.json();$('scope').innerHTML=(d.scopes||[]).filter(s=>Number(s.authorized)===1).map(s=>'<option value="'+s.id+'">'+esc(s.name)+' — '+esc(s.target)+'</option>').join('')||'<option value="">No authorized scopes</option>'}
async function health(){ $('healthOut').textContent='Checking Kali…'; try{const x=await api('health');const c=x.checks||{};$('healthGrid').innerHTML=[['os','Guest OS'],['nmap','Nmap'],['ssh','SSH']].map(([k,l])=>`<div class="stat"><b class="${c[k]?.ok?'online':'offline'}">${c[k]?.ok?'READY':'CHECK'}</b><span>${l}: ${esc(c[k]?.value||'unavailable')}</span></div>`).join('');$('healthOut').textContent=JSON.stringify(x,null,2)}catch(e){$('healthOut').textContent=e.message}}
async function vm(a){try{const x=await api(a==='list'?'vm_list':'vm_'+a,{method:a==='list'?'GET':'POST'});$('vmOut').textContent=x.output||'OK';}catch(e){$('vmOut').textContent=e.message}}
async function consoleRun(k){$('consoleOut').textContent='Running '+k+'…';try{const x=await api('console',{method:'POST',body:JSON.stringify({kind:k})});$('consoleOut').textContent=(x.command?('$ '+x.command+'\n\n'):'')+(x.stdout||'')+(x.stderr?'\n'+x.stderr:'')}catch(e){$('consoleOut').textContent=e.message}}
async function resolveTarget(){const t=$('resolveTarget').value.trim();if(!t){$('resolveOut').textContent='Enter a target.';return}try{const x=await api('resolve',{method:'POST',body:JSON.stringify({target:t})});$('resolveOut').textContent=JSON.stringify(x,null,2)}catch(e){$('resolveOut').textContent=e.message}}
async function scan(){const id=Number($('scope').value);if(!id){$('scanOut').textContent='No authorized scope.';return}if($('profile').value==='vuln-lab'&&!$('labConfirm').checked){$('scanOut').textContent='Confirm the authorized lab target first.';return}const b=$('scanBtn');b.disabled=true;$('scanMeta').textContent='Running';$('scanOut').textContent='Running inside Kali…';try{const x=await api('scan',{method:'POST',body:JSON.stringify({scope_id:id,profile:$('profile').value,lab_confirm:$('labConfirm').checked})});$('scanMeta').textContent=`${x.status} · ${x.duration_ms} ms · ${x.parsed?.open_count||0} open`;$('scanOut').textContent='$ '+x.command+'\n\n'+x.output+'\n\nParsed:\n'+JSON.stringify(x.parsed,null,2);await runs()}catch(e){$('scanMeta').textContent='Failed';$('scanOut').textContent=e.message}finally{b.disabled=false}}
async function runs(){try{const x=await api('runs');$('runsBody').innerHTML=(x.runs||[]).map(r=>{let p=[];try{p=JSON.parse(r.summary_json||'{}').open_ports||[]}catch(_){}return `<tr><td>#${r.id}</td><td>${esc(r.created_at)}</td><td>${esc(r.scope_name||r.target||'—')}</td><td>${esc(r.profile)}</td><td><span class="badge">${esc(r.status)}</span></td><td>${r.duration_ms??'—'} ms</td><td>${p.length?p.map(z=>`${z.port}/${z.protocol} ${esc(z.service)}`).join('<br>'):'—'}</td><td><code>${esc(r.command_text)}</code></td></tr>`}).join('')||'<tr><td colspan="8">No runs.</td></tr>'}catch(e){$('runsBody').innerHTML='<tr><td colspan="8">'+esc(e.message)+'</td></tr>'}}
async function init(){await config();await health();await runs()}
init();
</script></body></html>
