<?php
/**
 * ZeronKit: WoWonder to SocialEngine Migration Tool
 * Version: 2.0
 * Website: https://zeronkit.com
 *
 * Copyright (C) ZeronKit, https://zeronkit.com
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see https://www.gnu.org/licenses/.
 *
 * This file is fully self-contained: no license key, no network calls.
 * Your database credentials never leave your own server.
 *
 *  HOW TO USE:
 *  1. Upload this file to your SocialEngine root directory
 *  2. Open it in your browser: yourdomain.com/wowonder-to-socialengine.php
 *  3. Enter your database credentials
 *  4. Click "Start Migration"
 *  5. DELETE this file after migration is complete
 *
 *  REQUIREMENTS:
 *  - PHP 7.4 or higher (PHP 8.0 recommended for SocialEngine)
 *  - PHP extension: mysqli
 *  - Access to both WoWonder and SocialEngine databases
 *  - SocialEngine v6.x installed (fresh installation recommended)
 *
 *  NOTE: Users keep their existing passwords. SocialEngine supports
 *        bcrypt hashes natively when password length > 32 characters.
 *
 *  SUPPORT: contact@zeronkit.com
 */

define('ZERONKIT_VERSION', '2.0');
define('ZERONKIT_SITE', 'https://zeronkit.com');
define('ZERONKIT_TARGET', 'socialengine');

// ============================================================
// SYSTEM CHECK
// ============================================================
$errors = [];
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    $errors[] = 'PHP 7.4+ required. You have PHP ' . PHP_VERSION;
}
foreach (['mysqli'] as $ext) {
    if (!extension_loaded($ext)) {
        $errors[] = 'PHP extension "' . $ext . '" is not installed. Contact your hosting provider.';
    }
}
if (!empty($errors)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>System Check Failed | ZeronKit</title>';
    echo '<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,sans-serif;background:#0a0e1a;color:#c9d1d9;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.card{max-width:550px;width:100%;background:#111827;border-radius:16px;padding:40px;border:1px solid #1f2937;text-align:center}h1{font-size:1.5rem;color:#ef4444;margin-bottom:8px}p.sub{color:#6b7280;margin-bottom:24px;font-size:.9rem}.err{background:#450a0a;border:1px solid #991b1b;border-radius:10px;padding:14px 18px;margin-bottom:12px;text-align:left;color:#fca5a5;font-size:.9rem;line-height:1.6}.help{margin-top:24px;color:#6b7280;font-size:.85rem}.help a{color:#3b82f6;text-decoration:none}</style></head><body><div class="card"><h1>System Check Failed</h1><p class="sub">Your server is missing requirements.</p>';
    foreach ($errors as $e) echo '<div class="err">' . htmlspecialchars($e) . '</div>';
    echo '<div class="help">Need help? Visit <a href="' . ZERONKIT_SITE . '">zeronkit.com</a></div></div></body></html>';
    exit;
}

// ============================================================
// MIGRATION ENGINE (formerly protected/migration_engine_se.php v1.0)
// Runs locally with the same inputs the old API used to inject:
// $_MIGRATION_STEPS, $_WO_CONFIG and $_SE_CONFIG.
// ============================================================
function zk_out($msg, $class = '') {
    if ($class) echo "<span class='$class'>$msg</span>\n";
    else echo "$msg\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function zk_esc($db, $v) { return $db->real_escape_string($v ?? ''); }
function zk_ts($ts) { $t = (int)$ts; return $t > 0 ? date('Y-m-d H:i:s', $t) : date('Y-m-d H:i:s'); }

function zk_fix_text($text, $hashtags, $usernames) {
    $text = preg_replace_callback('/#\[(\d+)\]/', function($m) use ($hashtags) {
        return isset($hashtags[$m[1]]) ? '#' . $hashtags[$m[1]] : $m[0];
    }, $text);
    $text = preg_replace_callback('/@\[(\d+)\]/', function($m) use ($usernames) {
        return isset($usernames[$m[1]]) ? '@' . $usernames[$m[1]] : $m[0];
    }, $text);
    $text = preg_replace_callback('/\[a\](.*?)\[\/a\]/', function($m) {
        return urldecode($m[1]);
    }, $text);
    $text = str_replace(['&lt;', '&gt;', '&quot;', '&amp;'], ['<', '>', '"', '&'], $text);
    $text = preg_replace('/<a[^>]*>(.*?)<\/a>/s', '$1', $text);
    $text = preg_replace('/<br\s*\/?>/', "\n", $text);
    $text = strip_tags($text);
    return trim(preg_replace('/\n{3,}/', "\n\n", $text));
}

function zk_run_migration($_MIGRATION_STEPS, $_WO_CONFIG, $_SE_CONFIG) {
    set_time_limit(0);
    ini_set('memory_limit', '2G');

    $wo = new mysqli($_WO_CONFIG['host'], $_WO_CONFIG['user'], $_WO_CONFIG['pass'], $_WO_CONFIG['db'], $_WO_CONFIG['port']);
    if ($wo->connect_error) { zk_out("WoWonder DB failed: " . $wo->connect_error, 'err'); return; }
    $wo->set_charset('utf8mb4');

    $se = new mysqli($_SE_CONFIG['host'], $_SE_CONFIG['user'], $_SE_CONFIG['pass'], $_SE_CONFIG['db'], $_SE_CONFIG['port']);
    if ($se->connect_error) { zk_out("SocialEngine DB failed: " . $se->connect_error, 'err'); return; }
    $se->set_charset('utf8mb4');

    zk_out("Connected to both databases", 'ok');
    zk_out("");

    $se->query("SET FOREIGN_KEY_CHECKS = 0");
    $se->query("SET sql_mode = ''");
    $stats = [];
    $step_num = 0;
    $total_steps = count($_MIGRATION_STEPS) + 1;

    // Preload
    zk_out("Loading lookup tables...", 'info');
    $hashtags = [];
    $r = $wo->query("SELECT id, tag FROM Wo_Hashtags");
    while ($row = $r->fetch_assoc()) $hashtags[$row['id']] = $row['tag'];
    $usernames = [];
    $r = $wo->query("SELECT user_id, username FROM Wo_Users");
    while ($row = $r->fetch_assoc()) $usernames[$row['user_id']] = $row['username'];
    zk_out("  Loaded " . count($hashtags) . " hashtags, " . count($usernames) . " usernames", 'ok');
    zk_out("");

    $post_map = [];

    // ====== USERS ======
    if (in_array('users', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Users...", 'info');
        $se->query("DELETE FROM engine4_users WHERE user_id > 1");
        $result = $wo->query("SELECT * FROM Wo_Users ORDER BY user_id ASC");
        $c = 0;
        while ($u = $result->fetch_assoc()) {
            if ($u['user_id'] == 1) continue;
            $username = zk_esc($se, $u['username']);
            $email = zk_esc($se, $u['email']);
            $password = zk_esc($se, $u['password']);
            $firstname = zk_esc($se, substr($u['first_name'] ?: $u['username'], 0, 16));
            $lastname = zk_esc($se, substr($u['last_name'], 0, 16));
            $displayname = zk_esc($se, trim(($u['first_name'] ?: $u['username']) . ' ' . $u['last_name']));
            $gender = ($u['gender'] == 'female') ? 'female' : 'male';
            $dob = 'NULL';
            $bd = $u['birthday'] ?? '';
            if ($bd && $bd != '0000-00-00' && $bd != '0' && preg_match('/^\d{4}-\d{2}-\d{2}/', $bd)) {
                $dob = "'" . zk_esc($se, $bd) . "'";
            }
            $level = ($u['admin'] == '1') ? 1 : 4;
            $enabled = ($u['active'] == '1') ? 1 : 0;
            $verified = ($u['verified'] == '1') ? 1 : 0;
            $status = zk_esc($se, $u['about'] ?? '');
            $time = zk_ts($u['joined'] ?? 0);

            $sql = "INSERT INTO engine4_users (user_id,email,username,displayname,firstname,lastname,dob,gender,password,salt,status,level_id,enabled,approved,verified,creation_date,modified_date,creation_ip) VALUES ({$u['user_id']},'$email','$username','$displayname','$firstname','$lastname',$dob,'$gender','$password','','$status',$level,$enabled,1,$verified,'$time','$time',INET6_ATON('127.0.0.1')) ON DUPLICATE KEY UPDATE username=VALUES(username)";
            if ($se->query($sql)) $c++;
        }
        $stats['Users'] = $c;
        zk_out("  $c users migrated", 'ok');
    }

    // ====== FOLLOWS ======
    if (in_array('followers', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Follows...", 'info');
        $se->query("TRUNCATE TABLE engine4_user_follows");
        $result = $wo->query("SELECT * FROM Wo_Followers WHERE active = 1");
        $c = 0;
        while ($f = $result->fetch_assoc()) {
            $a = (int)$f['follower_id']; $b = (int)$f['following_id'];
            $check = $se->query("SELECT COUNT(*) as c FROM engine4_users WHERE user_id IN ($a,$b)");
            if ($check->fetch_assoc()['c'] < 2) continue;
            if ($se->query("INSERT IGNORE INTO engine4_user_follows (user_id,resource_id,resource_approved,user_approved,creation_date) VALUES ($a,$b,1,1,'".zk_ts($f['time'])."')")) $c++;
        }
        $stats['Follows'] = $c;
        zk_out("  $c follows migrated", 'ok');
    }

    // ====== POSTS ======
    if (in_array('posts', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Posts...", 'info');
        $se->query("DELETE FROM engine4_activity_actions WHERE action_id > 0");
        $se->query("TRUNCATE TABLE engine4_activity_stream");
        $result = $wo->query("SELECT * FROM Wo_Posts WHERE user_id > 0 ORDER BY id ASC");
        $c = 0;
        while ($p = $result->fetch_assoc()) {
            $uid = (int)$p['user_id'];
            $check = $se->query("SELECT 1 FROM engine4_users WHERE user_id = $uid");
            if (!$check || $check->num_rows == 0) continue;
            $text = zk_esc($se, zk_fix_text($p['postText'] ?: '', $hashtags, $usernames));
            $time = zk_ts($p['time']);
            $privacy = 'everyone';
            if ($p['postPrivacy'] == '2') $privacy = 'friends';
            elseif ($p['postPrivacy'] == '3') $privacy = 'self';
            if ($se->query("INSERT INTO engine4_activity_actions (action_id,type,subject_type,subject_id,object_type,object_id,body,date,modified_date,privacy,attachment_count,comment_count,like_count,share_count) VALUES ({$p['id']},'status','user',$uid,'user',$uid,'$text','$time','$time','$privacy',0,0,0,0)")) {
                $post_map[$p['id']] = $p['id'];
                $c++;
                $se->query("INSERT IGNORE INTO engine4_activity_stream (target_type,target_id,subject_type,subject_id,object_type,object_id,type,action_id) VALUES ('user',$uid,'user',$uid,'user',$uid,'status',{$p['id']})");
            }
        }
        $stats['Posts'] = $c;
        zk_out("  $c posts migrated", 'ok');
    }

    // ====== LIKES ======
    if (in_array('reactions', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Likes...", 'info');
        $se->query("TRUNCATE TABLE engine4_activity_likes");
        $result = $wo->query("SELECT DISTINCT post_id, user_id FROM Wo_Reactions WHERE post_id > 0");
        $c = 0; $counts = [];
        while ($r = $result->fetch_assoc()) {
            $pid = (int)$r['post_id']; $uid = (int)$r['user_id'];
            if (!isset($post_map[$pid])) continue;
            $check = $se->query("SELECT 1 FROM engine4_users WHERE user_id = $uid");
            if (!$check || $check->num_rows == 0) continue;
            if ($se->query("INSERT IGNORE INTO engine4_activity_likes (resource_id,poster_type,poster_id) VALUES ($pid,'user',$uid)")) {
                $c++;
                $counts[$pid] = ($counts[$pid] ?? 0) + 1;
            }
        }
        foreach ($counts as $pid => $cc) $se->query("UPDATE engine4_activity_actions SET like_count = $cc WHERE action_id = $pid");
        $stats['Likes'] = $c;
        zk_out("  $c likes migrated", 'ok');
    }

    // ====== COMMENTS ======
    if (in_array('comments', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Comments...", 'info');
        $se->query("DELETE FROM engine4_activity_comments WHERE comment_id > 0");
        $result = $wo->query("SELECT * FROM Wo_Comments ORDER BY id ASC");
        $c = 0; $counts = [];
        while ($co = $result->fetch_assoc()) {
            $pid = (int)$co['post_id']; $uid = (int)$co['user_id'];
            if (!isset($post_map[$pid])) continue;
            $check = $se->query("SELECT 1 FROM engine4_users WHERE user_id = $uid");
            if (!$check || $check->num_rows == 0) continue;
            $body = zk_esc($se, zk_fix_text($co['text'] ?: '', $hashtags, $usernames));
            $time = zk_ts($co['time']);
            if ($se->query("INSERT INTO engine4_activity_comments (comment_id,resource_id,poster_type,poster_id,body,creation_date,like_count) VALUES ({$co['id']},$pid,'user',$uid,'$body','$time',0)")) {
                $c++;
                $counts[$pid] = ($counts[$pid] ?? 0) + 1;
            }
        }
        foreach ($counts as $pid => $cc) $se->query("UPDATE engine4_activity_actions SET comment_count = $cc WHERE action_id = $pid");
        $stats['Comments'] = $c;
        zk_out("  $c comments migrated", 'ok');
    }

    // ====== MESSAGES ======
    if (in_array('messages', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Messages...", 'info');
        $se->query("TRUNCATE TABLE engine4_messages_messages");
        $se->query("TRUNCATE TABLE engine4_messages_conversations");
        $se->query("TRUNCATE TABLE engine4_messages_recipients");
        $result = $wo->query("SELECT DISTINCT LEAST(from_id,to_id) as u1, GREATEST(from_id,to_id) as u2 FROM Wo_Messages WHERE to_id>0 AND from_id>0 AND group_id=0 AND page_id=0");
        $cmap = []; $cid = 0;
        while ($pair = $result->fetch_assoc()) {
            $u1 = (int)$pair['u1']; $u2 = (int)$pair['u2'];
            $check = $se->query("SELECT COUNT(*) as c FROM engine4_users WHERE user_id IN ($u1,$u2)");
            if ($check->fetch_assoc()['c'] < 2) continue;
            $cid++;
            $cmap["$u1-$u2"] = $cid;
            $se->query("INSERT INTO engine4_messages_conversations (conversation_id,title,user_id,recipients,modified,locked) VALUES ($cid,'',$u1,2,NOW(),0)");
            $se->query("INSERT IGNORE INTO engine4_messages_recipients (user_id,conversation_id,inbox_updated,inbox_read,inbox_deleted,outbox_deleted) VALUES ($u1,$cid,NOW(),1,0,0)");
            $se->query("INSERT IGNORE INTO engine4_messages_recipients (user_id,conversation_id,inbox_updated,inbox_read,inbox_deleted,outbox_deleted) VALUES ($u2,$cid,NOW(),1,0,0)");
        }
        $result = $wo->query("SELECT * FROM Wo_Messages WHERE to_id>0 AND from_id>0 AND group_id=0 AND page_id=0 ORDER BY id ASC");
        $mc = 0;
        while ($m = $result->fetch_assoc()) {
            $k = min($m['from_id'],$m['to_id']).'-'.max($m['from_id'],$m['to_id']);
            if (!isset($cmap[$k])) continue;
            $body = zk_esc($se, zk_fix_text($m['text'] ?: '', $hashtags, $usernames));
            if ($se->query("INSERT INTO engine4_messages_messages (conversation_id,user_id,title,body,date) VALUES ({$cmap[$k]},{$m['from_id']},'','$body','".zk_ts($m['time'])."')")) $mc++;
        }
        $stats['Conversations'] = $cid;
        $stats['Messages'] = $mc;
        zk_out("  $cid conversations, $mc messages", 'ok');
    }

    // ====== BLOCKS ======
    if (in_array('blocks', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Blocks...", 'info');
        $se->query("TRUNCATE TABLE engine4_user_block");
        $result = $wo->query("SELECT * FROM Wo_Blocks");
        $c = 0;
        while ($b = $result->fetch_assoc()) {
            $blocker = (int)$b['blocker']; $blocked = (int)$b['blocked'];
            if ($blocker <= 0 || $blocked <= 0) continue;
            $check = $se->query("SELECT COUNT(*) as c FROM engine4_users WHERE user_id IN ($blocker,$blocked)");
            if ($check->fetch_assoc()['c'] < 2) continue;
            if ($se->query("INSERT IGNORE INTO engine4_user_block (user_id,blocked_user_id) VALUES ($blocker,$blocked)")) $c++;
        }
        $stats['Blocks'] = $c;
        zk_out("  $c blocks migrated", 'ok');
    }

    // ====== PERMISSIONS ======
    $step_num++;
    zk_out("[$step_num/$total_steps] Setting profile permissions...", 'info');
    foreach (['view','comment'] as $action) {
        foreach (['everyone','member','registered','network'] as $role) {
            $se->query("INSERT IGNORE INTO engine4_authorization_allow (resource_type,resource_id,action,role,role_id,value) SELECT 'user',user_id,'$action','$role',0,1 FROM engine4_users WHERE user_id > 1");
        }
    }
    $se->query("SET FOREIGN_KEY_CHECKS = 1");
    zk_out("  Done", 'ok');

    // Summary
    echo "</div><div class='summary'><h2>Migration Complete!</h2><table>";
    foreach ($stats as $label => $val) echo "<tr><td>$label</td><td>$val</td></tr>";
    echo "</table></div>";
    echo "<div style='background:#161b2e;border-radius:12px;padding:20px;margin-top:16px;border:1px solid #21283d;color:#eab308;font-size:.9rem'>";
    echo "<strong>Important:</strong> WoWonder media files need to be copied manually:<br>";
    echo "<code style='background:#0a0e1a;padding:4px 10px;border-radius:6px;color:#58a6ff;display:inline-block;margin-top:8px'>cp -r /wowonder/upload/* /socialengine/public/</code>";
    echo "</div>";
    echo "<a href='javascript:history.back()' class='back'>← Run Again</a>";
    $wo->close();
    $se->close();
}

// ============================================================
// HANDLE MIGRATION EXECUTION
// ============================================================
if (isset($_POST['run_migration'])) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Migration Running | ZeronKit</title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Courier New',monospace;background:#0a0e1a;color:#c9d1d9;padding:30px 20px}.c{max-width:850px;margin:0 auto}.logo{text-align:center;margin-bottom:20px;font-size:1.4rem;font-weight:700;background:linear-gradient(135deg,#3b82f6,#8b5cf6);-webkit-background-clip:text;-webkit-text-fill-color:transparent}.log{background:#161b2e;border-radius:12px;padding:24px;border:1px solid #21283d;font-size:.88rem;line-height:2;white-space:pre-wrap;min-height:200px;max-height:70vh;overflow-y:auto}.ok{color:#22c55e}.err{color:#ef4444}.info{color:#3b82f6}.warn{color:#eab308}.summary{background:#161b2e;border-radius:12px;padding:24px;margin-top:16px;border:1px solid #21283d}.summary h2{color:#22c55e;margin-bottom:14px;font-size:1.1rem}.summary table{width:100%;border-collapse:collapse}.summary td{padding:7px 12px;border-bottom:1px solid #21283d;font-size:.9rem}.summary td:last-child{text-align:right;color:#3b82f6;font-weight:700}.back{display:inline-block;margin-top:16px;padding:10px 22px;background:#21283d;color:#c9d1d9;text-decoration:none;border-radius:8px;font-size:.9rem}.footer{text-align:center;margin-top:20px;font-size:.8rem;color:#4b5563}.footer a{color:#3b82f6;text-decoration:none}</style>
</head><body><div class="c"><div class="logo">ZERONKIT</div><div class="log">
<?php
ob_implicit_flush(true);
if (ob_get_level()) ob_end_flush();

$steps = [];
foreach (['users','followers','posts','reactions','comments','messages','blocks'] as $s) {
    if (isset($_POST['migrate_' . $s])) $steps[] = $s;
}

if (empty($steps)) {
    echo "<span class='err'>No data types selected.</span>\n";
} else {
    $_WO_CONFIG = [
        'host' => $_POST['wo_host'], 'port' => (int)($_POST['wo_port'] ?: 3306),
        'user' => $_POST['wo_user'], 'pass' => $_POST['wo_pass'] ?? '', 'db' => $_POST['wo_db'],
    ];
    $_SE_CONFIG = [
        'host' => $_POST['se_host'], 'port' => (int)($_POST['se_port'] ?: 3306),
        'user' => $_POST['se_user'], 'pass' => $_POST['se_pass'] ?? '', 'db' => $_POST['se_db'],
    ];
    zk_run_migration($steps, $_WO_CONFIG, $_SE_CONFIG);
}
?>
</div><div class="footer">Powered by <a href="<?= ZERONKIT_SITE ?>" target="_blank">ZeronKit</a> v<?= ZERONKIT_VERSION ?></div></div></body></html>
<?php exit;
}

// ============================================================
// HANDLE CONNECTION TEST
// ============================================================
if (isset($_POST['test_connection'])) {
    // PHP 8.1+ throws on a failed connect or query by default, which broke the JSON reply.
    mysqli_report(MYSQLI_REPORT_OFF);
    header('Content-Type: application/json');
    $results = [];
    $wo = @new mysqli($_POST['wo_host'], $_POST['wo_user'], $_POST['wo_pass'] ?? '', $_POST['wo_db'], (int)($_POST['wo_port'] ?: 3306));
    if ($wo->connect_error) {
        $results['wowonder'] = ['status' => 'error', 'message' => $wo->connect_error];
    } else {
        $r = $wo->query("SELECT COUNT(*) as c FROM Wo_Users");
        $results['wowonder'] = $r ? ['status' => 'ok', 'users' => $r->fetch_assoc()['c']] : ['status' => 'error', 'message' => 'Wo_Users table not found.'];
        $wo->close();
    }
    $se = @new mysqli($_POST['se_host'], $_POST['se_user'], $_POST['se_pass'] ?? '', $_POST['se_db'], (int)($_POST['se_port'] ?: 3306));
    if ($se->connect_error) {
        $results['socialengine'] = ['status' => 'error', 'message' => $se->connect_error];
    } else {
        $r = $se->query("SELECT COUNT(*) as c FROM engine4_users");
        $results['socialengine'] = $r ? ['status' => 'ok', 'users' => $r->fetch_assoc()['c']] : ['status' => 'error', 'message' => 'engine4_users table not found. Complete SocialEngine installation first.'];
        $se->close();
    }
    echo json_encode($results);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WoWonder to SocialEngine Migration | ZeronKit</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0a0e1a;color:#c9d1d9;min-height:100vh}
.container{max-width:880px;margin:0 auto;padding:36px 20px}
.logo{text-align:center;margin-bottom:8px}
.logo h1{font-size:1.8rem;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:-.5px}
.logo p{color:#6b7280;font-size:.9rem;margin-top:4px}
.card{background:#111827;border-radius:14px;padding:28px;margin-top:22px;border:1px solid #1f2937}
.card h2{font-size:1.05rem;margin-bottom:18px;display:flex;align-items:center;gap:10px;color:#e5e7eb}
.badge{display:inline-block;padding:3px 10px;border-radius:6px;font-size:.75rem;font-weight:600}
.badge-red{background:#7f1d1d;color:#fca5a5}.badge-green{background:#14532d;color:#86efac}.badge-blue{background:#1e3a5f;color:#93c5fd}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.field{display:flex;flex-direction:column;gap:5px}.field.full{grid-column:1/-1}
.field label{font-size:.82rem;color:#9ca3af;font-weight:500}
.field input{background:#0a0e1a;border:1px solid #1f2937;border-radius:9px;padding:11px 14px;color:#e5e7eb;font-size:.92rem}
.field input:focus{outline:none;border-color:#3b82f6}
.checks{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.check{display:flex;align-items:center;gap:8px;padding:10px 14px;background:#0a0e1a;border-radius:9px;border:1px solid #1f2937;cursor:pointer;font-size:.88rem}
.check:hover{border-color:#3b82f6}
.check input[type=checkbox]{accent-color:#3b82f6;width:16px;height:16px}
.check .info{color:#6b7280;font-size:.75rem;margin-left:auto}
.btns{display:flex;gap:12px;margin-top:26px}
.btn{padding:13px 24px;border-radius:10px;border:none;font-size:.95rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;font-family:inherit}
.btn-primary{background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;flex:1}
.btn-secondary{background:#1f2937;color:#e5e7eb}
.arrow{text-align:center;font-size:1.6rem;color:#374151;margin:6px 0}
.alert{padding:14px 18px;border-radius:10px;margin-top:16px;font-size:.88rem;display:none;white-space:pre-line;line-height:1.6}
.alert-error{background:#450a0a;border:1px solid #991b1b;color:#fca5a5}
.alert-success{background:#052e16;border:1px solid #166534;color:#86efac}
.footer{text-align:center;margin-top:28px;font-size:.8rem;color:#4b5563}
.footer a{color:#3b82f6;text-decoration:none}
.key-section{display:flex;gap:12px;align-items:flex-end}.key-section .field{flex:1}
.key-link{font-size:.82rem;color:#3b82f6;text-decoration:none;white-space:nowrap;padding-bottom:13px}
.spinner{display:none;width:18px;height:18px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:600px){.grid,.checks{grid-template-columns:1fr}}
</style>
</head><body>
<div class="container">
    <div class="logo"><h1>ZERONKIT</h1><p>WoWonder → SocialEngine Migration v<?= ZERONKIT_VERSION ?></p></div>
    <form method="POST" id="migForm">
        <div class="card">
            <h2>WoWonder Database <span class="badge badge-red">Source</span></h2>
            <div class="grid">
                <div class="field"><label>Host</label><input type="text" name="wo_host" value="localhost" required></div>
                <div class="field"><label>Port</label><input type="number" name="wo_port" value="3306" required></div>
                <div class="field"><label>Username</label><input type="text" name="wo_user" required></div>
                <div class="field"><label>Password</label><input type="text" name="wo_pass"></div>
                <div class="field full"><label>Database Name</label><input type="text" name="wo_db" required></div>
            </div>
        </div>

        <div class="arrow">↓</div>

        <div class="card">
            <h2>SocialEngine Database <span class="badge badge-green">Target</span></h2>
            <div class="grid">
                <div class="field"><label>Host</label><input type="text" name="se_host" value="localhost" required></div>
                <div class="field"><label>Port</label><input type="number" name="se_port" value="3306" required></div>
                <div class="field"><label>Username</label><input type="text" name="se_user" required></div>
                <div class="field"><label>Password</label><input type="text" name="se_pass"></div>
                <div class="field full"><label>Database Name</label><input type="text" name="se_db" required></div>
            </div>
        </div>

        <div class="card">
            <h2>What to Migrate <span class="badge badge-blue">Select</span></h2>
            <div class="checks">
                <label class="check"><input type="checkbox" name="migrate_users" checked> Users & Passwords <span class="info">Required</span></label>
                <label class="check"><input type="checkbox" name="migrate_followers" checked> Follows</label>
                <label class="check"><input type="checkbox" name="migrate_posts" checked> Posts (as Activity)</label>
                <label class="check"><input type="checkbox" name="migrate_comments" checked> Comments</label>
                <label class="check"><input type="checkbox" name="migrate_reactions" checked> Likes</label>
                <label class="check"><input type="checkbox" name="migrate_messages"> Messages <span class="info">Slow</span></label>
                <label class="check"><input type="checkbox" name="migrate_blocks" checked> Blocks</label>
            </div>
        </div>

        <div class="alert alert-error" id="alertError"></div>
        <div class="alert alert-success" id="alertSuccess"></div>

        <div class="btns">
            <button type="button" class="btn btn-secondary" id="btnTest" onclick="testConnection()">
                <div class="spinner" id="spinTest"></div> Test Connection
            </button>
            <button type="submit" name="run_migration" class="btn btn-primary" onclick="return confirmMig()">
                Start Migration
            </button>
        </div>
    </form>
    <div class="footer"><a href="<?= ZERONKIT_SITE ?>" target="_blank">zeronkit.com</a>: Free open-source migration tools</div>
</div>
<script>
function hideA(){document.getElementById('alertError').style.display='none';document.getElementById('alertSuccess').style.display='none'}
function showE(m){hideA();var e=document.getElementById('alertError');e.textContent=m;e.style.display='block'}
function showS(m){hideA();var e=document.getElementById('alertSuccess');e.textContent=m;e.style.display='block'}
function testConnection(){
    hideA();
    var btn=document.getElementById('btnTest'),spin=document.getElementById('spinTest');
    btn.disabled=true;spin.style.display='block';
    var fd=new FormData(document.getElementById('migForm'));fd.append('test_connection','1');
    fetch(window.location.href,{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(d){
        btn.disabled=false;spin.style.display='none';
        var m='';
        if(d.wowonder)m+=d.wowonder.status==='ok'?'WoWonder: Connected ('+d.wowonder.users+' users)\n':'WoWonder: FAILED - '+d.wowonder.message+'\n';
        if(d.socialengine)m+=d.socialengine.status==='ok'?'SocialEngine: Connected ('+d.socialengine.users+' users)':'SocialEngine: FAILED - '+d.socialengine.message;
        d.wowonder&&d.wowonder.status==='ok'&&d.socialengine&&d.socialengine.status==='ok'?showS(m):showE(m);
    }).catch(function(e){btn.disabled=false;spin.style.display='none';showE('Error: '+e.message)});
}
function confirmMig(){
    return confirm('This will overwrite SocialEngine data.\n\nMake sure you have a backup!\n\nContinue?');
}
</script>
</body></html>
