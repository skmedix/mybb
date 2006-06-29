<?php
/**
 * MyBB 1.2
 * Copyright � 2006 MyBB Group, All Rights Reserved
 *
 * Website: http://www.mybboard.com
 * License: http://www.mybboard.com/eula.html
 *
 * $Id$
 */

/**
 * Build a post bit
 *
 * @param array The post data
 * @param int The type of post bit we're building (1 = preview, 2 = pm, 3 = announcement, else = post)
 * @return string The built post bit
 */
function build_postbit($post, $pmprevann=0)
{
	global $db, $altbg, $theme, $settings, $mybb, $mybbuser, $postcounter, $titlescache, $page, $templates;
	global $forumpermissions, $attachcache, $lang, $ismod, $inlinecookie, $inlinecount, $groupscache, $fid;
	global $plugins, $parser;

	$GLOBALS['post'] = $post;
	
	// Set up the message parser if it doesn't already exist.
	if(!$parser)
	{
		require_once MYBB_ROOT."inc/class_parser.php";
		$parser = new postParser;
	}

	if($post['visible'] == 0 && $pmprevann == 0)
	{
		$altbg = "trow_shaded";
	}
	elseif($altbg == "trow1")
	{
		$altbg = "trow2";
	}
	else
	{
		$altbg = "trow1";
	}
	switch($pmprevann)
	{
		case "1": // Message preview
			global $forum;
			$id = 0;
			break;
		case "2": // Private message
			global $message, $pmid;
			$parser_options['allow_html'] = $mybb->settings['pmsallowhtml'];
			$parser_options['allow_mycode'] = $mybb->settings['pmsallowmycode'];
			$parser_options['allow_smilies'] = $mybb->settings['pmsallowsmilies'];
			$parser_options['allow_imgcode'] = $mybb->settings['pmsallowimgcode'];
			$parser_options['me_username'] = $post['username'];
			$id = $pmid;
			break;
		case "3": // Announcement
			global $announcementarray, $message;
			$parser_options['allow_html'] = $announcementarray['allowhtml'];
			$parser_options['allow_mycode'] = $announcementarray['allowmycode'];
			$parser_options['allow_smilies'] = $announcementarray['allowsmilies'];
			$parser_options['allow_imgcode'] = "yes";
			$parser_options['me_username'] = $post['username'];
			break;
		default: // Regular post
			global $forum, $thread, $tid;
			$oldforum = $forum;
			$id = $post['pid'];
			$parser_options['allow_html'] = $forum['allowhtml'];
			$parser_options['allow_mycode'] = $forum['allow_mycode'];
			$parser_options['allow_smilies'] = $forum['allowsmilies'];
			$parser_options['allow_imgcode'] = $forum['allowimgcode'];
			if($post['userusername'])
			{
				$parser_options['me_username'] = $post['userusername'];
			}
			else
			{
				$parser_options['me_username'] = $post['username'];
			}
			break;
	}

	if(!$postcounter)
	{ // Used to show the # of the post
		if($page > 1)
		{
			$postcounter = $mybb->settings['postsperpage']*($page-1);
		}
		else
		{
			$postcounter = 0;
		}
	}
	if(!$altbg)
	{ // Define the alternate background colour if this is the first post
		$altbg = "trow1";
	}
	$postcounter++;

	// Format the post date and time using mydate
	$post['postdate'] = mydate($mybb->settings['dateformat'], $post['dateline']);
	$post['posttime'] = mydate($mybb->settings['timeformat'], $post['dateline']);

	// Dont want any little 'nasties' in the subject
	$post['subject'] = $parser->parse_badwords($post['subject']);
	$post['subject'] = htmlspecialchars_uni($post['subject']);
	if(empty($post['subject']))
	{
		$post['subject'] = '&nbsp;';
	}

	$post['author'] = $post['uid'];

	// Get the usergroup
	if($post['userusername'])
	{
		if(!$post['displaygroup'])
		{
			$post['displaygroup'] = $post['usergroup'];
		}
		$usergroup = $groupscache[$post['displaygroup']];
	}
	else
	{
		$usergroup = $groupscache[1];
	}

	if(!is_array($titlescache))
	{
		// Get user titles (i guess we should improve this, maybe in version3.
		$query = $db->query("SELECT * FROM ".TABLE_PREFIX."usertitles ORDER BY posts DESC");
		while($usertitle = $db->fetch_array($query))
		{
			$titlescache[$usertitle['posts']] = $usertitle;
		}
		unset($usertitle);
	}

	// Work out the usergroup/title stuff
	if(!empty($usergroup['image']))
	{
		if(!empty($mybb->user['language']))
		{
			$language = $mybb->user['language'];
		}
		else
		{
			$language = $mybb->settings['bblanguage'];
		}
		$usergroup['image'] = str_replace("{lang}", $language, $usergroup['image']);
		eval("\$post['groupimage'] = \"".$templates->get("postbit_groupimage")."\";");
	}

	if($post['userusername'])
	{ // This post was made by a registered user

		$post['username'] = $post['userusername'];
		$post['profilelink'] = "<a href=\"".str_replace("{uid}", $post['uid'], PROFILE_URL)."\">".format_name($post['username'], $post['usergroup'], $post['displaygroup'])."</a>";
		if(trim($post['usertitle']) != "")
		{
			$hascustomtitle = 1;
		}
		if($usergroup['usertitle'] != "" && !$hascustomtitle)
		{
			$post['usertitle'] = $usergroup['usertitle'];
		}
		elseif(is_array($titlescache) && !$usergroup['usertitle'])
		{
			reset($titlescache);
			foreach($titlescache as $key => $titleinfo)
			{
				if($post['postnum'] >= $key)
				{
					if(!$hascustomtitle)
					{
						$post['usertitle'] = $titleinfo['title'];
					}
					$post['stars'] = $titleinfo['stars'];
					$post['starimage'] = $titleinfo['starimage'];
					break;
				}
			}
		}

		if($usergroup['stars'])
		{
			$post['stars'] = $usergroup['stars'];
		}

		if(!$post['starimage'])
		{
			$post['starimage'] = $usergroup['starimage'];
		}
		for($i = 0; $i < $post['stars']; $i++)
		{
			$post['userstars'] .= "<img src=\"".$post['starimage']."\" border=\"0\" alt=\"*\" />";
		}
		if($post['userstars'] && $post['starimage'] && $post['stars'])
		{
			$post['userstars'] .= "<br />";
		}
		$post['postnum'] = mynumberformat($post['postnum']);

		// Determine the status to show for the user (Online/Offline/Away)
		$timecut = time() - $mybb->settings['wolcutoff'];
		if($post['lastactive'] > $timecut && ($post['invisible'] != "yes" || $mybb->usergroup['canviewwolinvis'] == "yes") && $post['lastvisit'] != $post['lastactive'])
		{
			eval("\$post['onlinestatus'] = \"".$templates->get("postbit_online")."\";");
		}
		else
		{
			if($post['away'] == "yes" && $mybb->settings['allowaway'] != "no")
			{
				eval("\$post['onlinestatus'] = \"".$templates->get("postbit_away")."\";");
			}
			else
			{
				eval("\$post['onlinestatus'] = \"".$templates->get("postbit_offline")."\";");
			}
		}

		if($post['avatar'] != "" && $mybb->user['showavatars'] != "no")
		{
			$post['avatar'] = htmlspecialchars_uni($post['avatar']);
			eval("\$post['useravatar'] = \"".$templates->get("postbit_avatar")."\";");
		}
		else
		{
			$post['useravatar'] = "";
		}
		eval("\$post['button_profile'] = \"".$templates->get("postbit_profile")."\";");
		eval("\$post['button_find'] = \"".$templates->get("postbit_find")."\";");
		if($mybb->settings['enablepms'] == "yes" && $post['receivepms'] != "no" && $mybb->usergroup['cansendpms'] == "yes" && strpos(",".$post['ignorelist'].",", ",".$mybb->user['uid'].",") === false)
		{
			eval("\$post['button_pm'] = \"".$templates->get("postbit_pm")."\";");
		}
		if($post['website'] != "")
		{
			$post['website'] = htmlspecialchars_uni($post['website']);
			eval("\$post['button_www'] = \"".$templates->get("postbit_www")."\";");
		}
		else
		{
			$post['button_www'] = "";
		}
		if($post['hideemail'] != "yes" && $mybb->usergroup['cansendemail'] == "yes")
		{
			eval("\$post['button_email'] = \"".$templates->get("postbit_email")."\";");
		}
		else
		{
			$post['button_email'] = "";
		}
		$post['userregdate'] = mydate($mybb->settings['regdateformat'], $post['regdate']);

		// Work out the reputation this user has
		if($usergroup['usereputationsystem'] != "no" && $mybb->settings['enablereputation'] == "yes")
		{
			$post['userreputation'] = get_reputation($post['reputation'], $post['uid']);
			eval("\$post['replink'] = \"".$templates->get("postbit_reputation")."\";");
		}
	}
	else
	{ // Message was posted by a guest or an unknown user
		$post['username'] = $post['username'];
		$post['profilelink'] = format_name($post['username'], 1);
		if($usergroup['usertitle'])
		{
			$post['usertitle'] = $usergroup['usertitle'];
		}
		else
		{
			$post['usertitle'] = $lang->guest;
		}
		$usergroup['title'] = $lang->na;

		$post['userregdate'] = $lang->na;
		$post['postnum'] = $lang->na;
		$post['button_profile'] = '';
		$post['button_email'] = '';
		$post['button_www'] = '';
		$post['signature'] = '';
		$post['button_pm'] = '';
		$post['button_find'] = '';
		$post['onlinestatus'] = $lang->unknown;
		$post['replink'] = '';
	}
	$post['button_edit'] = '';
	$post['button_quickdelete'] = '';
	$post['button_quote'] = '';
	$post['button_quickquote'] = '';
	$post['button_report'] = '';
	if(!$pmprevann)
	{
		if($post['edituid'] != "" && $post['edittime'] != "" && $post['editusername'] != "")
		{
			$post['editdate'] = mydate($mybb->settings['dateformat'], $post['edittime']);
			$post['edittime'] = mydate($mybb->settings['timeformat'], $post['edittime']);
			$post['editnote'] = sprintf($lang->postbit_edited, $post['editdate'], $post['edittime']);
			eval("\$post['editedmsg'] = \"".$templates->get("postbit_editedby")."\";");
		}
		if((is_moderator($fid, "caneditposts") == "yes" || ($forumpermissions['caneditposts'] == 'yes' && $mybb->user['uid'] == $post['uid'])) && $mybb->user['uid'] != 0)
		{
			eval("\$post['button_edit'] = \"".$templates->get("postbit_edit")."\";");
		}
		// Quick Delete button
		if((is_moderator($fid, "candeleteposts") == "yes" || ($forumpermissions['candeleteposts'] == 'yes' && $mybb->user['uid'] == $post['uid']) || ($forumpermissions['candeletethreads'] == 'yes' && $postcounter == 1)) && $mybb->user['uid'] != 0)
		{
			eval("\$post['button_quickdelete'] = \"".$templates->get("postbit_quickdelete")."\";");
		}
		// Inline moderation stuff
		if($ismod)
		{
			if(strstr($_COOKIE[$inlinecookie], "|".$post['pid']."|"))
			{
				$inlinecheck = "checked=\"checked\"";
				$inlinecount++;
			}
			else
			{
				$inlinecheck = "";
			}
			eval("\$post['inlinecheck'] = \"".$templates->get("postbit_inlinecheck")."\";");
			if($post['visible'] == 0)
			{
				$invisiblepost = 1;
			}
		}
		else
		{
			$post['inlinecheck'] = "";
		}
		eval("\$post['posturl'] = \"".$templates->get("postbit_posturl")."\";");
		global $forum, $thread;
		if($forum['open'] != "no" && ($thread['closed'] != "yes" || is_moderator($forum['fid']) == "yes"))
		{
			eval("\$post['button_quote'] = \"".$templates->get("postbit_quote")."\";");
		}
		if($forumpermissions['canpostreplys'] != "no" && ($thread['closed'] != "yes" || is_moderator($fid) == "yes") && $mybb->settings['multiquote'] != "off" && $forum['open'] != "no" && !$pmprevann)
		{
			eval("\$post['button_multiquote'] = \"".$templates->get("postbit_multiquote")."\";");
		}
		if($mybb->user['uid'] != "0")
		{
			eval("\$post['button_report'] = \"".$templates->get("postbit_report")."\";");
		}

		if($mybb->settings['logip'] != "no")
		{
			if($mybb->settings['logip'] == "show")
			{
				eval("\$post['iplogged'] = \"".$templates->get("postbit_iplogged_show")."\";");
			}
			else if($mybb->settings['logip'] == "hide" && $ismod)
			{
				eval("\$post['iplogged'] = \"".$templates->get("postbit_iplogged_hiden")."\";");
			}
			else
			{
				$post['iplogged'] = "";
			}
		}
		else
		{
				$post['iplogged'] = "";
		}

	}
	if($post['smilieoff'] == "yes")
	{
		$parser_options['allow_smilies'] = "no";
	}
	$post['message'] = $parser->parse_message($post['message'], $parser_options);
	if(empty($post['message']))
	{
		$post['message'] = '&nbsp;';
	}

	$validationcount = 0;
	if(is_array($attachcache[$id]))
	{ // This post has 1 or more attachments
		foreach($attachcache[$id] as $aid => $attachment)
		{
			if($attachment['visible'])
			{ // There is an attachment thats visible!
				$attachment['name'] = htmlspecialchars_uni($attachment['name']);
				$attachment['filesize'] = get_friendly_size($attachment['filesize']);
				$ext = get_extension($attachment['filename']);
				if($ext == "jpeg" || $ext == "gif" || $ext == "bmp" || $ext == "png" || $ext == "jpg")
				{
					$isimage = true;
				}
				else
				{
					$isimage = false;
				}
				$attachment['icon'] = get_attachment_icon($ext);
				// Support for [attachment=id] code
				if(stripos($post['message'], "[attachment=".$attachment['aid']."]") !== false)
				{
					if($attachment['thumbnail'] != "SMALL" && $attachment['thumbnail'] != "")
					{ // We have a thumbnail to show (and its not the "SMALL" enough image
						eval("\$attbit = \"".$templates->get("postbit_attachments_thumbnails_thumbnail")."\";");
					}
					elseif($attachment['thumbnail'] == "SMALL" && $forumpermissions['candlattachments'] == "yes")
					{
						// Image is small enough to show - no thumbnail
						eval("\$attbit = \"".$templates->get("postbit_attachments_images_image")."\";");
					}
					else
					{
						// Show standard link to attachment
						eval("\$attbit = \"".$templates->get("postbit_attachments_attachment")."\";");
					}
					$post['message'] = preg_replace("#\[attachment=".$attachment['aid']."]#si", $attbit, $post['message']);
				}
				else
				{
					if($attachment['thumbnail'] != "SMALL" && $attachment['thumbnail'] != "")
					{ // We have a thumbnail to show
						eval("\$post['thumblist'] .= \"".$templates->get("postbit_attachments_thumbnails_thumbnail")."\";");
						if($tcount == 5)
						{
							$thumblist .= "<br />";
							$tcount = 0;
						}
						$tcount++;
					}
					elseif($attachment['thumbnail'] == "SMALL" && $forumpermissions['candlattachments'] == "yes")
					{
						// Image is small enough to show - no thumbnail
						eval("\$post['imagelist'] .= \"".$templates->get("postbit_attachments_images_image")."\";");
					}
					else
					{
						eval("\$post['attachmentlist'] .= \"".$templates->get("postbit_attachments_attachment")."\";");
					}
				}
			}
			else
			{
				$validationcount++;
			}
		}
		if($validationcount > 0 && is_moderator($fid) == 'yes')
		{
			if($validationcount == 1)
			{
				$lang->postbit_unapproved_attachments = $lang->postbit_unapproved_attachment;
			}
			else
			{
				$lang->postbit_unapproved_attachments = sprintf($lang->postbit_unapproved_attachments, $validationcount);
			}
			eval("\$post['attachmentlist'] .= \"".$templates->get("postbit_attachments_attachment_unapproved")."\";");
		}
		if($post['thumblist'])
		{
			eval("\$post['attachedthumbs'] = \"".$templates->get("postbit_attachments_thumbnails")."\";");
		}
		if($post['imagelist'])
		{
			eval("\$post['attachedimages'] = \"".$templates->get("postbit_attachments_images")."\";");
		}
		if($post['attachmentlist'] || $post['thumblist'] || $post['imagelist'])
		{
			eval("\$post['attachments'] = \"".$templates->get("postbit_attachments")."\";");
		}
	}

	if($post['includesig'] != "no" && $post['username'] && $post['signature'] != "" && $mybb->user['showsigs'] != "no")
	{
		$sig_parser = array(
			"allow_html" => $mybb->settings['sightml'],
			"allow_mycode" => $mybb->settings['sigmycode'],
			"allow_smilies" => $mybb->settings['sigsmilies'],
			"allow_imgcode" => $mybb->settings['sigimgcode']
		);

		$post['signature'] = $parser->parse_message($post['signature'], $sig_parser);
		eval("\$post['signature'] = \"".$templates->get("postbit_signature")."\";");
	}
	else
	{
		$post['signature'] = "";
	}

	if($post['iconpath'])
	{
		$post['icon'] = "<img src=\"".$post['iconpath']."\" alt=\"".$post['iconname']."\">&nbsp;";
	}
	else
	{
		$post['icon'] = "";
	}
	$GLOBALS['post'] =& $post;
	switch($pmprevann)
	{
		case 1: // Message preview
			$plugins->run_hooks("postbit_prev");
			break;
		case 2: // Private message
			$plugins->run_hooks("postbit_pm");
			break;
		case 3: // Announcement
			$plugins->run_hooks("postbit_announcement");
			break;
		default: // Regular post
			eval("\$seperator = \"".$templates->get("postbit_seperator")."\";");
			$plugins->run_hooks("postbit");
			break;
	}
	eval("\$postbit = \"".$templates->get("postbit")."\";");
	return $postbit;
	$GLOBALS['post'] = "";
}
?>
