<?php

/**
 * Copyright (C) 2013-2014 ModernBB Group
 * Based on code by FluxBB copyright (C) 2008-2012 FluxBB
 * Based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License under GPLv3
 */

// The contents of this file are very much inspired by the file search.php
// from the phpBB Group forum software phpBB2 (http://www.phpbb.com)

define('FORUM_ROOT', dirname(__FILE__).'/');
require FORUM_ROOT.'include/common.php';

$section = isset($_GET['section']) ? $_GET['section'] : null;

if ($luna_user['g_read_board'] == '0')
	message($lang['No view'], false, '403 Forbidden');
else if ($luna_user['g_search'] == '0')
	message($lang['No search permission'], false, '403 Forbidden');

require FORUM_ROOT.'include/search_idx.php';

// Figure out what to do :-)
if (isset($_GET['action']) || isset($_GET['search_id']))
{
	$action = (isset($_GET['action'])) ? $_GET['action'] : null;
	$forums = isset($_GET['forums']) ? (is_array($_GET['forums']) ? $_GET['forums'] : array_filter(explode(',', $_GET['forums']))) : (isset($_GET['forum']) ? array($_GET['forum']) : array());
	$sort_dir = (isset($_GET['sort_dir']) && $_GET['sort_dir'] == 'DESC') ? 'DESC' : 'ASC';

	$forums = array_map('intval', $forums);

	// Allow the old action names for backwards compatibility reasons
	if ($action == 'show_user')
		$action = 'show_user_posts';
	else if ($action == 'show_24h')
		$action = 'show_recent';

	// If a search_id was supplied
	if (isset($_GET['search_id']))
	{
		$search_id = intval($_GET['search_id']);
		if ($search_id < 1)
			message($lang['Bad request'], false, '404 Not Found');
	}
	// If it's a regular search (keywords and/or author)
	else if ($action == 'search')
	{
		$keywords = (isset($_GET['keywords'])) ? utf8_strtolower(luna_trim($_GET['keywords'])) : null;
		$author = (isset($_GET['author'])) ? utf8_strtolower(luna_trim($_GET['author'])) : null;

		if (preg_match('%^[\*\%]+$%', $keywords) || (luna_strlen(str_replace(array('*', '%'), '', $keywords)) < FORUM_SEARCH_MIN_WORD && !is_cjk($keywords)))
			$keywords = '';

		if (preg_match('%^[\*\%]+$%', $author) || luna_strlen(str_replace(array('*', '%'), '', $author)) < 2)
			$author = '';

		if (!$keywords && !$author)
			message($lang['No terms']);

		if ($author)
			$author = str_replace('*', '%', $author);

		$show_as = (isset($_GET['show_as']) && $_GET['show_as'] == 'topics') ? 'topics' : 'posts';
		$sort_by = (isset($_GET['sort_by'])) ? intval($_GET['sort_by']) : 0;
		$search_in = (!isset($_GET['search_in']) || $_GET['search_in'] == '0') ? 0 : (($_GET['search_in'] == '1') ? 1 : -1);
	}
	// If it's a user search (by ID)
	else if ($action == 'show_user_posts' || $action == 'show_user_topics' || $action == 'show_subscriptions')
	{
		$user_id = (isset($_GET['user_id'])) ? intval($_GET['user_id']) : $luna_user['id'];
		if ($user_id < 2)
			message($lang['Bad request'], false, '404 Not Found');

		// Subscribed topics can only be viewed by admins, moderators and the users themselves
		if ($action == 'show_subscriptions' && !$luna_user['is_admmod'] && $user_id != $luna_user['id'])
			message($lang['No permission'], false, '403 Forbidden');
	}
	else if ($action == 'show_recent')
		$interval = isset($_GET['value']) ? intval($_GET['value']) : 86400;
	else if ($action == 'show_replies')
	{
		if ($luna_user['is_guest'])
			message($lang['Bad request'], false, '404 Not Found');
	}
	else if ($action != 'show_new' && $action != 'show_unanswered')
		message($lang['Bad request'], false, '404 Not Found');


	// If a valid search_id was supplied we attempt to fetch the search results from the db
	if (isset($search_id))
	{
		$ident = ($luna_user['is_guest']) ? get_remote_address() : $luna_user['username'];

		$result = $db->query('SELECT search_data FROM '.$db->prefix.'search_cache WHERE id='.$search_id.' AND ident=\''.$db->escape($ident).'\'') or error('Unable to fetch search results', __FILE__, __LINE__, $db->error());
		if ($row = $db->fetch_assoc($result))
		{
			$temp = unserialize($row['search_data']);

			$search_ids = unserialize($temp['search_ids']);
			$num_hits = $temp['num_hits'];
			$sort_by = $temp['sort_by'];
			$sort_dir = $temp['sort_dir'];
			$show_as = $temp['show_as'];
			$search_type = $temp['search_type'];

			unset($temp);
		}
		else
			message($lang['No hits']);
	}
	else
	{
		$keyword_results = $author_results = array();

		// Search a specific forum?
		$forum_sql = (!empty($forums) || (empty($forums) && $luna_config['o_search_all_forums'] == '0' && !$luna_user['is_admmod'])) ? ' AND t.forum_id IN ('.implode(',', $forums).')' : '';

		if (!empty($author) || !empty($keywords))
		{
			// Flood protection
			if ($luna_user['last_search'] && (time() - $luna_user['last_search']) < $luna_user['g_search_flood'] && (time() - $luna_user['last_search']) >= 0)
				message(sprintf($lang['Search flood'], $luna_user['g_search_flood'], $luna_user['g_search_flood'] - (time() - $luna_user['last_search'])));

			if (!$luna_user['is_guest'])
				$db->query('UPDATE '.$db->prefix.'users SET last_search='.time().' WHERE id='.$luna_user['id']) or error('Unable to update user', __FILE__, __LINE__, $db->error());
			else
				$db->query('UPDATE '.$db->prefix.'online SET last_search='.time().' WHERE ident=\''.$db->escape(get_remote_address()).'\'' ) or error('Unable to update user', __FILE__, __LINE__, $db->error());

			switch ($sort_by)
			{
				case 1:
					$sort_by_sql = ($show_as == 'topics') ? 't.poster' : 'p.poster';
					$sort_type = SORT_STRING;
					break;

				case 2:
					$sort_by_sql = 't.subject';
					$sort_type = SORT_STRING;
					break;

				case 3:
					$sort_by_sql = 't.forum_id';
					$sort_type = SORT_NUMERIC;
					break;

				case 4:
					$sort_by_sql = 't.last_post';
					$sort_type = SORT_NUMERIC;
					break;

				default:
					$sort_by_sql = ($show_as == 'topics') ? 't.last_post' : 'p.posted';
					$sort_type = SORT_NUMERIC;
					break;
			}

			// If it's a search for keywords
			if ($keywords)
			{
				// split the keywords into words
				$keywords_array = split_words($keywords, false);

				if (empty($keywords_array))
					message($lang['No hits']);

				// Should we search in message body or topic subject specifically?
				$search_in_cond = ($search_in) ? (($search_in > 0) ? ' AND m.subject_match = 0' : ' AND m.subject_match = 1') : '';

				$word_count = 0;
				$match_type = 'and';

				$sort_data = array();
				foreach ($keywords_array as $cur_word)
				{
					switch ($cur_word)
					{
						case 'and':
						case 'or':
						case 'not':
							$match_type = $cur_word;
							break;

						default:
						{
							if (is_cjk($cur_word))
							{
								$where_cond = str_replace('*', '%', $cur_word);
								$where_cond = ($search_in ? (($search_in > 0) ? 'p.message LIKE \'%'.$db->escape($where_cond).'%\'' : 't.subject LIKE \'%'.$db->escape($where_cond).'%\'') : 'p.message LIKE \'%'.$db->escape($where_cond).'%\' OR t.subject LIKE \'%'.$db->escape($where_cond).'%\'');

								$result = $db->query('SELECT p.id AS post_id, p.topic_id, '.$sort_by_sql.' AS sort_by FROM '.$db->prefix.'posts AS p INNER JOIN '.$db->prefix.'topics AS t ON t.id=p.topic_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE ('.$where_cond.') AND (fp.read_forum IS NULL OR fp.read_forum=1)'.$forum_sql, true) or error('Unable to search for posts', __FILE__, __LINE__, $db->error());
							}
							else
								$result = $db->query('SELECT m.post_id, p.topic_id, '.$sort_by_sql.' AS sort_by FROM '.$db->prefix.'search_words AS w INNER JOIN '.$db->prefix.'search_matches AS m ON m.word_id = w.id INNER JOIN '.$db->prefix.'posts AS p ON p.id=m.post_id INNER JOIN '.$db->prefix.'topics AS t ON t.id=p.topic_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE w.word LIKE \''.$db->escape(str_replace('*', '%', $cur_word)).'\''.$search_in_cond.' AND (fp.read_forum IS NULL OR fp.read_forum=1)'.$forum_sql, true) or error('Unable to search for posts', __FILE__, __LINE__, $db->error());

							$row = array();
							while ($temp = $db->fetch_assoc($result))
							{
								$row[$temp['post_id']] = $temp['topic_id'];

								if (!$word_count)
								{
									$keyword_results[$temp['post_id']] = $temp['topic_id'];
									$sort_data[$temp['post_id']] = $temp['sort_by'];
								}
								else if ($match_type == 'or')
								{
									$keyword_results[$temp['post_id']] = $temp['topic_id'];
									$sort_data[$temp['post_id']] = $temp['sort_by'];
								}
								else if ($match_type == 'not')
								{
									unset($keyword_results[$temp['post_id']]);
									unset($sort_data[$temp['post_id']]);
								}
							}

							if ($match_type == 'and' && $word_count)
							{
								foreach ($keyword_results as $post_id => $topic_id)
								{
									if (!isset($row[$post_id]))
									{
										unset($keyword_results[$post_id]);
										unset($sort_data[$post_id]);
									}
								}
							}

							++$word_count;
							$db->free_result($result);

							break;
						}
					}
				}

				// Sort the results - annoyingly array_multisort re-indexes arrays with numeric keys, so we need to split the keys out into a separate array then combine them again after
				$post_ids = array_keys($keyword_results);
				$topic_ids = array_values($keyword_results);

				array_multisort(array_values($sort_data), $sort_dir == 'DESC' ? SORT_DESC : SORT_ASC, $sort_type, $post_ids, $topic_ids);

				// combine the arrays back into a key=>value array (array_combine is PHP5 only unfortunately)
				$num_results = count($keyword_results);
				$keyword_results = array();
				for ($i = 0;$i < $num_results;$i++)
					$keyword_results[$post_ids[$i]] = $topic_ids[$i];

				unset($sort_data, $post_ids, $topic_ids);
			}

			// If it's a search for author name (and that author name isn't Guest)
			if ($author && $author != 'guest' && $author != utf8_strtolower($lang['Guest']))
			{
				switch ($db_type)
				{
					case 'pgsql':
						$result = $db->query('SELECT id FROM '.$db->prefix.'users WHERE username ILIKE \''.$db->escape($author).'\'') or error('Unable to fetch users', __FILE__, __LINE__, $db->error());
						break;

					default:
						$result = $db->query('SELECT id FROM '.$db->prefix.'users WHERE username LIKE \''.$db->escape($author).'\'') or error('Unable to fetch users', __FILE__, __LINE__, $db->error());
						break;
				}

				if ($db->num_rows($result))
				{
					$user_ids = array();
					while ($row = $db->fetch_row($result))
						$user_ids[] = $row[0];

					$result = $db->query('SELECT p.id AS post_id, p.topic_id FROM '.$db->prefix.'posts AS p INNER JOIN '.$db->prefix.'topics AS t ON t.id=p.topic_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND p.poster_id IN('.implode(',', $user_ids).')'.$forum_sql.' ORDER BY '.$sort_by_sql.' '.$sort_dir) or error('Unable to fetch matched posts list', __FILE__, __LINE__, $db->error());
					while ($temp = $db->fetch_assoc($result))
						$author_results[$temp['post_id']] = $temp['topic_id'];

					$db->free_result($result);
				}
			}

			// If we searched for both keywords and author name we want the intersection between the results
			if ($author && $keywords)
			{
				$search_ids = array_intersect_assoc($keyword_results, $author_results);
				$search_type = array('both', array($keywords, luna_trim($_GET['author'])), implode(',', $forums), $search_in);
			}
			else if ($keywords)
			{
				$search_ids = $keyword_results;
				$search_type = array('keywords', $keywords, implode(',', $forums), $search_in);
			}
			else
			{
				$search_ids = $author_results;
				$search_type = array('author', luna_trim($_GET['author']), implode(',', $forums), $search_in);
			}

			unset($keyword_results, $author_results);

			if ($show_as == 'topics')
				$search_ids = array_values($search_ids);
			else
				$search_ids = array_keys($search_ids);

			$search_ids = array_unique($search_ids);

			$num_hits = count($search_ids);
			if (!$num_hits)
				message($lang['No hits']);
		}
		else if ($action == 'show_new' || $action == 'show_recent' || $action == 'show_replies' || $action == 'show_user_posts' || $action == 'show_user_topics' || $action == 'show_subscriptions' || $action == 'show_unanswered')
		{
			$search_type = array('action', $action);
			$show_as = 'topics';
			// We want to sort things after last post
			$sort_by = 0;
			$sort_dir = 'DESC';

			// If it's a search for new posts since last visit
			if ($action == 'show_new')
			{
				if ($luna_user['is_guest'])
					message($lang['No permission'], false, '403 Forbidden');

				$result = $db->query('SELECT t.id FROM '.$db->prefix.'topics AS t LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND t.last_post>'.$luna_user['last_visit'].' AND t.moved_to IS NULL'.(isset($_GET['fid']) ? ' AND t.forum_id='.intval($_GET['fid']) : '').' ORDER BY t.last_post DESC') or error('Unable to fetch topic list', __FILE__, __LINE__, $db->error());
				$num_hits = $db->num_rows($result);

				if (!$num_hits)
					message($lang['No new posts']);
			}
			// If it's a search for recent posts (in a certain time interval)
			else if ($action == 'show_recent')
			{
				$result = $db->query('SELECT t.id FROM '.$db->prefix.'topics AS t LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND t.last_post>'.(time() - $interval).' AND t.moved_to IS NULL'.(isset($_GET['fid']) ? ' AND t.forum_id='.intval($_GET['fid']) : '').' ORDER BY t.last_post DESC') or error('Unable to fetch topic list', __FILE__, __LINE__, $db->error());
				$num_hits = $db->num_rows($result);

				if (!$num_hits)
					message($lang['No recent posts']);
			}
			// If it's a search for topics in which the user has posted
			else if ($action == 'show_replies')
			{
				$result = $db->query('SELECT t.id FROM '.$db->prefix.'topics AS t INNER JOIN '.$db->prefix.'posts AS p ON t.id=p.topic_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND p.poster_id='.$luna_user['id'].' GROUP BY t.id'.($db_type == 'pgsql' ? ', t.last_post' : '').' ORDER BY t.last_post DESC') or error('Unable to fetch topic list', __FILE__, __LINE__, $db->error());
				$num_hits = $db->num_rows($result);

				if (!$num_hits)
					message($lang['No user posts']);
			}
			// If it's a search for posts by a specific user ID
			else if ($action == 'show_user_posts')
			{
				$show_as = 'posts';

				$result = $db->query('SELECT p.id FROM '.$db->prefix.'posts AS p INNER JOIN '.$db->prefix.'topics AS t ON p.topic_id=t.id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND p.poster_id='.$user_id.' ORDER BY p.posted DESC') or error('Unable to fetch user posts', __FILE__, __LINE__, $db->error());
				$num_hits = $db->num_rows($result);

				if (!$num_hits)
					message($lang['No user posts']);

				// Pass on the user ID so that we can later know whose posts we're searching for
				$search_type[2] = $user_id;
			}
			// If it's a search for topics by a specific user ID
			else if ($action == 'show_user_topics')
			{
				$result = $db->query('SELECT t.id FROM '.$db->prefix.'topics AS t INNER JOIN '.$db->prefix.'posts AS p ON t.first_post_id=p.id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND p.poster_id='.$user_id.' ORDER BY t.last_post DESC') or error('Unable to fetch user topics', __FILE__, __LINE__, $db->error());
				$num_hits = $db->num_rows($result);

				if (!$num_hits)
					message($lang['No user topics']);

				// Pass on the user ID so that we can later know whose topics we're searching for
				$search_type[2] = $user_id;
			}
			// If it's a search for subscribed topics
			else if ($action == 'show_subscriptions')
			{
				if ($luna_user['is_guest'])
					message($lang['Bad request'], false, '404 Not Found');

				$result = $db->query('SELECT t.id FROM '.$db->prefix.'topics AS t INNER JOIN '.$db->prefix.'topic_subscriptions AS s ON (t.id=s.topic_id AND s.user_id='.$user_id.') LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) ORDER BY t.last_post DESC') or error('Unable to fetch topic list', __FILE__, __LINE__, $db->error());
				$num_hits = $db->num_rows($result);

				if (!$num_hits)
					message($lang['No subscriptions']);

				// Pass on user ID so that we can later know whose subscriptions we're searching for
				$search_type[2] = $user_id;
			}
			// If it's a search for unanswered posts
			else
			{
				$result = $db->query('SELECT t.id FROM '.$db->prefix.'topics AS t LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND t.num_replies=0 AND t.moved_to IS NULL ORDER BY t.last_post DESC') or error('Unable to fetch topic list', __FILE__, __LINE__, $db->error());
				$num_hits = $db->num_rows($result);

				if (!$num_hits)
					message($lang['No unanswered']);
			}

			$search_ids = array();
			while ($row = $db->fetch_row($result))
				$search_ids[] = $row[0];

			$db->free_result($result);
		}
		else
			message($lang['Bad request'], false, '404 Not Found');


		// Prune "old" search results
		$old_searches = array();
		$result = $db->query('SELECT ident FROM '.$db->prefix.'online') or error('Unable to fetch online list', __FILE__, __LINE__, $db->error());

		if ($db->num_rows($result))
		{
			while ($row = $db->fetch_row($result))
				$old_searches[] = '\''.$db->escape($row[0]).'\'';

			$db->query('DELETE FROM '.$db->prefix.'search_cache WHERE ident NOT IN('.implode(',', $old_searches).')') or error('Unable to delete search results', __FILE__, __LINE__, $db->error());
		}

		// Fill an array with our results and search properties
		$temp = serialize(array(
			'search_ids'		=> serialize($search_ids),
			'num_hits'			=> $num_hits,
			'sort_by'			=> $sort_by,
			'sort_dir'			=> $sort_dir,
			'show_as'			=> $show_as,
			'search_type'		=> $search_type
		));
		$search_id = mt_rand(1, 2147483647);

		$ident = ($luna_user['is_guest']) ? get_remote_address() : $luna_user['username'];

		$db->query('INSERT INTO '.$db->prefix.'search_cache (id, ident, search_data) VALUES('.$search_id.', \''.$db->escape($ident).'\', \''.$db->escape($temp).'\')') or error('Unable to insert search results', __FILE__, __LINE__, $db->error());

		if ($search_type[0] != 'action')
		{
			$db->end_transaction();
			$db->close();

			// Redirect the user to the cached result page
			header('Location: search.php?search_id='.$search_id);
			exit;
		}
	}

	$forum_actions = array();

	// If we're on the new posts search, display a "mark all as read" link
	if (!$luna_user['is_guest'] && $search_type[0] == 'action' && $search_type[1] == 'show_new')
		$forum_actions[] = '<a href="misc.php?action=markread">'.$lang['Mark all as read'].'</a>';

	// Fetch results to display
	if (!empty($search_ids))
	{
		switch ($sort_by)
		{
			case 1:
				$sort_by_sql = ($show_as == 'topics') ? 't.poster' : 'p.poster';
				break;

			case 2:
				$sort_by_sql = 't.subject';
				break;

			case 3:
				$sort_by_sql = 't.forum_id';
				break;

			default:
				$sort_by_sql = ($show_as == 'topics') ? 't.last_post' : 'p.posted';
				break;
		}

		// Determine the topic or post offset (based on $_GET['p'])
		$per_page = ($show_as == 'posts') ? $luna_user['disp_posts'] : $luna_user['disp_topics'];
		$num_pages = ceil($num_hits / $per_page);

		$p = (!isset($_GET['p']) || $_GET['p'] <= 1 || $_GET['p'] > $num_pages) ? 1 : intval($_GET['p']);
		$start_from = $per_page * ($p - 1);

		// Generate paging links
		$paging_links = paginate($num_pages, $p, 'search.php?search_id='.$search_id);

		// throw away the first $start_from of $search_ids, only keep the top $per_page of $search_ids
		$search_ids = array_slice($search_ids, $start_from, $per_page);

		// Run the query and fetch the results
		if ($show_as == 'posts')
			$result = $db->query('SELECT p.id AS pid, p.poster AS pposter, p.posted AS pposted, p.poster_id, p.message, p.hide_smilies, t.id AS tid, t.poster, t.subject, t.first_post_id, t.last_post, t.last_post_id, t.last_poster, t.num_replies, t.forum_id, f.forum_name FROM '.$db->prefix.'posts AS p INNER JOIN '.$db->prefix.'topics AS t ON t.id=p.topic_id INNER JOIN '.$db->prefix.'forums AS f ON f.id=t.forum_id WHERE p.id IN('.implode(',', $search_ids).') ORDER BY '.$sort_by_sql.' '.$sort_dir) or error('Unable to fetch search results', __FILE__, __LINE__, $db->error());
		else
			$result = $db->query('SELECT t.id AS tid, t.poster, t.subject, t.last_post, t.last_post_id, t.last_poster, t.num_replies, t.closed, t.sticky, t.forum_id, f.forum_name FROM '.$db->prefix.'topics AS t INNER JOIN '.$db->prefix.'forums AS f ON f.id=t.forum_id WHERE t.id IN('.implode(',', $search_ids).') ORDER BY '.$sort_by_sql.' '.$sort_dir) or error('Unable to fetch search results', __FILE__, __LINE__, $db->error());

		$search_set = array();
		while ($row = $db->fetch_assoc($result))
			$search_set[] = $row;

		$crumbs_text = array();
		$crumbs_text['show_as'] = $lang['Search'];

		if ($search_type[0] == 'action')
		{
			if ($search_type[1] == 'show_user_topics')
				$crumbs_text['search_type'] = '<a href="search.php?action=show_user_topics&amp;user_id='.$search_type[2].'">'.sprintf($lang['Quick search show_user_topics'], luna_htmlspecialchars($search_set[0]['poster'])).'</a>';
			else if ($search_type[1] == 'show_user_posts')
				$crumbs_text['search_type'] = '<a href="search.php?action=show_user_posts&amp;user_id='.$search_type[2].'">'.sprintf($lang['Quick search show_user_posts'], luna_htmlspecialchars($search_set[0]['pposter'])).'</a>';
			else if ($search_type[1] == 'show_subscriptions')
			{
				// Fetch username of subscriber
				$subscriber_id = $search_type[2];
				$result = $db->query('SELECT username FROM '.$db->prefix.'users WHERE id='.$subscriber_id) or error('Unable to fetch username of subscriber', __FILE__, __LINE__, $db->error());

				if ($db->num_rows($result))
					$subscriber_name = $db->result($result);
				else
					message($lang['Bad request'], false, '404 Not Found');

				$crumbs_text['search_type'] = '<a href="search.php?action=show_subscriptions&amp;user_id='.$subscriber_id.'">'.sprintf($lang['Quick search show_subscriptions'], luna_htmlspecialchars($subscriber_name)).'</a>';
			}
			else
				$crumbs_text['search_type'] = '<a href="search.php?action='.$search_type[1].'">'.$lang['Quick search '.$search_type[1]].'</a>';
		}
		else
		{
			$keywords = $author = '';

			if ($search_type[0] == 'both')
			{
				list ($keywords, $author) = $search_type[1];
				$crumbs_text['search_type'] = sprintf($lang['By both show as '.$show_as], luna_htmlspecialchars($keywords), luna_htmlspecialchars($author));
			}
			else if ($search_type[0] == 'keywords')
			{
				$keywords = $search_type[1];
				$crumbs_text['search_type'] = sprintf($lang['By keywords show as '.$show_as], luna_htmlspecialchars($keywords));
			}
			else if ($search_type[0] == 'author')
			{
				$author = $search_type[1];
				$crumbs_text['search_type'] = sprintf($lang['By user show as '.$show_as], luna_htmlspecialchars($author));
			}

			$crumbs_text['search_type'] = '<a href="search.php?action=search&amp;keywords='.urlencode($keywords).'&amp;author='.urlencode($author).'&amp;forums='.$search_type[2].'&amp;search_in='.$search_type[3].'&amp;sort_by='.$sort_by.'&amp;sort_dir='.$sort_dir.'&amp;show_as='.$show_as.'">'.$crumbs_text['search_type'].'</a>';
		}

		$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Search results']);
		define('FORUM_ACTIVE_PAGE', 'search');
		require FORUM_ROOT.'header.php';

?>
<div class="linkst">
    <ol class="breadcrumb">
        <li><a href="index.php"><?php echo $lang['Index'] ?></a></li>
        <li><a href="search.php"><?php echo $crumbs_text['show_as'] ?></a></li>
        <li class="active"><?php echo $crumbs_text['search_type'] ?></li>
    </ol>
    <ul class="pagination">
        <?php echo $paging_links ?>
    </ul>
</div>

<?php

		if ($show_as == 'topics')
		{
			$topic_count = 0;

?>
    <div class="forum-box">
        <div class="row forum-header">
			<div class="col-xs-6"><?php echo $lang['Topic'] ?></div>
            <div class="col-xs-2 hidden-xs"><?php echo $lang['Forum'] ?></div>
			<div class="col-xs-1 hidden-xs"><p class="text-center"><?php echo $lang['Replies forum'] ?></p></div>
			<div class="col-xs-3 col-search"><?php echo $lang['Last post'] ?></div>
        </div>
<?php

		}
		else if ($show_as == 'posts')
		{
			require FORUM_ROOT.'include/parser.php';

			$post_count = 0;
		}

		// Get topic/forum tracking data
		if (!$luna_user['is_guest'])
			$tracked_topics = get_tracked_topics();

		foreach ($search_set as $cur_search)
		{
			$forum = '<a href="viewforum.php?id='.$cur_search['forum_id'].'">'.luna_htmlspecialchars($cur_search['forum_name']).'</a>';

			if ($luna_config['o_censoring'] == '1')
				$cur_search['subject'] = censor_words($cur_search['subject']);

			if ($show_as == 'posts')
			{
				++$post_count;
				$icon_type = 'icon';

				if (!$luna_user['is_guest'] && $cur_search['last_post'] > $luna_user['last_visit'] && (!isset($tracked_topics['topics'][$cur_search['tid']]) || $tracked_topics['topics'][$cur_search['tid']] < $cur_search['last_post']) && (!isset($tracked_topics['forums'][$cur_search['forum_id']]) || $tracked_topics['forums'][$cur_search['forum_id']] < $cur_search['last_post']))
				{
					$item_status = 'inew';
					$icon_type = 'icon icon-new';
					$icon_text = $lang['New icon'];
				}
				else
				{
					$item_status = '';
					$icon_text = '<!-- -->';
				}

				if ($luna_config['o_censoring'] == '1')
					$cur_search['message'] = censor_words($cur_search['message']);

				$message = parse_message($cur_search['message'], $cur_search['hide_smilies']);
				$pposter = luna_htmlspecialchars($cur_search['pposter']);

				if ($cur_search['poster_id'] > 1)
				{
					if ($luna_user['g_view_users'] == '1')
						$pposter = '<strong><a href="profile.php?id='.$cur_search['poster_id'].'">'.$pposter.'</a></strong>';
					else
						$pposter = '<strong>'.$pposter.'</strong>';
				}


?>
<div class="blockpost<?php echo ($post_count % 2 == 0) ? ' roweven' : ' rowodd' ?><?php if ($cur_search['pid'] == $cur_search['first_post_id']) echo ' firstpost' ?><?php if ($post_count == 1) echo ' blockpost1' ?><?php if ($item_status != '') echo ' '.$item_status ?>">
    <table class="table">
    	<tr>
            <td colspan="2" class="postbreadcrumb" style="padding-bottom: 0px;">
                <ol class="breadcrumb">
                    <li><?php if ($cur_search['pid'] != $cur_search['first_post_id']) echo $lang['Re'].' ' ?><?php echo $forum ?></li>
                    <li><a href="viewtopic.php?id=<?php echo $cur_search['tid'] ?>"><?php echo luna_htmlspecialchars($cur_search['subject']) ?></a></li>
                    <li><a href="viewtopic.php?pid=<?php echo $cur_search['pid'].'#p'.$cur_search['pid'] ?>"><?php echo format_time($cur_search['pposted']) ?></a></li>
                </ol>
            </td>
        </tr>
        <tr>
            <td class="col-lg-2 user-data">
                <?php echo $pposter ?><br />
				<?php if ($cur_search['pid'] == $cur_search['first_post_id']) : ?>                    
                    <?php echo $lang['Replies'].' '.forum_number_format($cur_search['num_replies']) ?>
                <?php endif; ?>
            </td>
            <td class="col-lg-10">
				<?php echo $message."\n" ?>
            </td>
        </tr>
        <tr>
            <td colspan="2" class="postfooter" style="padding-bottom: 0;">
                <p class="pull-right">
					<a class="btn btn-small btn-primary" href="viewtopic.php?id=<?php echo $cur_search['tid'] ?>"><?php echo $lang['Go to topic'] ?></a>
					<a class="btn btn-small btn-primary" href="viewtopic.php?pid=<?php echo $cur_search['pid'].'#p'.$cur_search['pid'] ?>"><?php echo $lang['Go to post'] ?></a>
                </p>
            </td>
        </tr>
	</table>
</div>
<?php

			}
			else
			{
				++$topic_count;
				$status_text = array();
				$item_status = ($topic_count % 2 == 0) ? 'roweven' : 'rowodd';
				$icon_type = 'icon';

				$subject = '<a href="viewtopic.php?id='.$cur_search['tid'].'">'.luna_htmlspecialchars($cur_search['subject']).'</a> <span class="byuser">'.$lang['by'].' '.luna_htmlspecialchars($cur_search['poster']).'</span>';

				if ($cur_search['sticky'] == '1')
				{
					$item_status .= ' isticky';
					$status_text[] = '<span class="label label-success">'.$lang['Sticky'].'</span>';
				}

				if ($cur_search['closed'] != '0')
				{
					$status_text[] = '<span class="label label-danger">'.$lang['Closed'].'</span>';
					$item_status .= ' iclosed';
				}

				if (!$luna_user['is_guest'] && $cur_search['last_post'] > $luna_user['last_visit'] && (!isset($tracked_topics['topics'][$cur_search['tid']]) || $tracked_topics['topics'][$cur_search['tid']] < $cur_search['last_post']) && (!isset($tracked_topics['forums'][$cur_search['forum_id']]) || $tracked_topics['forums'][$cur_search['forum_id']] < $cur_search['last_post']))
				{
					$item_status .= ' inew';
					$icon_type = 'icon icon-new';
					$subject = '<strong>'.$subject.'</strong>';
					$subject_new_posts = '<span class="newtext">[ <a href="viewtopic.php?id='.$cur_search['tid'].'&amp;action=new" title="'.$lang['New posts info'].'">'.$lang['New posts'].'</a> ]</span>';
				}
				else
					$subject_new_posts = null;

				// Insert the status text before the subject
				$subject = implode(' ', $status_text).' '.$subject;

				$num_pages_topic = ceil(($cur_search['num_replies'] + 1) / $luna_user['disp_posts']);

				if ($num_pages_topic > 1)
					$subject_multipage = '<span class="pagestext">'.simple_paginate($num_pages_topic, -1, 'viewtopic.php?id='.$cur_search['tid']).'</span>';
				else
					$subject_multipage = null;

				// Should we show the "New posts" and/or the multipage links?
				if (!empty($subject_new_posts) || !empty($subject_multipage))
				{
					$subject .= !empty($subject_new_posts) ? ' '.$subject_new_posts : '';
					$subject .= !empty($subject_multipage) ? ' '.$subject_multipage : '';
				}

?>
        <div class="row topic-row <?php echo $item_status ?>">
            <div class="col-xs-6">
                <div class="<?php echo $icon_type ?>"><div class="nosize"><?php echo forum_number_format($topic_count + $start_from) ?></div></div>
                <div class="tclcon">
					<?php echo $subject."\n" ?>
                </div>
            </div>
            <div class="col-xs-2 hidden-xs"><?php echo $forum ?></div>
            <div class="col-xs-1 hidden-xs"><p class="text-center"><?php echo forum_number_format($cur_search['num_replies']) ?></p></div>
            <div class="col-xs-3 col-search"><?php echo '<a href="viewtopic.php?pid='.$cur_search['last_post_id'].'#p'.$cur_search['last_post_id'].'">'.format_time($cur_search['last_post']).'</a> <span class="byuser">'.$lang['by'].' '.luna_htmlspecialchars($cur_search['last_poster']) ?></span></div>
        </div>
<?php

			}
		}

		if ($show_as == 'topics')
			echo "\t\t\t".'</div>'."\n\n";

?>
<div class="<?php echo ($show_as == 'topics') ? 'linksb' : 'postlinksb'; ?>">
    <ul class="pagination pagination-fix">
        <?php echo $paging_links ?>
    </ul>
    <ol class="breadcrumb">
        <li><a href="index.php"><?php echo $lang['Index'] ?></a></li>
        <li><a href="search.php"><?php echo $crumbs_text['show_as'] ?></a></li>
        <li class="active"><?php echo $crumbs_text['search_type'] ?></li>
    </ol>
    <?php echo (!empty($forum_actions) ? "\t\t".'<p class="subscribelink clearb">'.implode(' - ', $forum_actions).'</p>'."\n" : '') ?>
</div>
<?php

		require FORUM_ROOT.'footer.php';
	}
	else
		message($lang['No hits']);
}


	if (!$section || $section == 'simple')
	{

	$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Search']);
	$focus_element = array('search', 'keywords');
	define('FORUM_ACTIVE_PAGE', 'search');
	require FORUM_ROOT.'header.php';

?>
<form id="search" method="get" action="search.php?section=simple">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><?php echo $lang['Search criteria legend'] ?></h3>
        </div>
        <div class="panel-body">
            <fieldset>
                <input type="hidden" name="action" value="search" />
            	<div class="input-group"><input class="form-control" type="text" name="keywords" maxlength="100" /><span class="input-group-btn"><input class="btn btn-primary" type="submit" name="search" value="<?php echo $lang['Search'] ?>" accesskey="s" /></span></div>
                <a class="hidden-xs" href="search.php?section=advanced"><?php echo $lang['Advanced search'] ?></a>
            </fieldset>
        </div>
    </div>
</form>
<?php

	require FORUM_ROOT.'footer.php';

	} else {
	
	$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Search']);
	$focus_element = array('search', 'keywords');
	define('FORUM_ACTIVE_PAGE', 'search');
	require FORUM_ROOT.'header.php';

?>
<form id="search" method="get" action="search.php?section=advanced">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><?php echo $lang['Search criteria legend'] ?></h3>
        </div>
        <div class="panel-body">
            <fieldset>
                <input type="hidden"  name="action" value="search" />
            	<table>
                	<thead>
                    	<tr>
                        	<th><?php echo $lang['Keyword search'] ?></th>
                            <th><?php echo $lang['Author search'] ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    	<tr>
                        	<td><input class="form-control" type="text" name="keywords" maxlength="100" /></td>
                        	<td><input class="form-control" id="author" type="text" name="author" maxlength="25" /></td>
                        </tr>
                    </tbody>
                </table>
                <p class="help-block"><?php echo $lang['Search info'] ?></p>
            </fieldset>
            <fieldset>
            	<div class="row">
<?php

$result = $db->query('SELECT c.id AS cid, c.cat_name, f.id AS fid, f.forum_name, f.redirect_url FROM '.$db->prefix.'categories AS c INNER JOIN '.$db->prefix.'forums AS f ON c.id=f.cat_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=f.id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND f.redirect_url IS NULL ORDER BY c.disp_position, c.id, f.disp_position', true) or error('Unable to fetch category/forum list', __FILE__, __LINE__, $db->error());

// We either show a list of forums of which multiple can be selected
if ($luna_config['o_search_all_forums'] == '1' || $luna_user['is_admmod'])
{
	echo "\t\t\t\t\t\t".'<div class="col-xs-4"><div class="conl multiselect"><b>'.$lang['Forum search'].'</b>'."\n";
	echo "\t\t\t\t\t\t".'<br />'."\n";
	echo "\t\t\t\t\t\t".'<div>'."\n";

	$cur_category = 0;
	while ($cur_forum = $db->fetch_assoc($result))
	{
		if ($cur_forum['cid'] != $cur_category) // A new category since last iteration?
		{
			if ($cur_category)
			{
				echo "\t\t\t\t\t\t\t\t".'</div>'."\n"; 
				echo "\t\t\t\t\t\t\t".'</fieldset>'."\n";
			}
			echo "\t\t\t\t\t\t\t".'<fieldset><h3 class="forum-list"><span>'.luna_htmlspecialchars($cur_forum['cat_name']).'</span></h3>'."\n";
			echo "\t\t\t\t\t\t\t\t".'<div class="rbox">'; 
			$cur_category = $cur_forum['cid'];
		}
		echo "\t\t\t\t\t\t\t\t".'<input type="checkbox" name="forums[]" id="forum-'.$cur_forum['fid'].'" value="'.$cur_forum['fid'].'" /> '.luna_htmlspecialchars($cur_forum['forum_name']).'<br />'."\n";
 	}
	
	if ($cur_category)
	{
		echo "\t\t\t\t\t\t\t\t".'</div>'."\n";
		echo "\t\t\t\t\t\t\t".'</fieldset>'."\n";
	}
	
	echo "\t\t\t\t\t\t".'</div>'."\n";
	echo "\t\t\t\t\t\t".'</div></div>'."\n";
}
// ... or a simple select list for one forum only
else
{
	echo "\t\t\t\t\t\t".'<div class="col-xs-4"><label class="conl">'.$lang['Forum search']."\n";
	echo "\t\t\t\t\t\t".'<br />'."\n";
	echo "\t\t\t\t\t\t".'<select id="forum" name="forum">'."\n";

	$cur_category = 0;
	while ($cur_forum = $db->fetch_assoc($result))
	{
		if ($cur_forum['cid'] != $cur_category) // A new category since last iteration?
		{
			if ($cur_category)
				echo "\t\t\t\t\t\t\t".'</optgroup>'."\n";

			echo "\t\t\t\t\t\t\t".'<optgroup label="'.luna_htmlspecialchars($cur_forum['cat_name']).'">'."\n";
			$cur_category = $cur_forum['cid'];
		}

		echo "\t\t\t\t\t\t\t\t".'<option value="'.$cur_forum['fid'].'">'.($cur_forum['parent_forum_id'] == 0 ? '' : '&nbsp;&nbsp;&nbsp;').luna_htmlspecialchars($cur_forum['forum_name']).'</option>'."\n";
	}

	echo "\t\t\t\t\t\t\t".'</optgroup>'."\n";
	echo "\t\t\t\t\t\t".'</select>'."\n";
	echo "\t\t\t\t\t\t".'<br /></label></div>'."\n";
}

?>
                    <div class="col-xs-8">
                        <label class="conl"><?php echo $lang['Search in']."\n" ?>
                        <br /><select class="form-control" id="search_in" name="search_in">
                            <option value="0"><?php echo $lang['Message and subject'] ?></option>
                            <option value="1"><?php echo $lang['Message only'] ?></option>
                            <option value="-1"><?php echo $lang['Topic only'] ?></option>
                        </select>
                        </label>
                        <table>
                            <thead>
                                <tr>
                                    <th><?php echo $lang['Sort by'] ?></th>
                                    <th><?php echo $lang['Sort order'] ?></th>
                                    <th><?php echo $lang['Show as'] ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <select class="form-control" name="sort_by">
                                            <option value="0"><?php echo $lang['Sort by post time'] ?></option>
                                            <option value="1"><?php echo $lang['Sort by author'] ?></option>
                                            <option value="2"><?php echo $lang['Sort by subject'] ?></option>
                                            <option value="3"><?php echo $lang['Sort by forum'] ?></option>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="form-control" name="sort_dir">
                                            <option value="DESC"><?php echo $lang['Descending'] ?></option>
                                            <option value="ASC"><?php echo $lang['Ascending'] ?></option>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="form-control" name="show_as">
                                            <option value="topics"><?php echo $lang['Show as topics'] ?></option>
                                            <option value="posts"><?php echo $lang['Show as posts'] ?></option>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="help-block"><?php echo $lang['Search results info'] ?></p>
                    </div>
                </div>
            </fieldset>
        </div>
        <div class="panel-footer">
            <input class="btn btn-primary" type="submit" name="search" value="<?php echo $lang['Search'] ?>" accesskey="s" />
        </div>
    </div>
</form>
<?php

	require FORUM_ROOT.'footer.php';
	}
