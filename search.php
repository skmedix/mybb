<?php
/**
 * MyBB 1.8
 * Copyright 2014 MyBB Group, All Rights Reserved
 *
 * Website: http://www.mybb.com
 * License: http://www.mybb.com/about/license
 *
 */

define("IN_MYBB", 1);
define("IGNORE_CLEAN_VARS", "sid");
define('THIS_SCRIPT', 'search.php');

$templatelist = "search,forumdisplay_thread_gotounread,search_results_threads_thread,search_results_threads,search_results_posts,search_results_posts_post,search_results_icon,search_forumlist_forum,search_forumlist";
$templatelist .= ",multipage,multipage_breadcrumb,multipage_end,multipage_jump_page,multipage_nextpage,multipage_page,multipage_page_current,multipage_page_link_current,multipage_prevpage,multipage_start";
$templatelist .= ",search_results_posts_inlinecheck,search_results_posts_nocheck,search_results_threads_inlinecheck,search_results_threads_nocheck,search_results_inlinemodcol,search_results_inlinemodcol_empty,search_results_posts_inlinemoderation_custom_tool";
$templatelist .= ",search_results_posts_inlinemoderation_custom,search_results_posts_inlinemoderation,search_results_threads_inlinemoderation_custom_tool,search_results_threads_inlinemoderation_custom,search_results_threads_inlinemoderation";
$templatelist .= ",forumdisplay_thread_attachment_count,search_threads_inlinemoderation_selectall,search_posts_inlinemoderation_selectall,post_prefixselect_prefix,post_prefixselect_multiple,search_orderarrow";
$templatelist .= ",search_results_posts_forumlink,search_results_threads_forumlink,forumdisplay_thread_multipage_more,forumdisplay_thread_multipage_page,forumdisplay_thread_multipage,search_moderator_options";

require_once "./global.php";
require_once MYBB_ROOT."inc/functions_post.php";
require_once MYBB_ROOT."inc/functions_search.php";
require_once MYBB_ROOT."inc/class_parser.php";
$parser = new postParser;

// Load global language phrases
$lang->load("search");

add_breadcrumb($lang->nav_search, "search.php");

$mybb->input['action'] = $mybb->get_input('action');
switch($mybb->input['action'])
{
	case "results":
		add_breadcrumb($lang->nav_results);
		break;
	default:
		break;
}

if($mybb->usergroup['cansearch'] == 0)
{
	error_no_permission();
}

$now = TIME_NOW;
$mybb->input['keywords'] = trim($mybb->get_input('keywords'));

$limitsql = "";
if((int)$mybb->settings['searchhardlimit'] > 0)
{
	$limitsql = "LIMIT ".(int)$mybb->settings['searchhardlimit'];
}

if($mybb->input['action'] == "results")
{
	$sid = $db->escape_string($mybb->get_input('sid'));
	$query = $db->simple_select("searchlog", "*", "sid='$sid'");
	$search = $db->fetch_array($query);

	if(!$search['sid'])
	{
		error($lang->error_invalidsearch);
	}

	$plugins->run_hooks("search_results_start");

	// Decide on our sorting fields and sorting order.
	$order = my_strtolower(htmlspecialchars_uni($mybb->get_input('order')));
	$sortby = my_strtolower(htmlspecialchars_uni($mybb->get_input('sortby')));

	switch($sortby)
	{
		case "replies":
			$sortfield = "t.replies";
			break;
		case "views":
			$sortfield = "t.views";
			break;
		case "subject":
			if($search['resulttype'] == "threads")
			{
				$sortfield = "t.subject";
			}
			else
			{
				$sortfield = "p.subject";
			}
			break;
		case "forum":
			$sortfield = "f.name";
			break;
		case "starter":
			if($search['resulttype'] == "threads")
			{
				$sortfield = "t.username";
			}
			else
			{
				$sortfield = "p.username";
			}
			break;
		case "lastpost":
		default:
			if($search['resulttype'] == "threads")
			{
				$sortfield = "t.lastpost";
				$sortby = "lastpost";
			}
			else
			{
				$sortfield = "p.dateline";
				$sortby = "dateline";
			}
			break;
	}

    if ($order != "asc") {
        $order = "desc";
        $results['oppsortnext'] = "asc";
        $results['oppsort'] = $lang->asc;
    } else {
        $results['oppsortnext'] = "desc";
        $results['oppsort'] = $lang->desc;
    }

	if(!$mybb->settings['threadsperpage'] || (int)$mybb->settings['threadsperpage'] < 1)
	{
		$mybb->settings['threadsperpage'] = 20;
	}

	// Work out pagination, which page we're at, as well as the limits.
	$perpage = $mybb->settings['threadsperpage'];
	$page = $mybb->get_input('page');
	if($page > 0)
	{
		$start = ($page-1) * $perpage;
	}
	else
	{
		$start = 0;
		$page = 1;
	}
	$end = $start + $perpage;
	$lower = $start+1;
	$upper = $end;

	// Work out if we have terms to highlight
	$highlight = "";
	if($search['keywords'])
	{
		if($mybb->seo_support == true)
		{
			$highlight = "?highlight=".urlencode($search['keywords']);
		}
		else
		{
			$highlight = "&amp;highlight=".urlencode($search['keywords']);
		}
	}

    $results['orderarrow'] = array('replies' => '', 'views' => '', 'subject' => '', 'forum' => '', 'starter' => '', 'lastpost' => '', 'dateline' => '');
    $results['orderarrow'][$sortby] = true;
	$results['sortby'] = $sortby;
    $results['sorturl'] = "search.php?action=results&amp;sid={$sid}";

	// Read some caches we will be using
	$forumcache = $cache->read("forums");
	$icon_cache = $cache->read("posticons");

	$threads = array();

	if($mybb->user['uid'] == 0)
	{
		// Build a forum cache.
		$query = $db->query("
			SELECT fid
			FROM ".TABLE_PREFIX."forums
			WHERE active != 0
			ORDER BY pid, disporder
		");

		$forumsread = my_unserialize($mybb->cookies['mybb']['forumread']);
	}
	else
	{
		// Build a forum cache.
		$query = $db->query("
			SELECT f.fid, fr.dateline AS lastread
			FROM ".TABLE_PREFIX."forums f
			LEFT JOIN ".TABLE_PREFIX."forumsread fr ON (fr.fid=f.fid AND fr.uid='{$mybb->user['uid']}')
			WHERE f.active != 0
			ORDER BY pid, disporder
		");
	}

	while($forum = $db->fetch_array($query))
	{
		if($mybb->user['uid'] == 0)
		{
			if($forumsread[$forum['fid']])
			{
				$forum['lastread'] = $forumsread[$forum['fid']];
			}
		}
		$readforums[$forum['fid']] = $forum['lastread'];
	}
	$fpermissions = forum_permissions();

	// Inline Mod Column for moderators
	$inlinemodcol = $inlinecookie = '';
	$is_mod = $is_supermod = $results['show_inline_moderation'] = false;
	if($mybb->usergroup['issupermod'])
	{
		$is_supermod = true;
	}
	if($is_supermod || is_moderator())
	{
		$inlinecookie = "inlinemod_search".$sid;
		$results['inlinecount'] = 0;
		$is_mod = true;
		$results['return_url'] = 'search.php?'.htmlspecialchars_uni($_SERVER['QUERY_STRING']);
	}

	// Show search results as 'threads'
	if($search['resulttype'] == "threads")
	{
		$results['threadcount'] = 0;

		// Moderators can view unapproved threads
		$query = $db->simple_select("moderators", "fid, canviewunapprove, canviewdeleted", "(id='{$mybb->user['uid']}' AND isgroup='0') OR (id='{$mybb->user['usergroup']}' AND isgroup='1')");
		if($mybb->usergroup['issupermod'] == 1)
		{
			// Super moderators (and admins)
			$unapproved_where = "t.visible>=-1";
		}
		elseif($db->num_rows($query))
		{
			// Normal moderators
			$unapprove_forums = array();
			$deleted_forums = array();
			$unapproved_where = '(t.visible = 1';
			while($moderator = $db->fetch_array($query))
			{
				if($moderator['canviewunapprove'] == 1)
				{
					$unapprove_forums[] = $moderator['fid'];
				}

				if($moderator['canviewdeleted'] == 1)
				{
					$deleted_forums[] = $moderator['fid'];
				}
			}

			if(!empty($unapprove_forums))
			{
				$unapproved_where .= " OR (t.visible = 0 AND t.fid IN(".implode(',', $unapprove_forums)."))";
			}
			if(!empty($deleted_forums))
			{
				$unapproved_where .= " OR (t.visible = -1 AND t.fid IN(".implode(',', $deleted_forums)."))";
			}
			$unapproved_where .= ')';
		}
		else
		{
			// Normal users
			$unapproved_where = 't.visible>0';
		}

		// If we have saved WHERE conditions, execute them
		if($search['querycache'] != "")
		{
			$where_conditions = $search['querycache'];
			$query = $db->simple_select("threads t", "t.tid", $where_conditions. " AND {$unapproved_where} AND t.moved='0' ORDER BY t.lastpost DESC {$limitsql}");
			while($thread = $db->fetch_array($query))
			{
				$threads[$thread['tid']] = $thread['tid'];
				$results['threadcount']++;
			}
			// Build our list of threads.
			if($results['threadcount'] > 0)
			{
				$search['threads'] = implode(",", $threads);
			}
			// No results.
			else
			{
				error($lang->error_nosearchresults);
			}
			$where_conditions = "t.tid IN (".$search['threads'].")";
		}
		// This search doesn't use a query cache, results stored in search table.
		else
		{
			$where_conditions = "t.tid IN (".$search['threads'].")";
			$query = $db->simple_select("threads t", "COUNT(t.tid) AS resultcount", $where_conditions. " AND {$unapproved_where} AND t.moved='0' {$limitsql}");
			$count = $db->fetch_array($query);

			if(!$count['resultcount'])
			{
				error($lang->error_nosearchresults);
			}
			$results['threadcount'] = $count['resultcount'];
		}

		$permsql = "";
		$onlyusfids = array();

		// Check group permissions if we can't view threads not started by us
		$group_permissions = forum_permissions();
		foreach($group_permissions as $fid => $forum_permissions)
		{
			if(isset($forum_permissions['canonlyviewownthreads']) && $forum_permissions['canonlyviewownthreads'] == 1)
			{
				$onlyusfids[] = $fid;
			}
		}
		if(!empty($onlyusfids))
		{
			$permsql .= "AND ((t.fid IN(".implode(',', $onlyusfids).") AND t.uid='{$mybb->user['uid']}') OR t.fid NOT IN(".implode(',', $onlyusfids)."))";
		}

		$unsearchforums = get_unsearchable_forums();
		if($unsearchforums)
		{
			$permsql .= " AND t.fid NOT IN ($unsearchforums)";
		}
		$inactiveforums = get_inactive_forums();
		if($inactiveforums)
		{
			$permsql .= " AND t.fid NOT IN ($inactiveforums)";
		}

		// Begin selecting matching threads, cache them.
		$sqlarray = array(
			'order_by' => $sortfield,
			'order_dir' => $order,
			'limit_start' => $start,
			'limit' => $perpage
		);
		$query = $db->query("
			SELECT t.*, u.username AS userusername, u.avatar AS user_avatar, last_poster.avatar AS last_poster_avatar
			FROM ".TABLE_PREFIX."threads t
			LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=t.uid)
			LEFT JOIN ".TABLE_PREFIX."forums f ON (t.fid=f.fid)
			LEFT JOIN ".TABLE_PREFIX."users last_poster ON (t.lastposteruid = u.uid)
			WHERE $where_conditions AND {$unapproved_where} {$permsql} AND t.moved='0'
			ORDER BY $sortfield $order
			LIMIT $start, $perpage
		");

		$threadprefixes = build_prefixes();
		$thread_cache = array();
		while($thread = $db->fetch_array($query))
		{
			$thread['threadprefix'] = '';
			if($thread['prefix'] && !empty($threadprefixes[$thread['prefix']]))
			{
				$thread['threadprefix'] = $threadprefixes[$thread['prefix']]['displaystyle'];
			}
			$thread_cache[$thread['tid']] = $thread;
		}
		$thread_ids = implode(",", array_keys($thread_cache));

		if(empty($thread_ids))
		{
			error($lang->error_nosearchresults);
		}

		// Fetch dot icons if enabled
		if($mybb->settings['dotfolders'] != 0 && $mybb->user['uid'] && $thread_cache)
		{
			$p_unapproved_where = str_replace('t.', '', $unapproved_where);
			$query = $db->simple_select("posts", "DISTINCT tid,uid", "uid='{$mybb->user['uid']}' AND tid IN({$thread_ids}) AND {$p_unapproved_where}");
			while($thread = $db->fetch_array($query))
			{
				$thread_cache[$thread['tid']]['dot_icon'] = 1;
			}
		}

		// Fetch the read threads.
		if($mybb->user['uid'] && $mybb->settings['threadreadcut'] > 0)
		{
			$query = $db->simple_select("threadsread", "tid,dateline", "uid='".$mybb->user['uid']."' AND tid IN(".$thread_ids.")");
			while($readthread = $db->fetch_array($query))
			{
				$thread_cache[$readthread['tid']]['lastread'] = $readthread['dateline'];
			}
		}

		if(!$mybb->settings['maxmultipagelinks'])
		{
			$mybb->settings['maxmultipagelinks'] = 5;
		}

        $threads = [];

        foreach ($thread_cache as $thread) {
            if ($thread['userusername']) {
                $thread['username'] = $thread['userusername'];
            }

            $thread['profilelink'] = build_profile_link($thread['username'], $thread['uid']);

            // If this thread has a prefix, insert a space between prefix and subject
            if ($thread['prefix'] != 0) {
                $thread['threadprefix'] .= '&nbsp;';
            }

            $thread['subject'] = $parser->parse_badwords($thread['subject']);

            $thread['hasicon'] = false;
            if (isset($icon_cache[$thread['icon']])) {
                $thread['hasicon'] = true;
                $posticon = $icon_cache[$thread['icon']];
                $posticon['path'] = str_replace("{theme}", $theme['imgdir'], $posticon['path']);
                $thread['icon_path'] = $posticon['path'];
                $thread['icon_name'] = $posticon['name'];
            }

            // Determine the folder
            $thread['folder'] = '';
            $thread['folder_label'] = '';
            if (isset($thread['dot_icon'])) {
                $thread['folder'] = "dot_";
                $thread['folder_label'] .= $lang->icon_dot;
            }

            $last_read = 0;

            if ($mybb->settings['threadreadcut'] > 0 && $mybb->user['uid']) {
                $forum_read = $readforums[$thread['fid']];

                $read_cutoff = TIME_NOW-$mybb->settings['threadreadcut']*60*60*24;
                if ($forum_read == 0 || $forum_read < $read_cutoff) {
                    $forum_read = $read_cutoff;
                }
            } else {
                $forum_read = $forumsread[$thread['fid']];
            }

            if ($mybb->settings['threadreadcut'] > 0 && $mybb->user['uid'] && $thread['lastpost'] > $forum_read) {
                if ($thread['lastread']) {
                    $last_read = $thread['lastread'];
                } else {
                    $last_read = $read_cutoff;
                }
            } else {
                $last_read = my_get_array_cookie("threadread", $thread['tid']);
            }

            if ($forum_read > $last_read) {
                $last_read = $forum_read;
            }

            $thread['unread'] = false;
            if ($thread['lastpost'] > $last_read && $last_read) {
                $thread['unread'] = true;
                $thread['folder'] .= "new";
                $thread['new_class'] = "subject_new";
                $thread['folder_label'] .= $lang->icon_new;
                $thread['newpostlink'] = get_thread_link($thread['tid'], 0, "newpost").$highlight;
            } else {
                $thread['new_class'] = 'subject_old';
                $thread['folder_label'] .= $lang->icon_no_new;
            }

            if ($thread['replies'] >= $mybb->settings['hottopic'] || $thread['views'] >= $mybb->settings['hottopicviews']) {
                $thread['folder'] .= "hot";
                $thread['folder_label'] .= $lang->icon_hot;
            }

            if ($thread['closed'] == 1) {
                $thread['folder'] .= "lock";
                $thread['folder_label'] .= $lang->icon_lock;
            }
            $thread['folder'] .= "folder";

            if (!$mybb->settings['postsperpage'] || (int)$mybb->settings['postsperpage'] < 1) {
                $mybb->settings['postsperpage'] = 20;
            }

            $thread['pages'] = 0;
            $thread['posts'] = $thread['replies'] + 1;
            if (is_moderator($thread['fid'], "canviewdeleted") == true || is_moderator($thread['fid'], "canviewunapprove") == true) {
                if (is_moderator($thread['fid'], "canviewdeleted") == true) {
                    $thread['posts'] += $thread['deletedposts'];
                }

                if (is_moderator($thread['fid'], "canviewunapprove") == true) {
                    $thread['posts'] += $thread['unapprovedposts'];
                }
            } elseif ($group_permissions[$thread['fid']]['canviewdeletionnotice'] != 0) {
                $thread['posts'] += $thread['deletedposts'];
            }

            $thread['multipage'] = [];
            if ($thread['posts'] > $mybb->settings['postsperpage']) {
                $thread['pages'] = $thread['posts'] / $mybb->settings['postsperpage'];
                $thread['pages'] = ceil($thread['pages']);
                if ($thread['pages'] > $mybb->settings['maxmultipagelinks']) {
                    $pagesstop = $mybb->settings['maxmultipagelinks'] - 1;
                    $thread['page_link'] = get_thread_link($thread['tid'], $thread['pages']).$highlight;
                } else {
                    $pagesstop = $thread['pages'];
                }

                for ($i = 1; $i <= $pagesstop; ++$i) {
                    $threadpages['page'] = $i;
                    $threadpages['page_link'] = get_thread_link($thread['tid'], $i).$highlight;
                    $thread['multipage'][] = $threadpages;
                }
            }

            $thread['lastpostdate'] = my_date('relative', $thread['lastpost']);
            $thread['lastpostlink'] = get_thread_link($thread['tid'], 0, "lastpost");
            $lastposteruid = $thread['lastposteruid'];
            if (!$lastposteruid && !$thread['lastposter']) {
                $lastposter = htmlspecialchars_uni($lang->guest);
            } else {
                $lastposter = htmlspecialchars_uni($thread['lastposter']);
            }

            $thread['thread_link'] = get_thread_link($thread['tid']).$highlight;

            // Don't link to guest's profiles (they have no profile).
            if ($lastposteruid == 0) {
                $thread['lastposterlink'] = $lastposter;
            } else {
                $thread['lastposterlink'] = build_profile_link($lastposter, $lastposteruid);
            }

            $thread['replies'] = my_number_format($thread['replies']);
            $thread['views'] = my_number_format($thread['views']);

            if ($forumcache[$thread['fid']]) {
                $thread['forumlink_link'] = get_forum_link($thread['fid']);
                $thread['forumlink_name'] = $forumcache[$thread['fid']]['name'];
            }

            // If this user is the author of the thread and it is not closed or they are a moderator, they can edit
            $thread['editable_subject'] = false;
            if (($thread['uid'] == $mybb->user['uid'] && $thread['closed'] != 1 && $mybb->user['uid'] != 0 && $fpermissions[$thread['fid']]['caneditposts'] == 1) || is_moderator($thread['fid'], "caneditposts")) {
                $thread['editable_subject'] = true;
            }

            // If this thread has 1 or more attachments show the papperclip
            if ($mybb->settings['enableattachments'] == 1 && $thread['attachmentcount'] > 0) {
                if ($thread['attachmentcount'] > 1) {
                    $thread['attachment_count'] = $lang->sprintf($lang->attachment_count_multiple, $thread['attachmentcount']);
                } else {
                    $thread['attachment_count'] = $lang->attachment_count;
                }
            }

            // Inline thread moderation
            $thread['show_mod_checkbox'] = $thread['show_mod_nocheck'] = false;
            if ($is_supermod || is_moderator($thread['fid'])) {
                $thread['checked'] = false;
                if (isset($mybb->cookies[$inlinecookie]) && my_strpos($mybb->cookies[$inlinecookie], "|{$thread['tid']}|") !== false) {
                    $thread['checked'] = true;
                    ++$results['inlinecount'];
                }

                // If this user is allowed to use the inline moderation tools for at least one thread, include the necessary scripts
                $results['show_inline_moderation'] = true;
                $thread['show_mod_checkbox'] = true;
            } elseif ($is_mod) {
                $thread['show_mod_nocheck'] = true;
            }

            $plugins->run_hooks("search_results_thread");
            $threads[] = $thread;
        }

        if (!$threads) {
            error($lang->error_nosearchresults);
        }

        $multipage = multipage($results['threadcount'], $perpage, $page, "search.php?action=results&amp;sid=$sid&amp;sortby=$sortby&amp;order=$order&amp;uid=".$mybb->get_input('uid', MyBB::INPUT_INT));
        if ($upper > $results['threadcount']) {
            $upper = $results['threadcount'];
        }

        // Inline Thread Moderation Options
        $results['show_mod_empty'] = false;
        if ($results['show_inline_moderation']) {
            // If user has moderation tools available, prepare the Select All feature
            $lang->page_selected = $lang->sprintf($lang->page_selected, count($thread_cache));
            $lang->all_selected = $lang->sprintf($lang->all_selected, (int)$results['threadcount']);
            $lang->select_all = $lang->sprintf($lang->select_all, (int)$results['threadcount']);

            $results['customthreadtools'] = [];
            switch ($db->type) {
                case "pgsql":
                case "sqlite":
                    $query = $db->simple_select("modtools", "tid, name", "type='t' AND (','||forums||',' LIKE '%,-1,%' OR forums='')");
                    break;
                default:
                    $query = $db->simple_select("modtools", "tid, name", "type='t' AND (CONCAT(',',forums,',') LIKE '%,-1,%' OR forums='')");
            }

            while ($tool = $db->fetch_array($query)) {
                $results['customthreadtools'][] = $tool;
            }

            // Build inline moderation dropdown
            $results['show_custom_tools'] = false;
            if (!empty($results['customthreadtools'])) {
                $results['show_custom_tools'] = true;
            }
        } elseif ($is_mod) {
            $results['show_mod_empty'] = true;
        }

        $results['sid'] = $sid;
        $plugins->run_hooks("search_results_end");

        output_page(\MyBB\template('search/results_threads.twig', [
            'multipage' => $multipage,
            'results' => $results,
            'threads' => $threads,
        ]));
	}
	else // Displaying results as posts
	{
		if(!$search['posts'])
		{
			error($lang->error_nosearchresults);
		}

		$results['postcount'] = 0;

		// Moderators can view unapproved threads
		$query = $db->simple_select("moderators", "fid, canviewunapprove, canviewdeleted", "(id='{$mybb->user['uid']}' AND isgroup='0') OR (id='{$mybb->user['usergroup']}' AND isgroup='1')");
		if($mybb->usergroup['issupermod'] == 1)
		{
			// Super moderators (and admins)
			$unapproved_where = "visible >= -1";
		}
		elseif($db->num_rows($query))
		{
			// Normal moderators
			$unapprove_forums = array();
			$deleted_forums = array();
			$unapproved_where = '(visible = 1';

			while($moderator = $db->fetch_array($query))
			{
				if($moderator['canviewunapprove'] == 1)
				{
					$unapprove_forums[] = $moderator['fid'];
				}

				if($moderator['canviewdeleted'] == 1)
				{
					$deleted_forums[] = $moderator['fid'];
				}
			}

			if(!empty($unapprove_forums))
			{
				$unapproved_where .= " OR (visible = 0 AND fid IN(".implode(',', $unapprove_forums)."))";
			}
			if(!empty($deleted_forums))
			{
				$unapproved_where .= " OR (visible = -1 AND fid IN(".implode(',', $deleted_forums)."))";
			}
			$unapproved_where .= ')';
		}
		else
		{
			// Normal users
			$unapproved_where = 'visible = 1';
		}

		$post_cache_options = array();
		if((int)$mybb->settings['searchhardlimit'] > 0)
		{
			$post_cache_options['limit'] = (int)$mybb->settings['searchhardlimit'];
		}

		if(strpos($sortfield, 'p.') !== false)
		{
			$post_cache_options['order_by'] = str_replace('p.', '', $sortfield);
			$post_cache_options['order_dir'] = $order;
		}

		$tids = array();
		$pids = array();
		// Make sure the posts we're viewing we have permission to view.
		$query = $db->simple_select("posts", "pid, tid", "pid IN(".$db->escape_string($search['posts']).") AND {$unapproved_where}", $post_cache_options);
		while($post = $db->fetch_array($query))
		{
			$pids[$post['pid']] = $post['tid'];
			$tids[$post['tid']][$post['pid']] = $post['pid'];
		}

		if(!empty($pids))
		{
			$temp_pids = array();

			$group_permissions = forum_permissions();
			$permsql = '';
			$onlyusfids = array();

			foreach($group_permissions as $fid => $forum_permissions)
			{
				if(!empty($forum_permissions['canonlyviewownthreads']))
				{
					$onlyusfids[] = $fid;
				}
			}

			if($onlyusfids)
			{
				$permsql .= " OR (fid IN(".implode(',', $onlyusfids).") AND uid!={$mybb->user['uid']})";
			}
			$unsearchforums = get_unsearchable_forums();
			if($unsearchforums)
			{
				$permsql .= " OR fid IN ($unsearchforums)";
			}
			$inactiveforums = get_inactive_forums();
			if($inactiveforums)
			{
				$permsql .= " OR fid IN ($inactiveforums)";
			}

			// Check the thread records as well. If we don't have permissions, remove them from the listing.
			$query = $db->simple_select("threads", "tid", "tid IN(".$db->escape_string(implode(',', $pids)).") AND ({$unapproved_where}{$permsql} OR moved != '0')");
			while($thread = $db->fetch_array($query))
			{
				if(array_key_exists($thread['tid'], $tids) != true)
				{
					$temp_pids = $tids[$thread['tid']];
					foreach($temp_pids as $pid)
					{
						unset($pids[$pid]);
						unset($tids[$thread['tid']]);
					}
				}
			}
			unset($temp_pids);
		}

		// Declare our post count
		$results['postcount'] = count($pids);

		if(!$results['postcount'])
		{
			error($lang->error_nosearchresults);
		}

		// And now we have our sanatized post list
		$search['posts'] = implode(',', array_keys($pids));

		$tids = implode(",", array_keys($tids));

		// Read threads
		if($mybb->user['uid'] && $mybb->settings['threadreadcut'] > 0)
		{
			$query = $db->simple_select("threadsread", "tid, dateline", "uid='".$mybb->user['uid']."' AND tid IN(".$db->escape_string($tids).")");
			while($readthread = $db->fetch_array($query))
			{
				$readthreads[$readthread['tid']] = $readthread['dateline'];
			}
		}

		$dot_icon = array();
		if($mybb->settings['dotfolders'] != 0 && $mybb->user['uid'] != 0)
		{
			$query = $db->simple_select("posts", "DISTINCT tid,uid", "uid='{$mybb->user['uid']}' AND tid IN({$db->escape_string($tids)}) AND {$unapproved_where}");
			while($post = $db->fetch_array($query))
			{
				$dot_icon[$post['tid']] = true;
			}
		}

        $posts = [];

        $query = $db->query("
            SELECT p.*, u.username AS userusername, u.avatar AS user_avatar, t.subject AS thread_subject, t.replies AS thread_replies, t.views AS thread_views, t.lastpost AS thread_lastpost, t.closed AS thread_closed, t.uid as thread_uid
            FROM " . TABLE_PREFIX . "posts p
            LEFT JOIN " . TABLE_PREFIX . "threads t ON (t.tid=p.tid)
            LEFT JOIN " . TABLE_PREFIX . "users u ON (u.uid=p.uid)
            WHERE p.pid IN (".$db->escape_string($search['posts']).")
            ORDER BY $sortfield $order
            LIMIT $start, $perpage
        ");
        while ($post = $db->fetch_array($query)) {
            if ($post['userusername']) {
                $post['username'] = $post['userusername'];
            }

            $post['profilelink'] = build_profile_link($post['username'], $post['uid']);
            $post['subject'] = $parser->parse_badwords($post['subject']);
            $post['thread_subject'] = $parser->parse_badwords($post['thread_subject']);

            $post['hasicon'] = false;
            if (isset($icon_cache[$post['icon']])) {
                $post['hasicon'] = true;
                $posticon = $icon_cache[$post['icon']];
                $posticon['path'] = str_replace("{theme}", $theme['imgdir'], $posticon['path']);
                $post['icon_path'] = $posticon['path'];
                $post['icon_name'] = $posticon['name'];
            }

            if (!empty($forumcache[$thread['fid']])) {
                $post['forumlink_link'] = get_forum_link($post['fid']);
                $post['forumlink_name'] = $forumcache[$post['fid']]['name'];
            }

            // Determine the folder
            $post['folder'] = '';
            $post['folder_label'] = '';
            $last_read = 0;
            $post['thread_lastread'] = $readthreads[$post['tid']];

            if ($mybb->settings['threadreadcut'] > 0 && $mybb->user['uid']) {
                $forum_read = $readforums[$post['fid']];

                $read_cutoff = TIME_NOW-$mybb->settings['threadreadcut']*60*60*24;
                if ($forum_read == 0 || $forum_read < $read_cutoff) {
                    $forum_read = $read_cutoff;
                }
            } else {
                $forum_read = $forumsread[$post['fid']];
            }

            if ($mybb->settings['threadreadcut'] > 0 && $mybb->user['uid'] && $post['thread_lastpost'] > $forum_read) {
                $cutoff = TIME_NOW-$mybb->settings['threadreadcut']*60*60*24;
                if ($post['thread_lastpost'] > $cutoff) {
                    if ($post['thread_lastread']) {
                        $last_read = $post['thread_lastread'];
                    } else {
                        $last_read = 1;
                    }
                }
            }

            if (isset($dot_icon[$post['tid']])) {
                $post['folder'] = "dot_";
                $post['folder_label'] .= $lang->icon_dot;
            }

            if (!$last_read) {
                $readcookie = $threadread = my_get_array_cookie("threadread", $post['tid']);
                if ($readcookie > $forum_read) {
                    $last_read = $readcookie;
                } elseif ($forum_read > $mybb->user['lastvisit']) {
                    $last_read = $forum_read;
                } else {
                    $last_read = $mybb->user['lastvisit'];
                }
            }

            $post['unread'] = false;
            if ($post['thread_lastpost'] > $last_read && $last_read) {
                $post['folder'] .= "new";
                $post['folder_label'] .= $lang->icon_new;
                $post['unread'] = true;
            } else {
                $post['folder_label'] .= $lang->icon_no_new;
            }

            if ($post['thread_replies'] >= $mybb->settings['hottopic'] || $post['thread_views'] >= $mybb->settings['hottopicviews']) {
                $post['folder'] .= "hot";
                $post['folder_label'] .= $lang->icon_hot;
            }

            if ($post['thread_closed'] == 1) {
                $post['folder'] .= "lock";
                $post['folder_label'] .= $lang->icon_lock;
            }

            $post['folder'] .= "folder";

            $post['thread_replies'] = my_number_format($post['thread_replies']);
            $post['thread_views'] = my_number_format($post['thread_views']);

            if ($forumcache[$post['fid']]) {
                $post['forumlink_link'] = get_forum_link($post['fid']);
                $post['forumlink_name'] = $forumcache[$post['fid']]['name'];
            }

            if (!$post['subject']) {
                $post['subject'] = $post['message'];
            }

            // What we do here is parse the post using our post parser, then strip the tags from it
            $parser_options = array(
                'allow_html' => 0,
                'allow_mycode' => 1,
                'allow_smilies' => 0,
                'allow_imgcode' => 0,
                'filter_badwords' => 1
            );
            $post['message'] = strip_tags($parser->parse_message($post['message'], $parser_options));

            $post['posted'] = my_date('relative', $post['dateline']);

            $post['thread_url'] = get_thread_link($post['tid']).$highlight;
            $post['post_url'] = get_post_link($post['pid'], $post['tid']).$highlight;

            // Inline post moderation
            $post['show_mod_checkbox'] = $post['show_mod_nocheck'] = false;
            if ($is_supermod || is_moderator($post['fid'])) {
                $post['checked'] = false;
                if (isset($mybb->cookies[$inlinecookie]) && my_strpos($mybb->cookies[$inlinecookie], "|{$post['pid']}|") !== false) {
                    $post['checked'] = true;
                    ++$results['inlinecount'];
                }

                $results['show_inline_moderation'] = true;
                $post['show_mod_checkbox'] = true;
            } elseif ($is_mod) {
                $post['show_mod_nocheck'] = true;
            }

            $plugins->run_hooks("search_results_post");
            $posts[] = $post;
        }

        if (!$posts) {
            error($lang->error_nosearchresults);
        }

        $multipage = multipage($results['postcount'], $perpage, $page, "search.php?action=results&amp;sid=".htmlspecialchars_uni($mybb->get_input('sid'))."&amp;sortby=$sortby&amp;order=$order&amp;uid=".$mybb->get_input('uid', MyBB::INPUT_INT));
        if ($upper > $results['postcount']) {
            $upper = $results['postcount'];
        }

        // Inline Post Moderation Options
        if ($results['show_inline_moderation']) {
            // If user has moderation tools available, prepare the Select All feature
            $num_results = $db->num_rows($query);
            $lang->page_selected = $lang->sprintf($lang->page_selected, (int)$num_results);
            $lang->select_all = $lang->sprintf($lang->select_all, (int)$results['postcount']);
            $lang->all_selected = $lang->sprintf($lang->all_selected, (int)$results['postcount']);

            $results['customposttools'] = [];
            switch ($db->type) {
                case "pgsql":
                case "sqlite":
                    $query = $db->simple_select("modtools", "tid, name, type", "type='p' AND (','||forums||',' LIKE '%,-1,%' OR forums='')");
                    break;
                default:
                    $query = $db->simple_select("modtools", "tid, name, type", "type='p' AND (CONCAT(',',forums,',') LIKE '%,-1,%' OR forums='')");
            }

            while ($tool = $db->fetch_array($query)) {
                $results['customposttools'][] = $tool;
            }

            // Build inline moderation dropdown
            $results['show_custom_tools'] = false;
            if (!empty($results['customposttools'])) {
                $results['show_custom_tools'] = true;
            }
        } elseif ($is_mod) {
            $results['show_mod_empty'] = true;
        }

        $results['sid'] = $sid;
        $plugins->run_hooks("search_results_end");

        output_page(\MyBB\template('search/results_posts.twig', [
            'multipage' => $multipage,
            'results' => $results,
            'posts' => $posts,
        ]));
	}
}
elseif($mybb->input['action'] == "findguest")
{
	$where_sql = "uid='0'";

	$unsearchforums = get_unsearchable_forums();
	if($unsearchforums)
	{
		$where_sql .= " AND fid NOT IN ($unsearchforums)";
	}
	$inactiveforums = get_inactive_forums();
	if($inactiveforums)
	{
		$where_sql .= " AND fid NOT IN ($inactiveforums)";
	}

	$permsql = "";
	$onlyusfids = array();

	// Check group permissions if we can't view threads not started by us
	$group_permissions = forum_permissions();
	foreach($group_permissions as $fid => $forum_permissions)
	{
		if(isset($forum_permissions['canonlyviewownthreads']) && $forum_permissions['canonlyviewownthreads'] == 1)
		{
			$onlyusfids[] = $fid;
		}
	}
	if(!empty($onlyusfids))
	{
		$where_sql .= " AND fid NOT IN(".implode(',', $onlyusfids).")";
	}

	$options = array(
		'order_by' => 'dateline',
		'order_dir' => 'desc'
	);

	// Do we have a hard search limit?
	if($mybb->settings['searchhardlimit'] > 0)
	{
		$options['limit'] = (int)$mybb->settings['searchhardlimit'];
	}

	$pids = '';
	$comma = '';
	$query = $db->simple_select("posts", "pid", "{$where_sql}", $options);
	while($pid = $db->fetch_field($query, "pid"))
	{
			$pids .= $comma.$pid;
			$comma = ',';
	}

	$tids = '';
	$comma = '';
	$query = $db->simple_select("threads", "tid", $where_sql);
	while($tid = $db->fetch_field($query, "tid"))
	{
			$tids .= $comma.$tid;
			$comma = ',';
	}

	$sid = md5(uniqid(microtime(), true));
	$searcharray = array(
		"sid" => $db->escape_string($sid),
		"uid" => $mybb->user['uid'],
		"dateline" => TIME_NOW,
		"ipaddress" => $db->escape_binary($session->packedip),
		"threads" => $db->escape_string($tids),
		"posts" => $db->escape_string($pids),
		"resulttype" => "posts",
		"querycache" => '',
		"keywords" => ''
	);
	$plugins->run_hooks("search_do_search_process");
	$db->insert_query("searchlog", $searcharray);
	redirect("search.php?action=results&sid=".$sid, $lang->redirect_searchresults);
}
elseif($mybb->input['action'] == "finduser")
{
	$where_sql = "uid='".$mybb->get_input('uid', MyBB::INPUT_INT)."'";

	$unsearchforums = get_unsearchable_forums();
	if($unsearchforums)
	{
		$where_sql .= " AND fid NOT IN ($unsearchforums)";
	}
	$inactiveforums = get_inactive_forums();
	if($inactiveforums)
	{
		$where_sql .= " AND fid NOT IN ($inactiveforums)";
	}

	$permsql = "";
	$onlyusfids = array();

	// Check group permissions if we can't view threads not started by us
	$group_permissions = forum_permissions();
	foreach($group_permissions as $fid => $forum_permissions)
	{
		if(isset($forum_permissions['canonlyviewownthreads']) && $forum_permissions['canonlyviewownthreads'] == 1)
		{
			$onlyusfids[] = $fid;
		}
	}
	if(!empty($onlyusfids))
	{
		$where_sql .= "AND ((fid IN(".implode(',', $onlyusfids).") AND uid='{$mybb->user['uid']}') OR fid NOT IN(".implode(',', $onlyusfids)."))";
	}

	$options = array(
		'order_by' => 'dateline',
		'order_dir' => 'desc'
	);

	// Do we have a hard search limit?
	if($mybb->settings['searchhardlimit'] > 0)
	{
		$options['limit'] = (int)$mybb->settings['searchhardlimit'];
	}

	$pids = '';
	$comma = '';
	$query = $db->simple_select("posts", "pid", "{$where_sql}", $options);
	while($pid = $db->fetch_field($query, "pid"))
	{
			$pids .= $comma.$pid;
			$comma = ',';
	}

	$tids = '';
	$comma = '';
	$query = $db->simple_select("threads", "tid", $where_sql);
	while($tid = $db->fetch_field($query, "tid"))
	{
			$tids .= $comma.$tid;
			$comma = ',';
	}

	$sid = md5(uniqid(microtime(), true));
	$searcharray = array(
		"sid" => $db->escape_string($sid),
		"uid" => $mybb->user['uid'],
		"dateline" => TIME_NOW,
		"ipaddress" => $db->escape_binary($session->packedip),
		"threads" => $db->escape_string($tids),
		"posts" => $db->escape_string($pids),
		"resulttype" => "posts",
		"querycache" => '',
		"keywords" => ''
	);
	$plugins->run_hooks("search_do_search_process");
	$db->insert_query("searchlog", $searcharray);
	redirect("search.php?action=results&sid=".$sid, $lang->redirect_searchresults);
}
elseif($mybb->input['action'] == "finduserthreads")
{
	$where_sql = "uid='".$mybb->get_input('uid', MyBB::INPUT_INT)."'";

	$unsearchforums = get_unsearchable_forums();
	if($unsearchforums)
	{
		$where_sql .= " AND fid NOT IN ($unsearchforums)";
	}
	$inactiveforums = get_inactive_forums();
	if($inactiveforums)
	{
		$where_sql .= " AND fid NOT IN ($inactiveforums)";
	}

	$permsql = "";
	$onlyusfids = array();

	// Check group permissions if we can't view threads not started by us
	$group_permissions = forum_permissions();
	foreach($group_permissions as $fid => $forum_permissions)
	{
		if(isset($forum_permissions['canonlyviewownthreads']) && $forum_permissions['canonlyviewownthreads'] == 1)
		{
			$onlyusfids[] = $fid;
		}
	}
	if(!empty($onlyusfids))
	{
		$where_sql .= "AND ((fid IN(".implode(',', $onlyusfids).") AND uid='{$mybb->user['uid']}') OR fid NOT IN(".implode(',', $onlyusfids)."))";
	}

	$tids = '';
	$comma = '';
	$query = $db->simple_select("threads", "tid", $where_sql);
	while($tid = $db->fetch_field($query, "tid"))
	{
			$tids .= $comma.$tid;
			$comma = ',';
	}

	$sid = md5(uniqid(microtime(), true));
	$searcharray = array(
		"sid" => $db->escape_string($sid),
		"uid" => $mybb->user['uid'],
		"dateline" => TIME_NOW,
		"ipaddress" => $db->escape_binary($session->packedip),
		"threads" => $db->escape_string($tids),
		"posts" => '',
		"resulttype" => "threads",
		"querycache" => $db->escape_string($where_sql),
		"keywords" => ''
	);
	$plugins->run_hooks("search_do_search_process");
	$db->insert_query("searchlog", $searcharray);
	redirect("search.php?action=results&sid=".$sid, $lang->redirect_searchresults);
}
elseif($mybb->input['action'] == "getnew")
{

	$where_sql = "lastpost >= '".(int)$mybb->user['lastvisit']."'";

	if($mybb->get_input('fid', MyBB::INPUT_INT))
	{
		$where_sql .= " AND fid='".$mybb->get_input('fid', MyBB::INPUT_INT)."'";
	}
	else if($mybb->get_input('fids'))
	{
		$fids = explode(',', $mybb->get_input('fids'));
		foreach($fids as $key => $fid)
		{
			$fids[$key] = (int)$fid;
		}

		if(!empty($fids))
		{
			$where_sql .= " AND fid IN (".implode(',', $fids).")";
		}
	}

	$unsearchforums = get_unsearchable_forums();
	if($unsearchforums)
	{
		$where_sql .= " AND fid NOT IN ($unsearchforums)";
	}
	$inactiveforums = get_inactive_forums();
	if($inactiveforums)
	{
		$where_sql .= " AND fid NOT IN ($inactiveforums)";
	}

	$permsql = "";
	$onlyusfids = array();

	// Check group permissions if we can't view threads not started by us
	$group_permissions = forum_permissions();
	foreach($group_permissions as $fid => $forum_permissions)
	{
		if(isset($forum_permissions['canonlyviewownthreads']) && $forum_permissions['canonlyviewownthreads'] == 1)
		{
			$onlyusfids[] = $fid;
		}
	}
	if(!empty($onlyusfids))
	{
		$where_sql .= "AND ((fid IN(".implode(',', $onlyusfids).") AND uid='{$mybb->user['uid']}') OR fid NOT IN(".implode(',', $onlyusfids)."))";
	}

	$tids = '';
	$comma = '';
	$query = $db->simple_select("threads", "tid", $where_sql);
	while($tid = $db->fetch_field($query, "tid"))
	{
			$tids .= $comma.$tid;
			$comma = ',';
	}

	$sid = md5(uniqid(microtime(), true));
	$searcharray = array(
		"sid" => $db->escape_string($sid),
		"uid" => $mybb->user['uid'],
		"dateline" => TIME_NOW,
		"ipaddress" => $db->escape_binary($session->packedip),
		"threads" => $db->escape_string($tids),
		"posts" => '',
		"resulttype" => "threads",
		"querycache" => $db->escape_string($where_sql),
		"keywords" => ''
	);

	$plugins->run_hooks("search_do_search_process");
	$db->insert_query("searchlog", $searcharray);
	redirect("search.php?action=results&sid=".$sid, $lang->redirect_searchresults);
}
elseif($mybb->input['action'] == "getdaily")
{
	if($mybb->get_input('days', MyBB::INPUT_INT) < 1)
	{
		$days = 1;
	}
	else
	{
		$days = $mybb->get_input('days', MyBB::INPUT_INT);
	}
	$datecut = TIME_NOW-(86400*$days);

	$where_sql = "lastpost >='".$datecut."'";

	if($mybb->get_input('fid', MyBB::INPUT_INT))
	{
		$where_sql .= " AND fid='".$mybb->get_input('fid', MyBB::INPUT_INT)."'";
	}
	else if($mybb->get_input('fids'))
	{
		$fids = explode(',', $mybb->get_input('fids'));
		foreach($fids as $key => $fid)
		{
			$fids[$key] = (int)$fid;
		}

		if(!empty($fids))
		{
			$where_sql .= " AND fid IN (".implode(',', $fids).")";
		}
	}

	$unsearchforums = get_unsearchable_forums();
	if($unsearchforums)
	{
		$where_sql .= " AND fid NOT IN ($unsearchforums)";
	}
	$inactiveforums = get_inactive_forums();
	if($inactiveforums)
	{
		$where_sql .= " AND fid NOT IN ($inactiveforums)";
	}

	$permsql = "";
	$onlyusfids = array();

	// Check group permissions if we can't view threads not started by us
	$group_permissions = forum_permissions();
	foreach($group_permissions as $fid => $forum_permissions)
	{
		if(isset($forum_permissions['canonlyviewownthreads']) && $forum_permissions['canonlyviewownthreads'] == 1)
		{
			$onlyusfids[] = $fid;
		}
	}
	if(!empty($onlyusfids))
	{
		$where_sql .= "AND ((fid IN(".implode(',', $onlyusfids).") AND uid='{$mybb->user['uid']}') OR fid NOT IN(".implode(',', $onlyusfids)."))";
	}

	$tids = '';
	$comma = '';
	$query = $db->simple_select("threads", "tid", $where_sql);
	while($tid = $db->fetch_field($query, "tid"))
	{
			$tids .= $comma.$tid;
			$comma = ',';
	}

	$sid = md5(uniqid(microtime(), true));
	$searcharray = array(
		"sid" => $db->escape_string($sid),
		"uid" => $mybb->user['uid'],
		"dateline" => TIME_NOW,
		"ipaddress" => $db->escape_binary($session->packedip),
		"threads" => $db->escape_string($tids),
		"posts" => '',
		"resulttype" => "threads",
		"querycache" => $db->escape_string($where_sql),
		"keywords" => ''
	);

	$plugins->run_hooks("search_do_search_process");
	$db->insert_query("searchlog", $searcharray);
	redirect("search.php?action=results&sid=".$sid, $lang->redirect_searchresults);
}
elseif($mybb->input['action'] == "do_search" && $mybb->request_method == "post")
{
	$plugins->run_hooks("search_do_search_start");

	// Check if search flood checking is enabled and user is not admin
	if($mybb->settings['searchfloodtime'] > 0 && $mybb->usergroup['cancp'] != 1)
	{
		// Fetch the time this user last searched
		if($mybb->user['uid'])
		{
			$conditions = "uid='{$mybb->user['uid']}'";
		}
		else
		{
			$conditions = "uid='0' AND ipaddress=".$db->escape_binary($session->packedip);
		}
		$timecut = TIME_NOW-$mybb->settings['searchfloodtime'];
		$query = $db->simple_select("searchlog", "*", "$conditions AND dateline > '$timecut'", array('order_by' => "dateline", 'order_dir' => "DESC"));
		$last_search = $db->fetch_array($query);
		// Users last search was within the flood time, show the error
		if($last_search['sid'])
		{
			$remaining_time = $mybb->settings['searchfloodtime']-(TIME_NOW-$last_search['dateline']);
			if($remaining_time == 1)
			{
				$lang->error_searchflooding = $lang->sprintf($lang->error_searchflooding_1, $mybb->settings['searchfloodtime']);
			}
			else
			{
				$lang->error_searchflooding = $lang->sprintf($lang->error_searchflooding, $mybb->settings['searchfloodtime'], $remaining_time);
			}
			error($lang->error_searchflooding);
		}
	}
	if($mybb->get_input('showresults') == "threads")
	{
		$resulttype = "threads";
	}
	else
	{
		$resulttype = "posts";
	}

	$search_data = array(
		"keywords" => $mybb->input['keywords'],
		"author" => $mybb->get_input('author'),
		"postthread" => $mybb->get_input('postthread', MyBB::INPUT_INT),
		"matchusername" => $mybb->get_input('matchusername', MyBB::INPUT_INT),
		"postdate" => $mybb->get_input('postdate', MyBB::INPUT_INT),
		"pddir" => $mybb->get_input('pddir', MyBB::INPUT_INT),
		"forums" => $mybb->input['forums'],
		"findthreadst" => $mybb->get_input('findthreadst', MyBB::INPUT_INT),
		"numreplies" => $mybb->get_input('numreplies', MyBB::INPUT_INT),
		"threadprefix" => $mybb->get_input('threadprefix', MyBB::INPUT_ARRAY)
	);

	if(is_moderator() && !empty($mybb->input['visible']))
	{
		$search_data['visible'] = $mybb->get_input('visible', MyBB::INPUT_INT);
	}

	if($db->can_search == true)
	{
		if($mybb->settings['searchtype'] == "fulltext" && $db->supports_fulltext_boolean("posts") && $db->is_fulltext("posts"))
		{
			$search_results = perform_search_mysql_ft($search_data);
		}
		else
		{
			$search_results = perform_search_mysql($search_data);
		}
	}
	else
	{
		error($lang->error_no_search_support);
	}
	$sid = md5(uniqid(microtime(), true));
	$searcharray = array(
		"sid" => $db->escape_string($sid),
		"uid" => $mybb->user['uid'],
		"dateline" => $now,
		"ipaddress" => $db->escape_binary($session->packedip),
		"threads" => $search_results['threads'],
		"posts" => $search_results['posts'],
		"resulttype" => $resulttype,
		"querycache" => $search_results['querycache'],
		"keywords" => $db->escape_string($mybb->input['keywords']),
	);
	$plugins->run_hooks("search_do_search_process");

	$db->insert_query("searchlog", $searcharray);

	if(my_strtolower($mybb->get_input('sortordr')) == "asc" || my_strtolower($mybb->get_input('sortordr') == "desc"))
	{
		$sortorder = $mybb->get_input('sortordr');
	}
	else
	{
		$sortorder = "desc";
	}
	$sortby = htmlspecialchars_uni($mybb->get_input('sortby'));
	$plugins->run_hooks("search_do_search_end");
	redirect("search.php?action=results&sid=".$sid."&sortby=".$sortby."&order=".$sortorder, $lang->redirect_searchresults);
}
else if($mybb->input['action'] == "thread")
{
	// Fetch thread info
	$thread = get_thread($mybb->get_input('tid', MyBB::INPUT_INT));
	$ismod = is_moderator($thread['fid']);

	if(!$thread || ($thread['visible'] != 1 && $ismod == false && ($thread['visible'] != -1 || $mybb->settings['soft_delete'] != 1 || !$mybb->user['uid'] || $mybb->user['uid'] != $thread['uid'])) || ($thread['visible'] > 1 && $ismod == true))
	{
		error($lang->error_invalidthread);
	}

	// Get forum info
	$forum = get_forum($thread['fid']);
	if(!$forum)
	{
		error($lang->error_invalidforum);
	}

	$forum_permissions = forum_permissions($forum['fid']);

	if($forum['open'] == 0 || $forum['type'] != "f")
	{
		error($lang->error_closedinvalidforum);
	}
	if($forum_permissions['canview'] == 0 || $forum_permissions['canviewthreads'] != 1 || (isset($forum_permissions['canonlyviewownthreads']) && $forum_permissions['canonlyviewownthreads'] != 0 && $thread['uid'] != $mybb->user['uid']))
	{
		error_no_permission();
	}

	$plugins->run_hooks("search_thread_start");

	// Check if search flood checking is enabled and user is not admin
	if($mybb->settings['searchfloodtime'] > 0 && $mybb->usergroup['cancp'] != 1)
	{
		// Fetch the time this user last searched
		if($mybb->user['uid'])
		{
			$conditions = "uid='{$mybb->user['uid']}'";
		}
		else
		{
			$conditions = "uid='0' AND ipaddress=".$db->escape_binary($session->packedip);
		}
		$timecut = TIME_NOW-$mybb->settings['searchfloodtime'];
		$query = $db->simple_select("searchlog", "*", "$conditions AND dateline > '$timecut'", array('order_by' => "dateline", 'order_dir' => "DESC"));
		$last_search = $db->fetch_array($query);

		// We shouldn't show remaining time if time is 0 or under.
		$remaining_time = $mybb->settings['searchfloodtime']-(TIME_NOW-$last_search['dateline']);
		// Users last search was within the flood time, show the error.
		if($last_search['sid'] && $remaining_time > 0)
		{
			if($remaining_time == 1)
			{
				$lang->error_searchflooding = $lang->sprintf($lang->error_searchflooding_1, $mybb->settings['searchfloodtime']);
			}
			else
			{
				$lang->error_searchflooding = $lang->sprintf($lang->error_searchflooding, $mybb->settings['searchfloodtime'], $remaining_time);
			}
			error($lang->error_searchflooding);
		}
	}

	$search_data = array(
		"keywords" => $mybb->input['keywords'],
		"postthread" => 1,
		"tid" => $mybb->get_input('tid', MyBB::INPUT_INT)
	);

	if($db->can_search == true)
	{
		if($mybb->settings['searchtype'] == "fulltext" && $db->supports_fulltext_boolean("posts") && $db->is_fulltext("posts"))
		{
			$search_results = perform_search_mysql_ft($search_data);
		}
		else
		{
			$search_results = perform_search_mysql($search_data);
		}
	}
	else
	{
		error($lang->error_no_search_support);
	}
	$sid = md5(uniqid(microtime(), true));
	$searcharray = array(
		"sid" => $db->escape_string($sid),
		"uid" => $mybb->user['uid'],
		"dateline" => $now,
		"ipaddress" => $db->escape_binary($session->packedip),
		"threads" => $search_results['threads'],
		"posts" => $search_results['posts'],
		"resulttype" => 'posts',
		"querycache" => $search_results['querycache'],
		"keywords" => $db->escape_string($mybb->input['keywords'])
	);
	$plugins->run_hooks("search_thread_process");

	$db->insert_query("searchlog", $searcharray);

	$plugins->run_hooks("search_do_search_end");
	redirect("search.php?action=results&sid=".$sid, $lang->redirect_searchresults);
}
else
{
    $plugins->run_hooks("search_start");
    $forums = make_searchable_forums();
    $prefixes = build_prefix_select('all', 'any', 1);

    $search['showprefixes'] = false;
    if (is_array($prefixes)) {
        $search['showprefixes'] = true;
    }

    $search['rowspan'] = 5;

    $search['ismoderator'] = false;
    if (is_moderator()) {
        $search['ismoderator'] = true;
        $search['rowspan'] += 2;
    }

    $plugins->run_hooks("search_end");

    output_page(\MyBB\template('search/search.twig', [
        'search' => $search,
        'forums' => $forums,
        'prefixes' => $prefixes,
    ]));
}
