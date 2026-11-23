<?php
/**
 * ZeronKit: WoWonder to Sngine Migration Tool
 * Version: 2.1
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
 *  1. Upload this file to your Sngine root directory
 *  2. Open it in your browser: yourdomain.com/wowonder-to-sngine.php
 *  3. Enter your database credentials
 *  4. Click "Start Migration"
 *  5. DELETE this file after migration is complete
 *
 *  REQUIREMENTS:
 *  - PHP 7.4 or higher
 *  - PHP extension: mysqli
 *  - Access to both WoWonder and Sngine databases
 *
 *  SUPPORT: contact@zeronkit.com
 */

define('ZERONKIT_VERSION', '2.1');
define('ZERONKIT_SITE', 'https://zeronkit.com');
define('ZERONKIT_MIN_PHP', '7.4.0');

// ============================================================
// SYSTEM CHECK: Verify requirements before anything else
// ============================================================
$errors = [];

if (version_compare(PHP_VERSION, ZERONKIT_MIN_PHP, '<')) {
    $errors[] = 'PHP ' . ZERONKIT_MIN_PHP . '+ required. You have PHP ' . PHP_VERSION;
}
if (!extension_loaded('mysqli')) {
    $errors[] = 'PHP extension "mysqli" is not installed. Contact your hosting provider to enable it.';
}
if (!empty($errors)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>System Check Failed | ZeronKit</title>';
    echo '<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,sans-serif;background:#0a0e1a;color:#c9d1d9;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}';
    echo '.card{max-width:550px;width:100%;background:#111827;border-radius:16px;padding:40px;border:1px solid #1f2937;text-align:center}';
    echo 'h1{font-size:1.5rem;color:#ef4444;margin-bottom:8px}p.sub{color:#6b7280;margin-bottom:24px;font-size:.9rem}';
    echo '.err{background:#450a0a;border:1px solid #991b1b;border-radius:10px;padding:14px 18px;margin-bottom:12px;text-align:left;color:#fca5a5;font-size:.9rem;line-height:1.6}';
    echo '.help{margin-top:24px;color:#6b7280;font-size:.85rem}.help a{color:#3b82f6;text-decoration:none}';
    echo '</style></head><body><div class="card"><h1>System Check Failed</h1><p class="sub">Your server is missing requirements needed to run this tool.</p>';
    foreach ($errors as $e) echo '<div class="err">' . htmlspecialchars($e) . '</div>';
    echo '<div class="help">Need help? Visit <a href="' . ZERONKIT_SITE . '">zeronkit.com</a> or email <a href="mailto:contact@zeronkit.com">contact@zeronkit.com</a></div>';
    echo '</div></body></html>';
    exit;
}

// ============================================================
// MIGRATION ENGINE (formerly protected/migration_engine.php v1.1)
// Runs locally with the same inputs the old API used to inject:
// $_MIGRATION_STEPS, $_WO_CONFIG and $_SN_CONFIG.
// ============================================================
function zk_out($msg, $class = '') {
    if ($class) echo "<span class='$class'>$msg</span>\n";
    else echo "$msg\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function zk_esc($db, $v) { return $db->real_escape_string($v ?? ''); }
function zk_ts($ts) { $t = (int)$ts; return $t > 0 ? date('Y-m-d H:i:s', $t) : date('Y-m-d H:i:s'); }

// Run one write query. A failing row is reported and skipped, the run goes on. Returns the rows really written.
function zk_q($db, $sql) {
    global $zk_failed;
    if ($db->query($sql)) return max(0, $db->affected_rows);
    $zk_failed = (int)$zk_failed + 1;
    if ($zk_failed <= 50) zk_out("  Skipped, database error: " . htmlspecialchars($db->error), 'warn');
    elseif ($zk_failed == 51) zk_out("  More database errors: only the first 50 are shown", 'warn');
    return 0;
}

// Read source rows. A missing table or column is reported and gives no rows instead of a fatal error.
function zk_rows($db, $sql) {
    $r = $db->query($sql);
    if (!$r) { zk_out("  Could not read from WoWonder: " . htmlspecialchars($db->error) . " (skipped)", 'warn'); return; }
    while ($row = $r->fetch_assoc()) yield $row;
    $r->free();
}

// Open a database connection. Returns null and prints a clear message when the login fails.
function zk_connect($cfg, $name) {
    $db = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db'], $cfg['port']);
    if ($db->connect_error) {
        zk_out("$name database connection failed: " . htmlspecialchars($db->connect_error), 'err');
        zk_out("Nothing was changed. Check the $name host, port, username, password and database name, then try again.", 'err');
        return null;
    }
    $db->set_charset('utf8mb4');
    return $db;
}

// Fix WoWonder text formatting: [a]URL[/a], <br>, HTML entities, #[ID], @[ID]
function zk_fix_text($text, $hashtags, $usernames) {
    // Fix #[ID] to #tag_name
    $text = preg_replace_callback('/#\[(\d+)\]/', function($m) use ($hashtags) {
        return isset($hashtags[$m[1]]) ? '#' . $hashtags[$m[1]] : $m[0];
    }, $text);
    // Fix @[ID] to @username
    $text = preg_replace_callback('/@\[(\d+)\]/', function($m) use ($usernames) {
        return isset($usernames[$m[1]]) ? '@' . $usernames[$m[1]] : $m[0];
    }, $text);
    // Fix [a]URL[/a] to plain URL
    $text = preg_replace_callback('/\[a\](.*?)\[\/a\]/', function($m) {
        return urldecode($m[1]);
    }, $text);
    // Fix HTML entities
    $text = str_replace(['&lt;', '&gt;', '&quot;', '&amp;'], ['<', '>', '"', '&'], $text);
    // Remove <a> tags, keep text
    $text = preg_replace('/<a[^>]*>(.*?)<\/a>/s', '$1', $text);
    // Convert <br> to newlines
    $text = preg_replace('/<br\s*\/?>/', "\n", $text);
    // Strip remaining HTML
    $text = strip_tags($text);
    // Clean multiple newlines
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

// Fix media path: upload/ to content/uploads/
function zk_fix_path($path) {
    if (!empty($path) && strpos($path, 'upload/') === 0) {
        return 'content/uploads/' . substr($path, 7);
    }
    return $path;
}

function zk_run_migration($_MIGRATION_STEPS, $_WO_CONFIG, $_SN_CONFIG) {
    set_time_limit(0);
    ini_set('memory_limit', '2G');
    // PHP 8.1+ turns every mysqli error into a fatal exception; every result is checked below instead.
    mysqli_report(MYSQLI_REPORT_OFF);
    global $zk_failed;
    $zk_failed = 0;

    $wo = zk_connect($_WO_CONFIG, 'WoWonder');
    if (!$wo) return;
    $sn = zk_connect($_SN_CONFIG, 'Sngine');
    if (!$sn) return;

    zk_out("Connected to both databases", 'ok');
    zk_out("");

    $sn->query("SET FOREIGN_KEY_CHECKS = 0");
    $sn->query("SET SQL_MODE = ''");
    $stats = [];
    $step_num = 0;
    $total_steps = count($_MIGRATION_STEPS) + 1; // +1 for fixes step

    // Pre-load hashtags and usernames for text fixing
    zk_out("Loading hashtags and usernames for text processing...", 'info');
    $hashtags = [];
    foreach (zk_rows($wo, "SELECT id, tag FROM Wo_Hashtags") as $row) $hashtags[$row['id']] = $row['tag'];

    $usernames = [];
    foreach (zk_rows($wo, "SELECT user_id, username FROM Wo_Users") as $row) $usernames[$row['user_id']] = $row['username'];
    zk_out("  Loaded " . count($hashtags) . " hashtags, " . count($usernames) . " usernames", 'ok');
    zk_out("");

    // The Sngine admin keeps id 1. WoWonder user 1 gets the first id after the last WoWonder user,
    // and $U() moves every reference to WoWonder user 1 along with it.
    $u1 = 1;
    $U = function ($id) use (&$u1) { $id = (int)$id; return $id === 1 ? $u1 : $id; };

    // ====== USERS ======
    if (in_array('users', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Users...", 'info');
        zk_q($sn, "DELETE FROM users WHERE user_id > 1");
        $c = 0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Users ORDER BY user_id ASC") as $u) {
            $id = (int)$u['user_id'];
            if ($id === 1) {
                $name = htmlspecialchars($u['username']);
                $dup = $sn->query("SELECT user_id FROM users WHERE user_name='" . zk_esc($sn,$u['username']) . "' OR user_email='" . zk_esc($sn,$u['email']) . "' LIMIT 1");
                if ($dup && $dup->num_rows) {
                    $stats['WoWonder user 1'] = "same as Sngine admin, merged into id 1";
                    zk_out("  WoWonder user 1 ($name) has the same username or email as the Sngine admin: no second account was made, its content now belongs to the admin (id 1). The admin login is unchanged.", 'warn');
                    continue;
                }
                $u1 = $id = max(array_keys($usernames)) + 1;
                $stats['WoWonder user 1'] = "moved to id $id";
                zk_out("  WoWonder user 1 ($name) is migrated as user id $id, because id 1 is the Sngine admin (left unchanged).", 'warn');
            }
            $admin = ($u['admin'] == '1') ? 1 : 3;
            $gender = 'NULL';
            if ($u['gender'] == 'male') $gender = 1; elseif ($u['gender'] == 'female') $gender = 2;
            $country = ((int)$u['country_id'] > 0) ? (int)$u['country_id'] : 'NULL';
            $bd = $u['birthday'] ?? '';
            $bday = ($bd && $bd != '0000-00-00' && $bd != '0') ? "'" . zk_esc($sn, $bd) . "'" : 'NULL';
            $time = zk_ts($u['joined'] ?? 0);
            $seen = zk_ts(($u['lastseen'] ?? 0) ?: ($u['joined'] ?? 0));
            $active = ($u['active'] == '1') ? '1' : '0';
            // Fix avatar/cover paths
            $avatar = zk_fix_path($u['avatar'] ?? '');
            $cover = zk_fix_path($u['cover'] ?? '');

            $sql = "INSERT INTO users (user_id,user_group,user_name,user_email,user_password,user_firstname,user_lastname,user_gender,user_country,user_birthdate,user_picture,user_cover,user_biography,user_website,user_verified,user_activated,user_approved,user_email_verified,user_registered,user_last_seen) VALUES ($id,$admin,'" . zk_esc($sn,$u['username']) . "','" . zk_esc($sn,$u['email']) . "','" . zk_esc($sn,$u['password']) . "','" . zk_esc($sn,$u['first_name']?:$u['username']) . "','" . zk_esc($sn,$u['last_name']) . "',$gender,$country,$bday,'" . zk_esc($sn,$avatar) . "','" . zk_esc($sn,$cover) . "','" . zk_esc($sn,$u['about']) . "','" . zk_esc($sn,$u['website']) . "','" . (($u['verified']=='1')?'1':'0') . "','$active','1','$active','$time','$seen')";
            $c += zk_q($sn, $sql);
        }
        $stats['Users'] = $c;
        zk_out("  $c users migrated", 'ok');
    }

    // ====== FOLLOWERS ======
    if (in_array('followers', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Followers...", 'info');
        zk_q($sn, "TRUNCATE TABLE followings"); zk_q($sn, "TRUNCATE TABLE friends");
        $cf=0;$cfr=0;$pairs=[];
        foreach (zk_rows($wo, "SELECT * FROM Wo_Followers WHERE active = 1") as $f) {
            $a=$U($f['follower_id']);$b=$U($f['following_id']);
            $cf+=zk_q($sn,"INSERT IGNORE INTO followings (user_id,following_id,time) VALUES ($a,$b,'".zk_ts($f['time'])."')");
            $k=min($a,$b).'-'.max($a,$b);
            if(isset($pairs[$k]))$cfr+=zk_q($sn,"INSERT IGNORE INTO friends (user_one_id,user_two_id,status) VALUES (".min($a,$b).",".max($a,$b).",1)");
            else $pairs[$k]=true;
        }
        $stats['Followings']=$cf;$stats['Friends']=$cfr;
        zk_out("  $cf followings, $cfr friendships",'ok');
    }

    // ====== PAGES ======
    if (in_array('pages', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Pages...", 'info');
        zk_q($sn, "DELETE FROM pages WHERE page_id > 0"); zk_q($sn, "TRUNCATE TABLE pages_likes");
        $c=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Pages ORDER BY page_id ASC") as $p) {
            $avatar = zk_fix_path($p['avatar'] ?? '');
            $cover = zk_fix_path($p['cover'] ?? '');
            $c+=zk_q($sn,"INSERT INTO pages (page_id,page_admin,page_category,page_name,page_title,page_description,page_picture,page_cover,page_verified,page_website,page_phone,page_company,page_location,page_country,page_language,page_date) VALUES (".(int)$p['page_id'].",".$U($p['user_id']).",1,'".zk_esc($sn,$p['page_name'])."','".zk_esc($sn,$p['page_title'])."','".zk_esc($sn,$p['page_description'])."','".zk_esc($sn,$avatar)."','".zk_esc($sn,$cover)."','".(($p['verified']??'0')=='1'?'1':'0')."','".zk_esc($sn,$p['website'])."','".zk_esc($sn,$p['phone'])."','".zk_esc($sn,$p['company'])."','".zk_esc($sn,$p['address'])."',0,0,'".zk_ts($p['time']??0)."')");
        }
        $cl=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Pages_Likes") as $pl) $cl+=zk_q($sn,"INSERT IGNORE INTO pages_likes (page_id,user_id) VALUES (".(int)$pl['page_id'].",".$U($pl['user_id']).")");
        $stats['Pages']=$c;$stats['Page Likes']=$cl;
        zk_out("  $c pages, $cl likes",'ok');
    }

    // ====== GROUPS ======
    if (in_array('groups', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Groups...", 'info');
        zk_q($sn, "DELETE FROM `groups` WHERE group_id > 0"); zk_q($sn, "TRUNCATE TABLE groups_members");
        $c=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Groups ORDER BY id ASC") as $g) {
            $pr=($g['privacy']=='2')?'closed':'public';
            $avatar = zk_fix_path($g['avatar'] ?? '');
            $cover = zk_fix_path($g['cover'] ?? '');
            $c+=zk_q($sn,"INSERT INTO `groups` (group_id,group_admin,group_category,group_name,group_title,group_description,group_privacy,group_picture,group_cover,group_country,group_language,group_date) VALUES (".(int)$g['id'].",".$U($g['user_id']).",1,'".zk_esc($sn,$g['group_name'])."','".zk_esc($sn,$g['group_title'])."','".zk_esc($sn,$g['about'])."','$pr','".zk_esc($sn,$avatar)."','".zk_esc($sn,$cover)."',0,0,'".zk_ts($g['time'])."')");
        }
        $cm=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Group_Members WHERE active='1'") as $gm) $cm+=zk_q($sn,"INSERT IGNORE INTO groups_members (group_id,user_id,approved) VALUES (".(int)$gm['group_id'].",".$U($gm['user_id']).",'1')");
        $stats['Groups']=$c;$stats['Group Members']=$cm;
        zk_out("  $c groups, $cm members",'ok');
    }

    // ====== POSTS ======
    if (in_array('posts', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Posts...", 'info');
        zk_q($sn,"TRUNCATE TABLE posts");zk_q($sn,"TRUNCATE TABLE posts_photos");zk_q($sn,"TRUNCATE TABLE posts_videos");
        zk_q($sn,"TRUNCATE TABLE posts_products");zk_q($sn,"TRUNCATE TABLE posts_articles");
        zk_q($sn,"TRUNCATE TABLE hashtags_posts");
        $c=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Posts ORDER BY id ASC") as $p) {
            $post_id=(int)$p['id'];
            $views=(int)($p['videoViews'] ?? 0);
            // Fix text: hashtags, mentions, links, formatting
            $raw_text = $p['postText'] ?? '';
            $fixed_text = zk_fix_text($raw_text, $hashtags, $usernames);
            $txt=zk_esc($sn,$fixed_text);
            $time=zk_ts($p['time']);$wt=$p['postType'];

            // Detect post type from postFile extension
            $file = $p['postFile'] ?? '';
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $photo_exts = ['jpg','jpeg','png','gif','webp','bmp','svg'];
            $video_exts = ['mp4','webm','mov','avi','mkv','wmv','flv','3gp'];

            $pt='text';
            if(in_array($wt,['photo','profile_picture','profile_cover','profile_picture_deleted','avatar','cover']))$pt='photos';
            elseif(in_array($wt,['video','youtube','vimeo','dailymotion','facebook']))$pt='video';
            elseif($wt=='file')$pt='file';
            elseif(in_array($wt,['music','soundcloud']))$pt='audio';
            elseif($wt=='map')$pt='map';
            elseif($wt=='poll')$pt='poll';
            elseif($wt=='sticker')$pt='sticker';
            elseif(!empty($file) && in_array($ext, $photo_exts))$pt='photos';
            elseif(!empty($file) && in_array($ext, $video_exts))$pt='video';
            elseif(!empty($p['postPhoto']))$pt='photos';
            elseif(!empty($file))$pt='file';

            $pv='public';if($p['postPrivacy']=='2')$pv='friends';elseif($p['postPrivacy']=='3')$pv='me';
            $ig=($p['group_id']>0)?'1':'0';$gid=($p['group_id']>0)?(int)$p['group_id']:'NULL';
            $ut=($p['page_id']>0)?'page':'user';$uid=($p['page_id']>0)?(int)$p['page_id']:$U($p['user_id']);
            $oid=($p['postShare']>0)?(int)$p['postShare']:'NULL';
            $loc=!empty($p['postMap'])?"'".zk_esc($sn,$p['postMap'])."'":'NULL';
            $fa='NULL';$fv='NULL';
            foreach(['postFeeling'=>'feeling','postListening'=>'listening','postTraveling'=>'traveling','postWatching'=>'watching','postPlaying'=>'playing'] as $col=>$act){
                if(!empty($p[$col])){$fa="'$act'";$fv="'".zk_esc($sn,$p[$col])."'";break;}
            }
            if(zk_q($sn,"INSERT INTO posts (post_id,user_id,user_type,post_type,in_group,group_id,time,privacy,text,feeling_action,feeling_value,location,origin_id,comments,shares,views) VALUES ($post_id,$uid,'$ut','$pt','$ig',$gid,'$time','$pv','$txt',$fa,$fv,$loc,$oid,0,0,$views)")){
                $c++;

                // Insert media from postFile
                if (!empty($file)) {
                    $fixed_file = zk_fix_path($file);
                    $fixed_thumb = zk_fix_path($p['postFileThumb'] ?? '');
                    if ($pt == 'photos') {
                        zk_q($sn,"INSERT INTO posts_photos (post_id,source) VALUES ($post_id,'".zk_esc($sn,$fixed_file)."')");
                    } elseif ($pt == 'video') {
                        zk_q($sn,"INSERT INTO posts_videos (post_id,category_id,source,thumbnail,views) VALUES ($post_id,0,'".zk_esc($sn,$fixed_file)."','".zk_esc($sn,$fixed_thumb)."',$views)");
                    }
                }

                // Insert hashtag-post links
                preg_match_all('/#\[(\d+)\]/', $raw_text, $hmatches);
                if (!empty($hmatches[1])) {
                    foreach (array_unique($hmatches[1]) as $hid) {
                        if (isset($hashtags[$hid])) {
                            zk_q($sn,"INSERT IGNORE INTO hashtags_posts (hashtag_id,post_id) VALUES ($hid,$post_id)");
                        }
                    }
                }
            }
        }

        // Insert album media (multi-image posts)
        $album_c = 0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Albums_Media WHERE image != '' ORDER BY id ASC") as $am) {
            $post_id = (int)$am['post_id'];
            $check = $sn->query("SELECT post_id FROM posts WHERE post_id = $post_id");
            if ($check && $check->num_rows > 0) {
                $fixed_img = zk_fix_path($am['image']);
                if (zk_q($sn,"INSERT INTO posts_photos (post_id,source) VALUES ($post_id,'".zk_esc($sn,$fixed_img)."')")) {
                    zk_q($sn,"UPDATE posts SET post_type='photos' WHERE post_id=$post_id");
                    $album_c++;
                }
            }
        }

        $stats['Posts']=$c;
        $r=$sn->query("SELECT COUNT(*) as c FROM posts_photos");$stats['Photos']=$r?(int)$r->fetch_assoc()['c']:0;
        $r=$sn->query("SELECT COUNT(*) as c FROM posts_videos");$stats['Videos']=$r?(int)$r->fetch_assoc()['c']:0;
        zk_out("  $c posts, {$stats['Photos']} photos, {$stats['Videos']} videos, $album_c album items",'ok');
    }

    // ====== REACTIONS ======
    if (in_array('reactions', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Reactions (may take a while)...", 'info');
        zk_q($sn,"TRUNCATE TABLE posts_reactions");zk_q($sn,"TRUNCATE TABLE posts_comments_reactions");
        $rm=['1'=>'like','2'=>'love','3'=>'haha','4'=>'yay','5'=>'wow','6'=>'sad','7'=>'angry','like'=>'like','love'=>'love','haha'=>'haha','wow'=>'wow','sad'=>'sad','angry'=>'angry','yay'=>'yay'];
        // WoWonder stores no reaction time, so the run time is used.
        $cr=0;$rc=[];
        foreach (zk_rows($wo, "SELECT * FROM Wo_Reactions WHERE post_id > 0") as $r) {
            $pid=(int)$r['post_id'];$m=$rm[strtolower($r['reaction']??'like')]??'like';
            if(zk_q($sn,"INSERT IGNORE INTO posts_reactions (post_id,user_id,reaction,reaction_time) VALUES ($pid,".$U($r['user_id']).",'$m',NOW())"))
            {$cr++;$rc[$pid][$m]=($rc[$pid][$m]??0)+1;}
        }
        foreach($rc as $pid=>$rr){$s=[];foreach(['like','love','haha','yay','wow','sad','angry'] as $r)$s[]="reaction_{$r}_count=".($rr[$r]??0);zk_q($sn,"UPDATE posts SET ".implode(',',$s)." WHERE post_id=$pid");}
        $stats['Reactions']=$cr;
        $ccr=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Reactions WHERE comment_id > 0") as $r) {$m=$rm[strtolower($r['reaction']??'like')]??'like';$ccr+=zk_q($sn,"INSERT IGNORE INTO posts_comments_reactions (comment_id,user_id,reaction,reaction_time) VALUES (".(int)$r['comment_id'].",".$U($r['user_id']).",'$m',NOW())");}
        $stats['Comment Reactions']=$ccr;
        zk_out("  $cr post reactions, $ccr comment reactions",'ok');
    }

    // ====== COMMENTS ======
    if (in_array('comments', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Comments...", 'info');
        zk_q($sn,"DELETE FROM posts_comments WHERE comment_id > 0");
        $cc=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Comments ORDER BY id ASC") as $c) {
            $fixed_text = zk_fix_text($c['text'] ?? '', $hashtags, $usernames);
            $fixed_img = zk_fix_path($c['c_file'] ?? '');
            $cc+=zk_q($sn,"INSERT INTO posts_comments (comment_id,node_id,node_type,user_id,user_type,text,image,time) VALUES (".(int)$c['id'].",".(int)$c['post_id'].",'post',".$U($c['user_id']).",'user','".zk_esc($sn,$fixed_text)."','".zk_esc($sn,$fixed_img)."','".zk_ts($c['time'])."')");
        }
        $cr=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Comment_Replies ORDER BY id ASC") as $r) {
            $fixed_text = zk_fix_text($r['text'] ?? '', $hashtags, $usernames);
            $fixed_img = zk_fix_path($r['c_file'] ?? '');
            $cr+=zk_q($sn,"INSERT INTO posts_comments (node_id,node_type,user_id,user_type,text,image,time) VALUES (".(int)$r['comment_id'].",'comment',".$U($r['user_id']).",'user','".zk_esc($sn,$fixed_text)."','".zk_esc($sn,$fixed_img)."','".zk_ts($r['time'])."')");
        }
        zk_q($sn,"UPDATE posts p SET comments=(SELECT COUNT(*) FROM posts_comments c WHERE c.node_id=p.post_id AND c.node_type='post')");
        $stats['Comments']=$cc;$stats['Replies']=$cr;
        zk_out("  $cc comments, $cr replies",'ok');
    }

    // ====== MESSAGES ======
    if (in_array('messages', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Messages...", 'info');
        zk_q($sn,"TRUNCATE TABLE conversations");zk_q($sn,"TRUNCATE TABLE conversations_messages");zk_q($sn,"TRUNCATE TABLE conversations_users");
        $cmap=[];$cid=0;$cc=0;
        foreach (zk_rows($wo, "SELECT DISTINCT LEAST(from_id,to_id) as u1, GREATEST(from_id,to_id) as u2 FROM Wo_Messages WHERE to_id>0 AND from_id>0 AND group_id=0 AND page_id=0") as $pair) {
            $a=$U($pair['u1']);$b=$U($pair['u2']);
            $cid++;$cmap[min($a,$b).'-'.max($a,$b)]=$cid;
            $cc+=zk_q($sn,"INSERT INTO conversations (conversation_id,last_message_id) VALUES ($cid,0)");
            zk_q($sn,"INSERT INTO conversations_users (conversation_id,user_id,seen) VALUES ($cid,$a,'1')");
            zk_q($sn,"INSERT INTO conversations_users (conversation_id,user_id,seen) VALUES ($cid,$b,'1')");
        }
        $mc=0;$last=[];
        foreach (zk_rows($wo, "SELECT * FROM Wo_Messages WHERE to_id>0 AND from_id>0 AND group_id=0 AND page_id=0 ORDER BY id ASC") as $m) {
            $from=$U($m['from_id']);$to=$U($m['to_id']);
            $k=min($from,$to).'-'.max($from,$to);
            if(!isset($cmap[$k]))continue;$cv=$cmap[$k];
            $fixed_text = zk_fix_text($m['text'] ?? '', $hashtags, $usernames);
            $fixed_media = zk_fix_path($m['media'] ?? '');
            if(zk_q($sn,"INSERT INTO conversations_messages (conversation_id,user_id,message,image,time) VALUES ($cv,$from,'".zk_esc($sn,$fixed_text)."','".zk_esc($sn,$fixed_media)."','".zk_ts($m['time'])."')"))
            {$mc++;$last[$cv]=$sn->insert_id;}
        }
        foreach($last as $cv=>$lid)zk_q($sn,"UPDATE conversations SET last_message_id=$lid WHERE conversation_id=$cv");
        $stats['Conversations']=$cc;$stats['Messages']=$mc;
        zk_out("  $cc conversations, $mc messages",'ok');
    }

    // ====== HASHTAGS ======
    if (in_array('hashtags', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Hashtags...", 'info');
        zk_q($sn,"TRUNCATE TABLE hashtags");
        $c=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Hashtags ORDER BY id ASC") as $h) $c+=zk_q($sn,"INSERT IGNORE INTO hashtags (hashtag_id,hashtag) VALUES (".(int)$h['id'].",'".zk_esc($sn,$h['tag'])."')");
        $stats['Hashtags']=$c;
        zk_out("  $c hashtags",'ok');
    }

    // ====== BLOCKS ======
    if (in_array('blocks', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Blocks...", 'info');
        zk_q($sn,"TRUNCATE TABLE users_blocks");
        $c=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Blocks") as $b) $c+=zk_q($sn,"INSERT IGNORE INTO users_blocks (user_id,blocked_id) VALUES (".$U($b['blocker']).",".$U($b['blocked']).")");
        $stats['Blocks']=$c;
        zk_out("  $c blocks",'ok');
    }

    // ====== SAVED POSTS ======
    if (in_array('saved_posts', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Saved Posts...", 'info');
        zk_q($sn,"TRUNCATE TABLE posts_saved");
        $c=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_SavedPosts") as $s) $c+=zk_q($sn,"INSERT IGNORE INTO posts_saved (post_id,user_id,time) VALUES (".(int)$s['post_id'].",".$U($s['user_id']).",'".zk_ts($s['time']??0)."')");
        $stats['Saved Posts']=$c;
        zk_out("  $c saved posts",'ok');
    }

    // ====== EVENTS ======
    if (in_array('events', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Events...", 'info');
        zk_q($sn,"DELETE FROM events WHERE event_id > 0");
        $c=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Events ORDER BY id ASC") as $e) {
            $sd=($e['start_date']&&$e['start_date']!='0000-00-00')?$e['start_date']:date('Y-m-d');
            $ed=($e['end_date']&&$e['end_date']!='0000-00-00')?$e['end_date']:date('Y-m-d');
            $st=(!empty($e['start_time'])&&$e['start_time']!='00:00:00')?$e['start_time']:'12:00:00';
            $et=(!empty($e['end_time'])&&$e['end_time']!='00:00:00')?$e['end_time']:'23:59:59';
            $cover = zk_fix_path($e['cover'] ?? '');
            // event_date is the creation time: taken from WoWonder when it has one, otherwise the run time.
            $created=isset($e['time'])?"'".zk_ts($e['time'])."'":'NOW()';
            $c+=zk_q($sn,"INSERT INTO events (event_id,event_admin,event_category,event_title,event_description,event_location,event_start_date,event_end_date,event_cover,event_country,event_language,event_date) VALUES (".(int)$e['id'].",".$U($e['poster_id']??0).",1,'".zk_esc($sn,$e['name'])."','".zk_esc($sn,$e['description'])."','".zk_esc($sn,$e['location'])."','".zk_esc($sn,"$sd $st")."','".zk_esc($sn,"$ed $et")."','".zk_esc($sn,$cover)."',0,0,$created)");
        }
        $stats['Events']=$c;
        zk_out("  $c events",'ok');
    }

    // ====== PRODUCTS ======
    if (in_array('products', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Products...", 'info');
        // Clear products from an earlier run so a rerun does not duplicate them.
        zk_q($sn,"DELETE FROM posts WHERE post_type='product'");zk_q($sn,"TRUNCATE TABLE posts_products");
        $c=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Products ORDER BY id ASC") as $pr) {
            $uid=$U($pr['user_id']);$n=zk_esc($sn,$pr['name']??'');$d=zk_esc($sn,$pr['description']??'');
            if(zk_q($sn,"INSERT INTO posts (user_id,user_type,post_type,text,time,privacy) VALUES ($uid,'user','product','$n - $d','".zk_ts($pr['time']??0)."','public')")){
                $pid=$sn->insert_id;$c+=zk_q($sn,"INSERT INTO posts_products (post_id,name,category_id,status,price,location) VALUES ($pid,'$n',0,'new',".(float)($pr['price']??0).",'".zk_esc($sn,$pr['location']??'')."')");
            }
        }
        $stats['Products']=$c;
        zk_out("  $c products",'ok');
    }

    // ====== BLOGS ======
    if (in_array('blogs', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Blogs...", 'info');
        // Clear articles from an earlier run so a rerun does not duplicate them.
        zk_q($sn,"DELETE FROM posts WHERE post_type='article'");zk_q($sn,"TRUNCATE TABLE posts_articles");
        $c=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Blog ORDER BY id ASC") as $b) {
            $uid=(int)($b['user']??0);if($uid<=0)continue;
            $uid=$U($uid);
            $thumb = zk_fix_path($b['thumbnail'] ?? '');
            if(zk_q($sn,"INSERT INTO posts (user_id,user_type,post_type,text,time,privacy) VALUES ($uid,'user','article','".zk_esc($sn,$b['title']??'')."','".zk_ts($b['posted']??0)."','public')")){
                $pid=$sn->insert_id;$c+=zk_q($sn,"INSERT INTO posts_articles (post_id,cover,title,text,category_id) VALUES ($pid,'".zk_esc($sn,$thumb)."','".zk_esc($sn,$b['title']??'')."','".zk_esc($sn,$b['content']??'')."',0)");
            }
        }
        $stats['Blogs']=$c;
        zk_out("  $c blogs",'ok');
    }

    // ====== POKES ======
    if (in_array('pokes', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Pokes...", 'info');
        zk_q($sn,"TRUNCATE TABLE users_pokes");
        $c=0;
        foreach (zk_rows($wo, "SELECT * FROM Wo_Pokes") as $pk) {$s=(int)($pk['send_user_id']??0);$r=(int)($pk['received_user_id']??0);if($s>0&&$r>0)$c+=zk_q($sn,"INSERT IGNORE INTO users_pokes (user_id,poked_id) VALUES (".$U($s).",".$U($r).")");}
        $stats['Pokes']=$c;
        zk_out("  $c pokes",'ok');
    }

    // ====== UPDATE COUNTERS ======
    $step_num++;
    zk_out("\n[$step_num/$total_steps] Updating counters & final cleanup...", 'info');
    zk_q($sn,"UPDATE pages p SET page_likes=(SELECT COUNT(*) FROM pages_likes pl WHERE pl.page_id=p.page_id)");
    zk_q($sn,"UPDATE `groups` g SET group_members=(SELECT COUNT(*) FROM groups_members gm WHERE gm.group_id=g.group_id)");
    zk_q($sn,"UPDATE posts_comments c INNER JOIN (SELECT node_id,COUNT(*) as cnt FROM posts_comments WHERE node_type='comment' GROUP BY node_id) r ON c.comment_id=r.node_id SET c.replies=r.cnt");
    $sn->query("SET FOREIGN_KEY_CHECKS = 1");
    zk_out("  Done", 'ok');
    if ($zk_failed) $stats['Rows skipped (errors)'] = $zk_failed;

    // ====== SUMMARY ======
    echo "</div><div class='summary'><h2>Migration Complete!</h2><table>";
    foreach($stats as $label=>$val) echo "<tr><td>$label</td><td>$val</td></tr>";
    echo "</table></div>";

    echo "<div style='background:#161b2e;border-radius:12px;padding:20px;margin-top:16px;border:1px solid #21283d;color:#eab308;font-size:.9rem'>";
    echo "<strong>Important:</strong> Copy your WoWonder media files to Sngine:<br>";
    echo "<code style='background:#0a0e1a;padding:4px 10px;border-radius:6px;color:#58a6ff;display:inline-block;margin-top:8px'>cp -r /wowonder/upload/* /sngine/content/uploads/</code>";
    echo "</div>";

    echo "<a href='javascript:history.back()' class='back'>← Run Again</a>";

    $wo->close();
    $sn->close();
}

// ============================================================
// HANDLE MIGRATION EXECUTION
// ============================================================
if (isset($_POST['run_migration'])) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Migration Running | ZeronKit</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Courier New',monospace;background:#0a0e1a;color:#c9d1d9;padding:30px 20px}
.c{max-width:850px;margin:0 auto}
.logo{text-align:center;margin-bottom:20px;font-size:1.4rem;font-weight:700;background:linear-gradient(135deg,#3b82f6,#8b5cf6);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.log{background:#161b2e;border-radius:12px;padding:24px;border:1px solid #21283d;font-size:.88rem;line-height:2;white-space:pre-wrap;min-height:200px;max-height:70vh;overflow-y:auto}
.ok{color:#22c55e}.err{color:#ef4444}.info{color:#3b82f6}.warn{color:#eab308}
.summary{background:#161b2e;border-radius:12px;padding:24px;margin-top:16px;border:1px solid #21283d}
.summary h2{color:#22c55e;margin-bottom:14px;font-size:1.1rem}
.summary table{width:100%;border-collapse:collapse}
.summary td{padding:7px 12px;border-bottom:1px solid #21283d;font-size:.9rem}
.summary td:last-child{text-align:right;color:#3b82f6;font-weight:700}
.back{display:inline-block;margin-top:16px;padding:10px 22px;background:#21283d;color:#c9d1d9;text-decoration:none;border-radius:8px;font-size:.9rem}
.back:hover{background:#2d3548}
.footer{text-align:center;margin-top:20px;font-size:.8rem;color:#4b5563}
.footer a{color:#3b82f6;text-decoration:none}
.warn-box{background:#431407;border:1px solid #9a3412;border-radius:10px;padding:16px;margin-top:16px;color:#fdba74;font-size:.88rem;line-height:1.6}
.warn-box code{background:#0a0e1a;padding:3px 8px;border-radius:5px;color:#3b82f6;font-size:.85em}
</style>
</head>
<body>
<div class="c">
<div class="logo">ZERONKIT</div>
<div class="log">
<?php
    ob_implicit_flush(true);
    if (ob_get_level()) ob_end_flush();

    // Users are required by every other step, so they always run (the checkbox cannot be unchecked).
    $steps = ['users'];
    $step_names = ['followers','pages','groups','posts','reactions','comments','messages','hashtags','blocks','saved_posts','events','products','blogs','pokes'];
    foreach ($step_names as $s) {
        if (isset($_POST['migrate_' . $s])) $steps[] = $s;
    }

    {
        // Validate inputs
        $required = ['wo_host','wo_port','wo_user','wo_db','sn_host','sn_port','sn_user','sn_db'];
        $missing = [];
        foreach ($required as $field) {
            if (empty($_POST[$field]) && $field !== 'wo_port' && $field !== 'sn_port') $missing[] = $field;
        }

        if (!empty($missing)) {
            echo "<span class='err'>Missing required fields: " . implode(', ', $missing) . "</span>\n";
        } else {
            $_WO_CONFIG = [
                'host' => $_POST['wo_host'], 'port' => (int)($_POST['wo_port'] ?: 3306),
                'user' => $_POST['wo_user'], 'pass' => $_POST['wo_pass'] ?? '', 'db' => $_POST['wo_db'],
            ];
            $_SN_CONFIG = [
                'host' => $_POST['sn_host'], 'port' => (int)($_POST['sn_port'] ?: 3306),
                'user' => $_POST['sn_user'], 'pass' => $_POST['sn_pass'] ?? '', 'db' => $_POST['sn_db'],
            ];
            zk_run_migration($steps, $_WO_CONFIG, $_SN_CONFIG);
        }
    }
?>
</div>
<div class="footer">Powered by <a href="<?= ZERONKIT_SITE ?>" target="_blank">ZeronKit</a> v<?= ZERONKIT_VERSION ?></div>
</div>
</body></html>
    <?php exit;
}

// ============================================================
// HANDLE CONNECTION TEST
// ============================================================
if (isset($_POST['test_connection'])) {
    // PHP 8.1+ throws on a failed connect or query by default, which broke the JSON reply.
    mysqli_report(MYSQLI_REPORT_OFF);
    header('Content-Type: application/json; charset=utf-8');

    $results = [];

    // Test WoWonder DB
    $wo_host = $_POST['wo_host'] ?? '';
    $wo_user = $_POST['wo_user'] ?? '';
    $wo_pass = $_POST['wo_pass'] ?? '';
    $wo_db = $_POST['wo_db'] ?? '';
    $wo_port = (int)($_POST['wo_port'] ?: 3306);

    if (empty($wo_host) || empty($wo_user) || empty($wo_db)) {
        $results['wowonder'] = ['status' => 'error', 'message' => 'Fill in all WoWonder database fields.'];
    } else {
        $wo = @new mysqli($wo_host, $wo_user, $wo_pass, $wo_db, $wo_port);
        if ($wo->connect_error) {
            $results['wowonder'] = ['status' => 'error', 'message' => $wo->connect_error];
        } else {
            $r = $wo->query("SELECT COUNT(*) as c FROM Wo_Users");
            if (!$r) {
                $results['wowonder'] = ['status' => 'error', 'message' => 'Connected but table "Wo_Users" not found. Is this a WoWonder database?'];
            } else {
                $results['wowonder'] = ['status' => 'ok', 'users' => $r->fetch_assoc()['c']];
            }
            $wo->close();
        }
    }

    // Test Sngine DB
    $sn_host = $_POST['sn_host'] ?? '';
    $sn_user = $_POST['sn_user'] ?? '';
    $sn_pass = $_POST['sn_pass'] ?? '';
    $sn_db = $_POST['sn_db'] ?? '';
    $sn_port = (int)($_POST['sn_port'] ?: 3306);

    if (empty($sn_host) || empty($sn_user) || empty($sn_db)) {
        $results['sngine'] = ['status' => 'error', 'message' => 'Fill in all Sngine database fields.'];
    } else {
        $sn = @new mysqli($sn_host, $sn_user, $sn_pass, $sn_db, $sn_port);
        if ($sn->connect_error) {
            $results['sngine'] = ['status' => 'error', 'message' => $sn->connect_error];
        } else {
            $r = $sn->query("SELECT COUNT(*) as c FROM users");
            if (!$r) {
                $results['sngine'] = ['status' => 'error', 'message' => 'Connected but table "users" not found. Complete Sngine installation first.'];
            } else {
                $results['sngine'] = ['status' => 'ok', 'users' => $r->fetch_assoc()['c']];
            }
            $sn->close();
        }
    }

    echo json_encode($results);
    exit;
}

// ============================================================
// MAIN FORM: Show the migration interface
// ============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WoWonder to Sngine Migration v<?= ZERONKIT_VERSION ?> | ZeronKit</title>
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
.badge-red{background:#7f1d1d;color:#fca5a5}
.badge-green{background:#14532d;color:#86efac}
.badge-blue{background:#1e3a5f;color:#93c5fd}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.field{display:flex;flex-direction:column;gap:5px}
.field.full{grid-column:1/-1}
.field label{font-size:.82rem;color:#9ca3af;font-weight:500}
.field input{background:#0a0e1a;border:1px solid #1f2937;border-radius:9px;padding:11px 14px;color:#e5e7eb;font-size:.92rem;transition:border .2s}
.field input:focus{outline:none;border-color:#3b82f6}
.checks{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.check{display:flex;align-items:center;gap:8px;padding:10px 14px;background:#0a0e1a;border-radius:9px;border:1px solid #1f2937;cursor:pointer;transition:border .2s;font-size:.88rem}
.check:hover{border-color:#3b82f6}
.check input[type=checkbox]{accent-color:#3b82f6;width:16px;height:16px}
.check .info{color:#6b7280;font-size:.75rem;margin-left:auto}
.btns{display:flex;gap:12px;margin-top:26px}
.btn{padding:13px 24px;border-radius:10px;border:none;font-size:.95rem;font-weight:600;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;font-family:inherit}
.btn-primary{background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;flex:1}
.btn-primary:hover{opacity:.9;transform:translateY(-1px)}
.btn-primary:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-secondary{background:#1f2937;color:#e5e7eb}
.btn-secondary:hover{background:#374151}
.btn-secondary:disabled{opacity:.5;cursor:not-allowed}
.arrow{text-align:center;font-size:1.6rem;color:#374151;margin:6px 0;line-height:1}
.alert{padding:14px 18px;border-radius:10px;margin-top:16px;font-size:.88rem;display:none;white-space:pre-line;line-height:1.6}
.alert-error{background:#450a0a;border:1px solid #991b1b;color:#fca5a5}
.alert-success{background:#052e16;border:1px solid #166534;color:#86efac}
.footer{text-align:center;margin-top:28px;font-size:.8rem;color:#4b5563}
.footer a{color:#3b82f6;text-decoration:none}
.key-section{display:flex;gap:12px;align-items:flex-end}
.key-section .field{flex:1}
.key-link{font-size:.82rem;color:#3b82f6;text-decoration:none;white-space:nowrap;padding-bottom:13px}
.key-link:hover{text-decoration:underline}
.sysinfo{background:#0a0e1a;border-radius:8px;padding:12px 16px;margin-top:14px;font-size:.8rem;color:#6b7280;line-height:1.8}
.sysinfo .ok{color:#22c55e}.sysinfo .fail{color:#ef4444}
.spinner{display:none;width:18px;height:18px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:600px){.grid,.checks{grid-template-columns:1fr}.key-section{flex-direction:column}}
</style>
</head>
<body>
<div class="container">
    <div class="logo">
        <h1>ZERONKIT</h1>
        <p>WoWonder → Sngine Migration Tool v<?= ZERONKIT_VERSION ?></p>
    </div>

    <form method="POST" id="migForm">
        <div class="sysinfo">
            System: PHP <?= PHP_VERSION ?> <span class="ok">✓</span> &nbsp;
            mysqli <span class="ok">✓</span>
        </div>

        <div class="card">
            <h2>WoWonder Database <span class="badge badge-red">Source</span></h2>
            <div class="grid">
                <div class="field"><label>Host</label><input type="text" name="wo_host" value="localhost" required></div>
                <div class="field"><label>Port</label><input type="number" name="wo_port" value="3306" required></div>
                <div class="field"><label>Username</label><input type="text" name="wo_user" placeholder="Database username" required></div>
                <div class="field"><label>Password</label><input type="text" name="wo_pass" placeholder="Database password"></div>
                <div class="field full"><label>Database Name</label><input type="text" name="wo_db" placeholder="WoWonder database name" required></div>
            </div>
        </div>

        <div class="arrow">↓</div>

        <div class="card">
            <h2>Sngine Database <span class="badge badge-green">Target</span></h2>
            <div class="grid">
                <div class="field"><label>Host</label><input type="text" name="sn_host" value="localhost" required></div>
                <div class="field"><label>Port</label><input type="number" name="sn_port" value="3306" required></div>
                <div class="field"><label>Username</label><input type="text" name="sn_user" placeholder="Database username" required></div>
                <div class="field"><label>Password</label><input type="text" name="sn_pass" placeholder="Database password"></div>
                <div class="field full"><label>Database Name</label><input type="text" name="sn_db" placeholder="Sngine database name" required></div>
            </div>
        </div>

        <div class="card">
            <h2>What to Migrate <span class="badge badge-blue">Select</span></h2>
            <div class="checks">
                <label class="check"><input type="checkbox" checked disabled> Users & Passwords <span class="info">Required</span><input type="hidden" name="migrate_users" value="on"></label>
                <label class="check"><input type="checkbox" name="migrate_followers" checked> Followers & Friends</label>
                <label class="check"><input type="checkbox" name="migrate_posts" checked> Posts & Media</label>
                <label class="check"><input type="checkbox" name="migrate_comments" checked> Comments & Replies</label>
                <label class="check"><input type="checkbox" name="migrate_reactions" checked> Reactions & Likes</label>
                <label class="check"><input type="checkbox" name="migrate_pages" checked> Pages & Page Likes</label>
                <label class="check"><input type="checkbox" name="migrate_groups" checked> Groups & Members</label>
                <label class="check"><input type="checkbox" name="migrate_messages"> Messages <span class="info">Slow</span></label>
                <label class="check"><input type="checkbox" name="migrate_hashtags" checked> Hashtags</label>
                <label class="check"><input type="checkbox" name="migrate_events" checked> Events</label>
                <label class="check"><input type="checkbox" name="migrate_products" checked> Products</label>
                <label class="check"><input type="checkbox" name="migrate_blogs" checked> Blog Articles</label>
                <label class="check"><input type="checkbox" name="migrate_saved_posts" checked> Saved Posts</label>
                <label class="check"><input type="checkbox" name="migrate_blocks" checked> Blocks</label>
                <label class="check"><input type="checkbox" name="migrate_pokes" checked> Pokes</label>
            </div>
        </div>

        <div class="alert alert-error" id="alertError"></div>
        <div class="alert alert-success" id="alertSuccess"></div>

        <div class="btns">
            <button type="button" class="btn btn-secondary" id="btnTest" onclick="testConnection()">
                <div class="spinner" id="spinTest"></div> Test Connection
            </button>
            <button type="submit" name="run_migration" class="btn btn-primary" onclick="return confirmMigration()">
                Start Migration
            </button>
        </div>
    </form>
    <div class="footer"><a href="<?= ZERONKIT_SITE ?>" target="_blank">zeronkit.com</a>: Free open-source migration tools | v<?= ZERONKIT_VERSION ?></div>
</div>
<script>
function hideAlerts(){document.getElementById('alertError').style.display='none';document.getElementById('alertSuccess').style.display='none'}
function showError(m){hideAlerts();var e=document.getElementById('alertError');e.textContent=m;e.style.display='block'}
function showSuccess(m){hideAlerts();var e=document.getElementById('alertSuccess');e.textContent=m;e.style.display='block'}
function testConnection(){
    hideAlerts();
    var btn=document.getElementById('btnTest'),spin=document.getElementById('spinTest');
    btn.disabled=true;spin.style.display='block';
    var fd=new FormData(document.getElementById('migForm'));fd.append('test_connection','1');
    fetch(window.location.href,{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(d){
        btn.disabled=false;spin.style.display='none';
        var m='';
        if(d.wowonder)m+=d.wowonder.status==='ok'?'WoWonder: Connected ('+d.wowonder.users+' users)\n':'WoWonder: FAILED - '+d.wowonder.message+'\n';
        if(d.sngine)m+=d.sngine.status==='ok'?'Sngine: Connected ('+d.sngine.users+' users)':'Sngine: FAILED - '+d.sngine.message;
        d.wowonder&&d.wowonder.status==='ok'&&d.sngine&&d.sngine.status==='ok'?showSuccess(m):showError(m);
    }).catch(function(e){btn.disabled=false;spin.style.display='none';showError('Connection error: '+e.message)});
}
function confirmMigration(){
    var checks=document.querySelectorAll('.check input[type=checkbox]:checked');
    if(checks.length===0){showError('Select at least one data type to migrate.');return false}
    return confirm('This will overwrite existing data in your Sngine database.\n\nMake sure you have a backup!\n\nContinue?');
}
</script>
</body>
</html>
