<?php
/**
 * ZeronKit: WoWonder to Sngine Migration Tool
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

define('ZERONKIT_VERSION', '2.0');
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

    $wo = new mysqli($_WO_CONFIG['host'], $_WO_CONFIG['user'], $_WO_CONFIG['pass'], $_WO_CONFIG['db'], $_WO_CONFIG['port']);
    if ($wo->connect_error) { zk_out("WoWonder DB failed: " . $wo->connect_error, 'err'); return; }
    $wo->set_charset('utf8mb4');

    $sn = new mysqli($_SN_CONFIG['host'], $_SN_CONFIG['user'], $_SN_CONFIG['pass'], $_SN_CONFIG['db'], $_SN_CONFIG['port']);
    if ($sn->connect_error) { zk_out("Sngine DB failed: " . $sn->connect_error, 'err'); return; }
    $sn->set_charset('utf8mb4');

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
    $r = $wo->query("SELECT id, tag FROM Wo_Hashtags");
    while ($row = $r->fetch_assoc()) $hashtags[$row['id']] = $row['tag'];

    $usernames = [];
    $r = $wo->query("SELECT user_id, username FROM Wo_Users");
    while ($row = $r->fetch_assoc()) $usernames[$row['user_id']] = $row['username'];
    zk_out("  Loaded " . count($hashtags) . " hashtags, " . count($usernames) . " usernames", 'ok');
    zk_out("");

    // ====== USERS ======
    if (in_array('users', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Users...", 'info');
        $sn->query("DELETE FROM users WHERE user_id > 1");
        $result = $wo->query("SELECT * FROM Wo_Users ORDER BY user_id ASC");
        $c = 0;
        while ($u = $result->fetch_assoc()) {
            $admin = ($u['admin'] == '1') ? 1 : 3;
            $gender = 'NULL';
            if ($u['gender'] == 'male') $gender = 1; elseif ($u['gender'] == 'female') $gender = 2;
            $country = ((int)$u['country_id'] > 0) ? (int)$u['country_id'] : 'NULL';
            $bd = $u['birthday'] ?? '';
            $bday = ($bd && $bd != '0000-00-00' && $bd != '0') ? "'" . zk_esc($sn, $bd) . "'" : 'NULL';
            $time = zk_ts($u['joined'] ?? 0);
            $active = ($u['active'] == '1') ? '1' : '0';
            // Fix avatar/cover paths
            $avatar = zk_fix_path($u['avatar'] ?? '');
            $cover = zk_fix_path($u['cover'] ?? '');

            $sql = "INSERT INTO users (user_id,user_group,user_name,user_email,user_password,user_firstname,user_lastname,user_gender,user_country,user_birthdate,user_picture,user_cover,user_biography,user_website,user_verified,user_activated,user_approved,user_email_verified,user_registered,user_last_seen) VALUES ({$u['user_id']},$admin,'" . zk_esc($sn,$u['username']) . "','" . zk_esc($sn,$u['email']) . "','" . zk_esc($sn,$u['password']) . "','" . zk_esc($sn,$u['first_name']?:$u['username']) . "','" . zk_esc($sn,$u['last_name']) . "',$gender,$country,$bday,'" . zk_esc($sn,$avatar) . "','" . zk_esc($sn,$cover) . "','" . zk_esc($sn,$u['about']) . "','" . zk_esc($sn,$u['website']) . "','" . (($u['verified']=='1')?'1':'0') . "','$active','1','$active','$time','$time') ON DUPLICATE KEY UPDATE user_name=VALUES(user_name)";
            if ($sn->query($sql)) $c++;
        }
        $stats['Users'] = $c;
        zk_out("  $c users migrated", 'ok');
    }

    // ====== FOLLOWERS ======
    if (in_array('followers', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Followers...", 'info');
        $sn->query("TRUNCATE TABLE followings"); $sn->query("TRUNCATE TABLE friends");
        $result = $wo->query("SELECT * FROM Wo_Followers WHERE active = 1");
        $cf=0;$cfr=0;$pairs=[];
        while ($f = $result->fetch_assoc()) {
            $a=(int)$f['follower_id'];$b=(int)$f['following_id'];
            $sn->query("INSERT IGNORE INTO followings (user_id,following_id,time) VALUES ($a,$b,'".zk_ts($f['time'])."')");$cf++;
            $k=min($a,$b).'-'.max($a,$b);
            if(isset($pairs[$k])){$sn->query("INSERT IGNORE INTO friends (user_one_id,user_two_id,status) VALUES (".min($a,$b).",".max($a,$b).",1)");$cfr++;}
            else $pairs[$k]=true;
        }
        $stats['Followings']=$cf;$stats['Friends']=$cfr;
        zk_out("  $cf followings, $cfr friendships",'ok');
    }

    // ====== PAGES ======
    if (in_array('pages', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Pages...", 'info');
        $sn->query("DELETE FROM pages WHERE page_id > 0"); $sn->query("TRUNCATE TABLE pages_likes");
        $result = $wo->query("SELECT * FROM Wo_Pages ORDER BY page_id ASC");
        $c=0;
        while ($p = $result->fetch_assoc()) {
            $avatar = zk_fix_path($p['avatar'] ?? '');
            $cover = zk_fix_path($p['cover'] ?? '');
            if($sn->query("INSERT INTO pages (page_id,page_admin,page_category,page_name,page_title,page_description,page_picture,page_cover,page_verified,page_website,page_phone,page_company,page_location,page_country,page_language,page_date) VALUES ({$p['page_id']},{$p['user_id']},1,'".zk_esc($sn,$p['page_name'])."','".zk_esc($sn,$p['page_title'])."','".zk_esc($sn,$p['page_description'])."','".zk_esc($sn,$avatar)."','".zk_esc($sn,$cover)."','".(($p['verified']??'0')=='1'?'1':'0')."','".zk_esc($sn,$p['website'])."','".zk_esc($sn,$p['phone'])."','".zk_esc($sn,$p['company'])."','".zk_esc($sn,$p['address'])."',0,0,'".zk_ts($p['time']??0)."')"))$c++;
        }
        $result=$wo->query("SELECT * FROM Wo_Pages_Likes");$cl=0;
        while($pl=$result->fetch_assoc()){$sn->query("INSERT IGNORE INTO pages_likes (page_id,user_id) VALUES ({$pl['page_id']},{$pl['user_id']})");$cl++;}
        $stats['Pages']=$c;$stats['Page Likes']=$cl;
        zk_out("  $c pages, $cl likes",'ok');
    }

    // ====== GROUPS ======
    if (in_array('groups', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Groups...", 'info');
        $sn->query("DELETE FROM `groups` WHERE group_id > 0"); $sn->query("TRUNCATE TABLE groups_members");
        $result=$wo->query("SELECT * FROM Wo_Groups ORDER BY id ASC");$c=0;
        while($g=$result->fetch_assoc()){
            $pr=($g['privacy']=='2')?'closed':'public';
            $avatar = zk_fix_path($g['avatar'] ?? '');
            $cover = zk_fix_path($g['cover'] ?? '');
            if($sn->query("INSERT INTO `groups` (group_id,group_admin,group_category,group_name,group_title,group_description,group_privacy,group_picture,group_cover,group_country,group_language,group_date) VALUES ({$g['id']},{$g['user_id']},1,'".zk_esc($sn,$g['group_name'])."','".zk_esc($sn,$g['group_title'])."','".zk_esc($sn,$g['about'])."','$pr','".zk_esc($sn,$avatar)."','".zk_esc($sn,$cover)."',0,0,'".zk_ts($g['time'])."')"))$c++;
        }
        $result=$wo->query("SELECT * FROM Wo_Group_Members WHERE active='1'");$cm=0;
        while($gm=$result->fetch_assoc()){$sn->query("INSERT IGNORE INTO groups_members (group_id,user_id,approved) VALUES ({$gm['group_id']},{$gm['user_id']},'1')");$cm++;}
        $stats['Groups']=$c;$stats['Group Members']=$cm;
        zk_out("  $c groups, $cm members",'ok');
    }

    // ====== POSTS ======
    if (in_array('posts', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Posts...", 'info');
        $sn->query("TRUNCATE TABLE posts");$sn->query("TRUNCATE TABLE posts_photos");$sn->query("TRUNCATE TABLE posts_videos");
        $sn->query("TRUNCATE TABLE posts_products");$sn->query("TRUNCATE TABLE posts_articles");
        $sn->query("TRUNCATE TABLE hashtags_posts");
        $result=$wo->query("SELECT * FROM Wo_Posts ORDER BY id ASC");$c=0;
        while($p=$result->fetch_assoc()){
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
            $ig=($p['group_id']>0)?'1':'0';$gid=($p['group_id']>0)?$p['group_id']:'NULL';
            $ut=($p['page_id']>0)?'page':'user';$uid=($p['page_id']>0)?$p['page_id']:(int)$p['user_id'];
            $oid=($p['postShare']>0)?$p['postShare']:'NULL';
            $loc=!empty($p['postMap'])?"'".zk_esc($sn,$p['postMap'])."'":'NULL';
            $fa='NULL';$fv='NULL';
            foreach(['postFeeling'=>'feeling','postListening'=>'listening','postTraveling'=>'traveling','postWatching'=>'watching','postPlaying'=>'playing'] as $col=>$act){
                if(!empty($p[$col])){$fa="'$act'";$fv="'".zk_esc($sn,$p[$col])."'";break;}
            }
            if($sn->query("INSERT INTO posts (post_id,user_id,user_type,post_type,in_group,group_id,time,privacy,text,feeling_action,feeling_value,location,origin_id,comments,shares,views) VALUES ({$p['id']},$uid,'$ut','$pt','$ig',$gid,'$time','$pv','$txt',$fa,$fv,$loc,$oid,0,0,{$p['videoViews']})")){
                $c++;

                // Insert media from postFile
                if (!empty($file)) {
                    $fixed_file = zk_fix_path($file);
                    $fixed_thumb = zk_fix_path($p['postFileThumb'] ?? '');
                    if ($pt == 'photos') {
                        $sn->query("INSERT INTO posts_photos (post_id,source) VALUES ({$p['id']},'".zk_esc($sn,$fixed_file)."')");
                    } elseif ($pt == 'video') {
                        $sn->query("INSERT INTO posts_videos (post_id,category_id,source,thumbnail,views) VALUES ({$p['id']},0,'".zk_esc($sn,$fixed_file)."','".zk_esc($sn,$fixed_thumb)."',{$p['videoViews']})");
                    }
                }

                // Insert hashtag-post links
                preg_match_all('/#\[(\d+)\]/', $raw_text, $hmatches);
                if (!empty($hmatches[1])) {
                    foreach (array_unique($hmatches[1]) as $hid) {
                        if (isset($hashtags[$hid])) {
                            $sn->query("INSERT IGNORE INTO hashtags_posts (hashtag_id,post_id) VALUES ($hid,{$p['id']})");
                        }
                    }
                }
            }
        }

        // Insert album media (multi-image posts)
        $result = $wo->query("SELECT * FROM Wo_Albums_Media WHERE image != '' ORDER BY id ASC");
        $album_c = 0;
        while ($am = $result->fetch_assoc()) {
            $post_id = (int)$am['post_id'];
            $check = $sn->query("SELECT post_id FROM posts WHERE post_id = $post_id");
            if ($check && $check->num_rows > 0) {
                $fixed_img = zk_fix_path($am['image']);
                $sn->query("INSERT INTO posts_photos (post_id,source) VALUES ($post_id,'".zk_esc($sn,$fixed_img)."')");
                $sn->query("UPDATE posts SET post_type='photos' WHERE post_id=$post_id");
                $album_c++;
            }
        }

        $stats['Posts']=$c;
        $stats['Photos']=$sn->query("SELECT COUNT(*) as c FROM posts_photos")->fetch_assoc()['c'];
        $stats['Videos']=$sn->query("SELECT COUNT(*) as c FROM posts_videos")->fetch_assoc()['c'];
        zk_out("  $c posts, {$stats['Photos']} photos, {$stats['Videos']} videos, $album_c album items",'ok');
    }

    // ====== REACTIONS ======
    if (in_array('reactions', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Reactions (may take a while)...", 'info');
        $sn->query("TRUNCATE TABLE posts_reactions");$sn->query("TRUNCATE TABLE posts_comments_reactions");
        $rm=['1'=>'like','2'=>'love','3'=>'haha','4'=>'yay','5'=>'wow','6'=>'sad','7'=>'angry','like'=>'like','love'=>'love','haha'=>'haha','wow'=>'wow','sad'=>'sad','angry'=>'angry','yay'=>'yay'];
        $result=$wo->query("SELECT * FROM Wo_Reactions WHERE post_id > 0");$cr=0;$rc=[];
        while($r=$result->fetch_assoc()){
            $pid=(int)$r['post_id'];$m=$rm[strtolower($r['reaction']??'like')]??'like';
            if($sn->query("INSERT IGNORE INTO posts_reactions (post_id,user_id,reaction,reaction_time) VALUES ($pid,{$r['user_id']},'$m',NOW())"))
            {$cr++;$rc[$pid][$m]=($rc[$pid][$m]??0)+1;}
        }
        foreach($rc as $pid=>$rr){$s=[];foreach(['like','love','haha','yay','wow','sad','angry'] as $r)$s[]="reaction_{$r}_count=".($rr[$r]??0);$sn->query("UPDATE posts SET ".implode(',',$s)." WHERE post_id=$pid");}
        $stats['Reactions']=$cr;
        $result=$wo->query("SELECT * FROM Wo_Reactions WHERE comment_id > 0");$ccr=0;
        while($r=$result->fetch_assoc()){$m=$rm[strtolower($r['reaction']??'like')]??'like';if($sn->query("INSERT IGNORE INTO posts_comments_reactions (comment_id,user_id,reaction,reaction_time) VALUES ({$r['comment_id']},{$r['user_id']},'$m',NOW())"))$ccr++;}
        $stats['Comment Reactions']=$ccr;
        zk_out("  $cr post reactions, $ccr comment reactions",'ok');
    }

    // ====== COMMENTS ======
    if (in_array('comments', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Comments...", 'info');
        $sn->query("DELETE FROM posts_comments WHERE comment_id > 0");
        $result=$wo->query("SELECT * FROM Wo_Comments ORDER BY id ASC");$cc=0;
        while($c=$result->fetch_assoc()){
            $fixed_text = zk_fix_text($c['text'] ?? '', $hashtags, $usernames);
            $fixed_img = zk_fix_path($c['c_file'] ?? '');
            if($sn->query("INSERT INTO posts_comments (comment_id,node_id,node_type,user_id,user_type,text,image,time) VALUES ({$c['id']},{$c['post_id']},'post',{$c['user_id']},'user','".zk_esc($sn,$fixed_text)."','".zk_esc($sn,$fixed_img)."','".zk_ts($c['time'])."')"))$cc++;
        }
        $result=$wo->query("SELECT * FROM Wo_Comment_Replies ORDER BY id ASC");$cr=0;
        while($r=$result->fetch_assoc()){
            $fixed_text = zk_fix_text($r['text'] ?? '', $hashtags, $usernames);
            $fixed_img = zk_fix_path($r['c_file'] ?? '');
            if($sn->query("INSERT INTO posts_comments (node_id,node_type,user_id,user_type,text,image,time) VALUES ({$r['comment_id']},'comment',{$r['user_id']},'user','".zk_esc($sn,$fixed_text)."','".zk_esc($sn,$fixed_img)."','".zk_ts($r['time'])."')"))$cr++;
        }
        $sn->query("UPDATE posts p SET comments=(SELECT COUNT(*) FROM posts_comments c WHERE c.node_id=p.post_id AND c.node_type='post')");
        $stats['Comments']=$cc;$stats['Replies']=$cr;
        zk_out("  $cc comments, $cr replies",'ok');
    }

    // ====== MESSAGES ======
    if (in_array('messages', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Messages...", 'info');
        $sn->query("TRUNCATE TABLE conversations");$sn->query("TRUNCATE TABLE conversations_messages");$sn->query("TRUNCATE TABLE conversations_users");
        $result=$wo->query("SELECT DISTINCT LEAST(from_id,to_id) as u1, GREATEST(from_id,to_id) as u2 FROM Wo_Messages WHERE to_id>0 AND from_id>0 AND group_id=0 AND page_id=0");
        $cmap=[];$cid=0;
        while($pair=$result->fetch_assoc()){$cid++;$cmap[$pair['u1'].'-'.$pair['u2']]=$cid;$sn->query("INSERT INTO conversations (conversation_id,last_message_id) VALUES ($cid,0)");$sn->query("INSERT INTO conversations_users (conversation_id,user_id,seen) VALUES ($cid,{$pair['u1']},'1')");$sn->query("INSERT INTO conversations_users (conversation_id,user_id,seen) VALUES ($cid,{$pair['u2']},'1')");}
        $result=$wo->query("SELECT * FROM Wo_Messages WHERE to_id>0 AND from_id>0 AND group_id=0 AND page_id=0 ORDER BY id ASC");
        $mc=0;$last=[];
        while($m=$result->fetch_assoc()){
            $k=min($m['from_id'],$m['to_id']).'-'.max($m['from_id'],$m['to_id']);
            if(!isset($cmap[$k]))continue;$cv=$cmap[$k];
            $fixed_text = zk_fix_text($m['text'] ?? '', $hashtags, $usernames);
            $fixed_media = zk_fix_path($m['media'] ?? '');
            if($sn->query("INSERT INTO conversations_messages (conversation_id,user_id,message,image,time) VALUES ($cv,{$m['from_id']},'".zk_esc($sn,$fixed_text)."','".zk_esc($sn,$fixed_media)."','".zk_ts($m['time'])."')"))
            {$mc++;$last[$cv]=$sn->insert_id;}
        }
        foreach($last as $cv=>$lid)$sn->query("UPDATE conversations SET last_message_id=$lid WHERE conversation_id=$cv");
        $stats['Conversations']=$cid;$stats['Messages']=$mc;
        zk_out("  $cid conversations, $mc messages",'ok');
    }

    // ====== HASHTAGS ======
    if (in_array('hashtags', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Hashtags...", 'info');
        $sn->query("TRUNCATE TABLE hashtags");
        $result=$wo->query("SELECT * FROM Wo_Hashtags ORDER BY id ASC");$c=0;
        while($h=$result->fetch_assoc()){if($sn->query("INSERT IGNORE INTO hashtags (hashtag_id,hashtag) VALUES ({$h['id']},'".zk_esc($sn,$h['tag'])."')"))$c++;}
        $stats['Hashtags']=$c;
        zk_out("  $c hashtags",'ok');
    }

    // ====== BLOCKS ======
    if (in_array('blocks', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Blocks...", 'info');
        $sn->query("TRUNCATE TABLE users_blocks");
        $result=$wo->query("SELECT * FROM Wo_Blocks");$c=0;
        while($b=$result->fetch_assoc()){if($sn->query("INSERT IGNORE INTO users_blocks (user_id,blocked_id) VALUES ({$b['blocker']},{$b['blocked']})"))$c++;}
        $stats['Blocks']=$c;
        zk_out("  $c blocks",'ok');
    }

    // ====== SAVED POSTS ======
    if (in_array('saved_posts', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Saved Posts...", 'info');
        $sn->query("TRUNCATE TABLE posts_saved");
        $result=$wo->query("SELECT * FROM Wo_SavedPosts");$c=0;
        while($s=$result->fetch_assoc()){if($sn->query("INSERT IGNORE INTO posts_saved (post_id,user_id,time) VALUES ({$s['post_id']},{$s['user_id']},'".zk_ts($s['time']??0)."')"))$c++;}
        $stats['Saved Posts']=$c;
        zk_out("  $c saved posts",'ok');
    }

    // ====== EVENTS ======
    if (in_array('events', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Events...", 'info');
        $sn->query("DELETE FROM events WHERE event_id > 0");
        $result=$wo->query("SELECT * FROM Wo_Events ORDER BY id ASC");$c=0;
        while($e=$result->fetch_assoc()){
            $sd=($e['start_date']&&$e['start_date']!='0000-00-00')?$e['start_date']:date('Y-m-d');
            $ed=($e['end_date']&&$e['end_date']!='0000-00-00')?$e['end_date']:date('Y-m-d');
            $st=(!empty($e['start_time'])&&$e['start_time']!='00:00:00')?$e['start_time']:'12:00:00';
            $et=(!empty($e['end_time'])&&$e['end_time']!='00:00:00')?$e['end_time']:'23:59:59';
            $cover = zk_fix_path($e['cover'] ?? '');
            if($sn->query("INSERT INTO events (event_id,event_admin,event_category,event_title,event_description,event_location,event_start_date,event_end_date,event_cover,event_country,event_language,event_date) VALUES ({$e['id']},".(int)($e['poster_id']??0).",1,'".zk_esc($sn,$e['name'])."','".zk_esc($sn,$e['description'])."','".zk_esc($sn,$e['location'])."','".zk_esc($sn,"$sd $st")."','".zk_esc($sn,"$ed $et")."','".zk_esc($sn,$cover)."',0,0,NOW())"))$c++;
        }
        $stats['Events']=$c;
        zk_out("  $c events",'ok');
    }

    // ====== PRODUCTS ======
    if (in_array('products', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Products...", 'info');
        $result=$wo->query("SELECT * FROM Wo_Products ORDER BY id ASC");$c=0;
        while($pr=$result->fetch_assoc()){
            $uid=(int)$pr['user_id'];$n=zk_esc($sn,$pr['name']??'');$d=zk_esc($sn,$pr['description']??'');
            if($sn->query("INSERT INTO posts (user_id,user_type,post_type,text,time,privacy) VALUES ($uid,'user','product','$n - $d','".zk_ts($pr['time']??0)."','public')")){
                $pid=$sn->insert_id;$sn->query("INSERT INTO posts_products (post_id,name,category_id,status,price,location) VALUES ($pid,'$n',0,'new',".(float)($pr['price']??0).",'".zk_esc($sn,$pr['location']??'')."')");$c++;
            }
        }
        $stats['Products']=$c;
        zk_out("  $c products",'ok');
    }

    // ====== BLOGS ======
    if (in_array('blogs', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Blogs...", 'info');
        $result=$wo->query("SELECT * FROM Wo_Blog ORDER BY id ASC");$c=0;
        while($b=$result->fetch_assoc()){
            $uid=(int)($b['user']??0);if($uid<=0)continue;
            $thumb = zk_fix_path($b['thumbnail'] ?? '');
            if($sn->query("INSERT INTO posts (user_id,user_type,post_type,text,time,privacy) VALUES ($uid,'user','article','".zk_esc($sn,$b['title']??'')."','".zk_ts($b['posted']??0)."','public')")){
                $pid=$sn->insert_id;$sn->query("INSERT INTO posts_articles (post_id,cover,title,text,category_id) VALUES ($pid,'".zk_esc($sn,$thumb)."','".zk_esc($sn,$b['title']??'')."','".zk_esc($sn,$b['content']??'')."',0)");$c++;
            }
        }
        $stats['Blogs']=$c;
        zk_out("  $c blogs",'ok');
    }

    // ====== POKES ======
    if (in_array('pokes', $_MIGRATION_STEPS)) {
        $step_num++;
        zk_out("[$step_num/$total_steps] Migrating Pokes...", 'info');
        $sn->query("TRUNCATE TABLE users_pokes");
        $result=$wo->query("SELECT * FROM Wo_Pokes");$c=0;
        while($pk=$result->fetch_assoc()){$s=(int)($pk['send_user_id']??0);$r=(int)($pk['received_user_id']??0);if($s>0&&$r>0){$sn->query("INSERT IGNORE INTO users_pokes (user_id,poked_id) VALUES ($s,$r)");$c++;}}
        $stats['Pokes']=$c;
        zk_out("  $c pokes",'ok');
    }

    // ====== UPDATE COUNTERS ======
    $step_num++;
    zk_out("\n[$step_num/$total_steps] Updating counters & final cleanup...", 'info');
    $sn->query("UPDATE pages p SET page_likes=(SELECT COUNT(*) FROM pages_likes pl WHERE pl.page_id=p.page_id)");
    $sn->query("UPDATE `groups` g SET group_members=(SELECT COUNT(*) FROM groups_members gm WHERE gm.group_id=g.group_id)");
    $sn->query("UPDATE posts_comments c INNER JOIN (SELECT node_id,COUNT(*) as cnt FROM posts_comments WHERE node_type='comment' GROUP BY node_id) r ON c.comment_id=r.node_id SET c.replies=r.cnt");
    $sn->query("SET FOREIGN_KEY_CHECKS = 1");
    zk_out("  Done", 'ok');

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

    $steps = [];
    $step_names = ['users','followers','pages','groups','posts','reactions','comments','messages','hashtags','blocks','saved_posts','events','products','blogs','pokes'];
    foreach ($step_names as $s) {
        if (isset($_POST['migrate_' . $s])) $steps[] = $s;
    }

    if (empty($steps)) {
        echo "<span class='err'>No data types selected. Go back and select what to migrate.</span>\n";
    } else {
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
<title>WoWonder to Sngine Migration | ZeronKit</title>
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
                <label class="check"><input type="checkbox" name="migrate_users" checked> Users & Passwords <span class="info">Required</span></label>
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
    <div class="footer"><a href="<?= ZERONKIT_SITE ?>" target="_blank">zeronkit.com</a>: Free open-source migration tools</div>
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
