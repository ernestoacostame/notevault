<?php
/**
 * NoteVault - API Backend v2
 * PHP puro + archivos JSON · Nginx + PHP-FPM
 */
session_start();
header('X-Content-Type-Options: nosniff');

define('DATA_DIR', __DIR__ . '/data');
define('USERS_FILE', DATA_DIR . '/users.json');
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('BCRYPT_COST', 12);
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);
define('ALLOWED_TYPES', ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml']);
define('REGISTRATION_MODE', 'open'); // open | closed | invite
define('INVITE_CODE', 'cambia-este-codigo-secreto');
define('MAX_USERS', 0);

foreach ([DATA_DIR, UPLOAD_DIR] as $d) { if (!is_dir($d)) mkdir($d, 0700, true); }

function jsonRes($data, $code=200) { header('Content-Type: application/json; charset=utf-8'); http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function getInput() { return json_decode(file_get_contents('php://input'), true) ?? []; }
function loadJson($f, $def=[]) { if (!file_exists($f)) return $def; return json_decode(file_get_contents($f), true) ?? $def; }
function saveJson($f, $d) { $dir=dirname($f); if (!is_dir($dir)) mkdir($dir,0700,true); file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX); }
function auth() { if (empty($_SESSION['user'])) jsonRes(['error'=>'No autorizado'],401); return $_SESSION['user']; }
function udir($u) { $s=preg_replace('/[^a-zA-Z0-9_-]/','_',$u); $d=DATA_DIR.'/users/'.$s; if(!is_dir($d))mkdir($d,0700,true); return $d; }
function gid() { return bin2hex(random_bytes(8)); }

set_error_handler(function($n,$s){ jsonRes(['error'=>'Error interno'],500); });

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ═══ FAVICON ═══
if ($action === 'favicon') {
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=604800');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" rx="18" fill="#1a1a1c"/><text x="50" y="68" text-anchor="middle" font-size="50" fill="#ffba0a" font-family="system-ui" font-weight="800">N</text></svg>';
    exit;
}

// ═══ STATUS ═══
if ($action === 'status') jsonRes(['ok'=>true,'php'=>PHP_VERSION,'registration'=>REGISTRATION_MODE]);

// ═══ AUTH ═══
if ($action === 'register' && $method === 'POST') {
    if (REGISTRATION_MODE === 'closed') jsonRes(['error'=>'Registro desactivado'],403);
    $i=getInput(); $u=trim($i['username']??''); $p=$i['password']??'';
    if (REGISTRATION_MODE==='invite' && ($i['invite_code']??'')!==INVITE_CODE) jsonRes(['error'=>'Código inválido'],403);
    if (strlen($u)<3||strlen($u)>30) jsonRes(['error'=>'Usuario: 3-30 caracteres'],400);
    if (strlen($p)<6) jsonRes(['error'=>'Contraseña: mín. 6 caracteres'],400);
    if (!preg_match('/^[a-zA-Z0-9_-]+$/',$u)) jsonRes(['error'=>'Usuario: solo letras, números, guiones'],400);
    $users=loadJson(USERS_FILE,[]);
    if (MAX_USERS>0&&count($users)>=MAX_USERS) jsonRes(['error'=>'Límite de usuarios'],403);
    if (isset($users[$u])) jsonRes(['error'=>'Usuario ya existe'],409);
    $users[$u]=['hash'=>password_hash($p,PASSWORD_BCRYPT,['cost'=>BCRYPT_COST]),'created'=>date('c')];
    saveJson(USERS_FILE,$users);
    $d=udir($u);
    saveJson($d.'/notes.json',[]);
    saveJson($d.'/folders.json',[
        ['id'=>'inbox','name'=>'Inbox','icon'=>'inbox'],
        ['id'=>'personal','name'=>'Personal','icon'=>'home'],
        ['id'=>'work','name'=>'Trabajo','icon'=>'work'],
    ]);
    $_SESSION['user']=$u; session_regenerate_id(true);
    jsonRes(['ok'=>true,'user'=>$u]);
}
if ($action==='login'&&$method==='POST') {
    $i=getInput(); $u=trim($i['username']??''); $p=$i['password']??'';
    $users=loadJson(USERS_FILE,[]);
    if (!isset($users[$u])||!password_verify($p,$users[$u]['hash'])) { usleep(random_int(100000,300000)); jsonRes(['error'=>'Credenciales inválidas'],401); }
    $_SESSION['user']=$u; session_regenerate_id(true);
    jsonRes(['ok'=>true,'user'=>$u]);
}
if ($action==='logout') { session_destroy(); jsonRes(['ok'=>true]); }
if ($action==='session') { jsonRes(['user'=>$_SESSION['user']??null]); }

// ═══ NOTES ═══
if ($action==='notes'&&$method==='GET') {
    $u=auth(); $notes=loadJson(udir($u).'/notes.json',[]);
    $unlocked=$_SESSION['unlocked_notes']??[];
    foreach ($notes as &$n) {
        if (!empty($n['locked'])) {
            $n['isLocked']=true;
            if (!in_array($n['id'],$unlocked)) { $n['content']=''; $n['title']='Nota protegida'; }
            else { $n['isUnlocked']=true; }
        }
    }
    unset($n);
    usort($notes, function($a,$b){
        if(($a['pinned']??false)!==($b['pinned']??false)) return ($b['pinned']??false)?1:-1;
        return ($b['updatedAt']??0)-($a['updatedAt']??0);
    });
    jsonRes($notes);
}
if ($action==='notes'&&$method==='POST') {
    $u=auth(); $i=getInput(); $d=udir($u); $notes=loadJson($d.'/notes.json',[]);
    $note=['id'=>gid(),'title'=>$i['title']??'Sin título','content'=>$i['content']??'','folder'=>$i['folder']??'inbox','pinned'=>false,'locked'=>false,'createdAt'=>time(),'updatedAt'=>time()];
    array_unshift($notes,$note); saveJson($d.'/notes.json',$notes);
    $td=$d.'/txt'; if(!is_dir($td))mkdir($td,0700,true);
    file_put_contents($td.'/'.$note['id'].'.txt',$note['content'],LOCK_EX);
    jsonRes($note,201);
}
if ($action==='notes'&&$method==='PUT') {
    $u=auth(); $i=getInput(); $id=$i['id']??''; $d=udir($u); $notes=loadJson($d.'/notes.json',[]);
    $found=false;
    foreach($notes as &$note) {
        if($note['id']===$id) {
            if(!empty($note['locked'])) {
                $unl=$_SESSION['unlocked_notes']??[];
                if(!in_array($id,$unl)) jsonRes(['error'=>'Nota protegida'],403);
            }
            if(isset($i['title']))$note['title']=$i['title'];
            if(isset($i['content']))$note['content']=$i['content'];
            if(isset($i['folder']))$note['folder']=$i['folder'];
            if(isset($i['pinned']))$note['pinned']=(bool)$i['pinned'];
            $note['updatedAt']=time(); $found=true;
            $td=$d.'/txt'; if(!is_dir($td))mkdir($td,0700,true);
            file_put_contents($td.'/'.$note['id'].'.txt',$note['content'],LOCK_EX);
            break;
        }
    }
    unset($note);
    if(!$found) jsonRes(['error'=>'No encontrada'],404);
    saveJson($d.'/notes.json',$notes);
    jsonRes(['ok'=>true]);
}
if ($action==='notes'&&$method==='DELETE') {
    $u=auth(); $id=$_GET['id']??''; $d=udir($u);
    $notes=loadJson($d.'/notes.json',[]);
    $trash=loadJson($d.'/trash.json',[]);
    $deleted=null;
    $remaining=[];
    foreach($notes as $n) {
        if($n['id']===$id) { $deleted=$n; } else { $remaining[]=$n; }
    }
    if($deleted) {
        $deleted['deletedAt']=time();
        array_unshift($trash,$deleted);
        saveJson($d.'/trash.json',$trash);
        saveJson($d.'/notes.json',$remaining);
    }
    jsonRes(['ok'=>true]);
}

// ═══ NOTE LOCK ═══
if ($action==='lock_note'&&$method==='POST') {
    $u=auth(); $i=getInput(); $id=$i['id']??''; $pw=$i['password']??'';
    if(strlen($pw)<4) jsonRes(['error'=>'Mínimo 4 caracteres'],400);
    $d=udir($u); $notes=loadJson($d.'/notes.json',[]);
    foreach($notes as &$n) {
        if($n['id']===$id) {
            $n['locked']=password_hash($pw,PASSWORD_BCRYPT,['cost'=>10]);
            saveJson($d.'/notes.json',$notes);
            jsonRes(['ok'=>true]);
        }
    }
    jsonRes(['error'=>'No encontrada'],404);
}
if ($action==='unlock_note'&&$method==='POST') {
    $u=auth(); $i=getInput(); $id=$i['id']??''; $pw=$i['password']??'';
    $d=udir($u); $notes=loadJson($d.'/notes.json',[]);
    foreach($notes as $n) {
        if($n['id']===$id) {
            if(empty($n['locked'])) jsonRes(['error'=>'No está protegida'],400);
            if(!password_verify($pw,$n['locked'])) { usleep(random_int(100000,300000)); jsonRes(['error'=>'Contraseña incorrecta'],403); }
            if(!isset($_SESSION['unlocked_notes'])) $_SESSION['unlocked_notes']=[];
            if(!in_array($id,$_SESSION['unlocked_notes'])) $_SESSION['unlocked_notes'][]=$id;
            jsonRes(['ok'=>true,'content'=>$n['content'],'title'=>$n['title']]);
        }
    }
    jsonRes(['error'=>'No encontrada'],404);
}
if ($action==='remove_lock'&&$method==='POST') {
    $u=auth(); $i=getInput(); $id=$i['id']??'';
    $d=udir($u); $notes=loadJson($d.'/notes.json',[]);
    foreach($notes as &$n) {
        if($n['id']===$id) { $n['locked']=false; saveJson($d.'/notes.json',$notes); jsonRes(['ok'=>true]); }
    }
    jsonRes(['error'=>'No encontrada'],404);
}

// ═══ TRASH ═══
if ($action==='trash'&&$method==='GET') {
    $u=auth(); $trash=loadJson(udir($u).'/trash.json',[]);
    usort($trash, fn($a,$b)=>($b['deletedAt']??0)-($a['deletedAt']??0));
    jsonRes($trash);
}
if ($action==='restore'&&$method==='POST') {
    $u=auth(); $i=getInput(); $id=$i['id']??''; $d=udir($u);
    $trash=loadJson($d.'/trash.json',[]);
    $notes=loadJson($d.'/notes.json',[]);
    $restored=null;
    $remaining=[];
    foreach($trash as $n) {
        if($n['id']===$id) { $restored=$n; } else { $remaining[]=$n; }
    }
    if(!$restored) jsonRes(['error'=>'No encontrada en papelera'],404);
    unset($restored['deletedAt']);
    $restored['updatedAt']=time();
    array_unshift($notes,$restored);
    saveJson($d.'/notes.json',$notes);
    saveJson($d.'/trash.json',$remaining);
    jsonRes(['ok'=>true,'note'=>$restored]);
}
if ($action==='trash_delete'&&$method==='DELETE') {
    $u=auth(); $id=$_GET['id']??''; $d=udir($u);
    $trash=loadJson($d.'/trash.json',[]);
    $trash=array_values(array_filter($trash,fn($n)=>$n['id']!==$id));
    saveJson($d.'/trash.json',$trash);
    $t=$d.'/txt/'.preg_replace('/[^a-f0-9]/','', $id).'.txt';
    if(file_exists($t))unlink($t);
    jsonRes(['ok'=>true]);
}
if ($action==='empty_trash'&&$method==='DELETE') {
    $u=auth(); $d=udir($u);
    $trash=loadJson($d.'/trash.json',[]);
    $td=$d.'/txt';
    foreach($trash as $n) {
        $t=$td.'/'.preg_replace('/[^a-f0-9]/','', $n['id']).'.txt';
        if(file_exists($t))unlink($t);
    }
    saveJson($d.'/trash.json',[]);
    jsonRes(['ok'=>true]);
}

// ═══ FOLDERS ═══
if ($action==='folders'&&$method==='GET') { jsonRes(loadJson(udir(auth()).'/folders.json',[])); }
if ($action==='folders'&&$method==='POST') {
    $u=auth(); $i=getInput(); $d=udir($u); $f=loadJson($d.'/folders.json',[]);
    $nf=['id'=>gid(),'name'=>trim($i['name']??'Carpeta'),'icon'=>$i['icon']??'folder'];
    $f[]=$nf; saveJson($d.'/folders.json',$f);
    jsonRes($nf,201);
}
if ($action==='folders'&&$method==='DELETE') {
    $u=auth(); $id=$_GET['id']??'';
    if(in_array($id,['inbox','personal','work'])) jsonRes(['error'=>'No se puede eliminar'],400);
    $d=udir($u); $f=loadJson($d.'/folders.json',[]);
    $f=array_values(array_filter($f,fn($x)=>$x['id']!==$id)); saveJson($d.'/folders.json',$f);
    $notes=loadJson($d.'/notes.json',[]);
    foreach($notes as &$n){if($n['folder']===$id)$n['folder']='inbox';}unset($n);
    saveJson($d.'/notes.json',$notes);
    jsonRes(['ok'=>true]);
}

// ═══ IMPORT/EXPORT ═══
if ($action==='import'&&$method==='POST') {
    $u=auth(); $i=getInput(); $d=udir($u);
    $notes=loadJson($d.'/notes.json',[]);
    $folders=loadJson($d.'/folders.json',[]);
    $importedNotes=$i['notes']??[];
    $importedFolders=$i['folders']??[];
    $folderIds = array_column($folders, 'id');
    foreach($importedFolders as $f) {
        if(isset($f['id']) && !in_array($f['id'], $folderIds)) {
            $folders[]=$f; $folderIds[]=$f['id'];
        }
    }
    saveJson($d.'/folders.json',$folders);
    $noteIds = array_column($notes, 'id');
    $td=$d.'/txt'; if(!is_dir($td))mkdir($td,0700,true);
    foreach($importedNotes as $n) {
        if(isset($n['id'], $n['title'], $n['content']) && !in_array($n['id'], $noteIds)) {
            $notes[]=$n; $noteIds[]=$n['id'];
            file_put_contents($td.'/'.$n['id'].'.txt',$n['content'],LOCK_EX);
        }
    }
    saveJson($d.'/notes.json',$notes);
    jsonRes(['ok'=>true]);
}

// ═══ IMAGE UPLOAD ═══
if ($action==='upload'&&$method==='POST') {
    $u=auth();
    if(empty($_FILES['image'])) jsonRes(['error'=>'No se recibió archivo'],400);
    $file=$_FILES['image'];
    if($file['error']!==UPLOAD_ERR_OK) jsonRes(['error'=>'Error al subir'],400);
    if($file['size']>MAX_UPLOAD_SIZE) jsonRes(['error'=>'Máximo 10MB'],400);
    $mime=mime_content_type($file['tmp_name']);
    if(!in_array($mime,ALLOWED_TYPES)) jsonRes(['error'=>'Tipo no permitido'],400);
    $ext=match($mime){'image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/svg+xml'=>'svg',default=>'bin'};
    $name=gid().'.'.$ext;
    $ud=UPLOAD_DIR.'/'.preg_replace('/[^a-zA-Z0-9_-]/','_',$u);
    if(!is_dir($ud))mkdir($ud,0755,true);
    if(!move_uploaded_file($file['tmp_name'],$ud.'/'.$name)) jsonRes(['error'=>'Error al guardar'],500);
    jsonRes(['ok'=>true,'url'=>'uploads/'.preg_replace('/[^a-zA-Z0-9_-]/','_',$u).'/'.$name]);
}

jsonRes(['error'=>'Acción no válida'],400);
