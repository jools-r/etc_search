<?php
// TXP 4.6 tag registration
if (class_exists('\Textpattern\Tag\Registry')) {
	Txp::get('\Textpattern\Tag\Registry')
		->register('etc_search')
		->register('etc_search_results')
//		->register('etc_search_results', 'article')
		->register('etc_search_query')
		->register('etc_search_result_excerpt')
//		->register('etc_search_result_excerpt', 'search_result_excerpt')
		->register('etc_search_result_count')
//		->register('etc_search_result_count', 'search_result_count')
	;
}

if(txpinterface == 'admin') {
	add_privs('etc_search', '1,2');
	register_tab("extensions", "etc_search", gTxt('etc_search'));
	register_callback("etc_search_tab", "etc_search");
	add_privs('plugin_prefs.etc_search','1,2');
	register_callback('etc_search_tab', 'plugin_prefs.etc_search');
	register_callback('etc_search_install', 'plugin_lifecycle.etc_search');
	register_callback('etc_search_pophelp', 'admin_help', 'etc_search_logical_operators');
}
elseif(gps('etc_search') !== '') {
	register_callback('etc_search_term', 'pretext_end');
	if(ps('etc_search') !== '') register_callback('etc_search_callback', 'log_hit');
}

function etc_search_install($event='', $step=''){
	if($step == 'deleted') {
		safe_delete('txp_prefs', "name LIKE 'etc\_search\_%'");
		safe_delete("txp_form", "name = 'etc_search_results'");
		safe_query('DROP TABLE IF EXISTS '.safe_pfx('etc_search'));
		return;
	}
	if($step == 'enabled') {
		if(!get_pref('etc_search_hash'))
			set_pref('etc_search_hash', uniqid('', true), 'etc_search', PREF_HIDDEN);
		if(!get_pref('etc_search_ops'))
			set_pref('etc_search_ops', '{"NOT":"-","AND":" ","OR":","}', 'etc_search', PREF_HIDDEN);
		if(!safe_count('txp_form', "name = 'etc_search_results' AND type = 'article'"))
			safe_insert("txp_form", "name = 'etc_search_results', type = 'article', Form = '<txp:permlink><txp:title /></txp:permlink>'");

		safe_create('etc_search', "
			id		INT(4)			NOT NULL AUTO_INCREMENT,
			query	VARCHAR(255)	NOT NULL,
			form1	VARCHAR(64)		NOT NULL,
			form2	VARCHAR(64)		NOT NULL,
			thing1	TEXT			NOT NULL,
			thing2	TEXT			NOT NULL,
			type	ENUM('article','image','file','link','category','section','custom') NOT NULL,
			PRIMARY KEY (id)
		");

		safe_alter('etc_search', "MODIFY `type` enum('article','image','file','link','category','section','custom') NOT NULL");
		safe_update('etc_search', "type = 'custom'", "type = ''");

		return;
	}
}

function etc_search_tab($event, $step, $message='') {
	global $prefs;
	$id = intval(gps('id'));
	if($step && bouncer($step, array('save'=>true, 'ops'=>true))) if($step == 'save') switch(gps('save')) {
		case 'Save' : safe_upsert('etc_search',
			"query='".doSlash(gps('query'))."',
			form1='".doSlash(gps('form1'))."',
			form2='".doSlash(gps('form2'))."',			thing1='".doSlash(gps('thing1'))."',
			thing2='".doSlash(gps('thing2'))."',
			type='".doSlash(gps('type'))."'",
			"id=".$id);
			$ops = gps('etc_ops_'.$id);
			if(!$id) $id = intval(getThing('SELECT LAST_INSERT_ID()'));
			if($ops === '') {safe_delete('txp_prefs', "name='etc_search_ops_$id'"); $prefs['etc_search_ops_'.$id] = '';}
			else set_pref('etc_search_ops_'.$id, $prefs['etc_search_ops_'.$id] = $ops, 'etc_search', PREF_HIDDEN);
		break;
		case 'Delete' : safe_delete('etc_search', "id=$id"); safe_delete('txp_prefs', "name='etc_search_ops_$id'");
		break;
	} elseif($step == 'ops') {
			set_pref('etc_search_ops', $prefs['etc_search_ops'] = gps('etc_ops'), 'etc_search', PREF_HIDDEN);
		}

	$ops = get_pref('etc_search_ops');

	$rs = safe_rows('*', 'etc_search', '1');
	pagetop(gTxt('etc_search'), $message);

	$style = '.txp-form-field-label:has(+ .txp-form-field-instructions),.txp-form-field-instructions {display:inline;}';
	if (class_exists('\Textpattern\UI\Style')) {
		echo Txp::get('\Textpattern\UI\Style')->setContent($style);
	} else {
		echo '<style>' . $style. '</style>';
	}

	echo tag(
		hed(gTxt('etc_search_pane'), 1, array('class' => 'txp-heading')),
		'div', array('class' => 'txp-layout-1col')
	);

	$etc_ops_form = form(
		inputLabel(
			'etc_ops',
			fInput('text', 'etc_ops', $ops,'','','', 30, 0, 'etc_ops'),
			gTxt('etc_search_logical_operators_global'),
			array('etc_search_logical_operators', 'instructions_etc_search_logical_operators')
		) .
		eInput('etc_search') .
		sInput('ops') .
		graf(
			fInput('submit', 'save', gTxt('save'), 'publish')
		),
		'', '', 'post', 'etc-search-form', '', 'etc-logic'
	);

	echo wrapRegion('etc-ops-group', $etc_ops_form, 'etc-ops-group-content', gTxt('etc_search_settings'), 'etc_search_global-ops');

	echo hed(gTxt('etc_search_forms'), 2).n.'<div class="summary-details">'.n;
	foreach($rs as $row) echo etc_search_form(doSpecial($row));
	echo etc_search_form(array('id'=>0, 'query'=>'', 'form1'=>'', 'form2'=>'', 'thing1'=>'', 'thing2'=>'', 'type'=>'')).n.'</div>';
}

function etc_search_form($row) {
	global $prefs;
	if(!($search_fields = $prefs['searchable_article_fields'])) $search_fields = 'Title,Body';
	$context = array();

	$out = [];
	$out[] = tag_start('div', array('class' => 'txp-layout'));

		$out[] = tag(
				inputLabel('etc-'.$row['id'].'-type',
				selectInput('type',
					array(
						'article' => gTxt('article_context'),
						'image' => gTxt('image_context'),
						'file' => gTxt('file_context'),
						'link' => gTxt('link_context'),
						'category' => gTxt('category'),
						'section' => gTxt('section'),
						'custom' => gTxt('custom_context')
					),
					$row['type'],
					false,
					'',
					'etc-'.$row['id'].'-type'
				),
				gTxt('etc_search_context')
			),
			'div',
			array('class' => 'txp-layout-2col')
		) . n;

		$out[] = tag(
			inputLabel('etc-' . $row['id'] . '-ops',
				fInput('text', 'etc_ops_' . $row['id'], ($row['id'] ? doSpecial(get_pref('etc_search_ops_' . $row['id'])) : ''),'','','', 30, 0, 'etc-'.$row['id'].'-ops', false, false, get_pref('etc_search_ops')),
				gTxt('etc_search_logical_operators'),
				array('etc_search_logical_operators', 'instructions_etc_search_logical_operators')
			),
			'div',
			array('class' => 'txp-layout-2col')
		) . n;

	$out[] = tag_end('div');

	$out[] = inputLabel('etc-' . $row['id'] . '-query',
		fInput('text', 'query', htmlspecialchars_decode($row['query']), '', '', '', INPUT_XLARGE, 0, 'etc-'.$row['id'].'-query', false, false, '{' . $search_fields . '}'),
		gTxt('etc_search_query')
	) . n;

	$out[] = tag_start('div', array('class' => 'txp-layout'));

		$out[] = tag(
			fieldset(
				inputLabel('etc-' . $row['id'] . '-live-form',
					fInput('text', 'form1', $row['form1'] ,'','','', 28, 0, 'etc-'.$row['id'].'-live-form', false, false, 'etc_search_results'),
					gTxt('etc_search_use_form')
				) .
				inputLabel('etc-' . $row['id'] . '-live-tags',
					text_area('thing1', 0, 0, $row['thing1'], 'etc-'.$row['id'].'-live-tags'),
					gTxt('etc_search_use_content')
				),
				gTxt('etc_search_live_search')
			),
			'div',
			array('class' => 'txp-layout-2col')
		) . n;

		$out[] = tag(
			fieldset(
				inputLabel('etc-' . $row['id'] . '-static-form',
					fInput('text', 'form2', $row['form2'] ,'','','', 28, 0, 'etc-'.$row['id'].'-static-form', false, false, 'search_results'),
					gTxt('etc_search_use_form')
				) .
				inputLabel('etc-' . $row['id'] . '-static-tags',
					text_area('thing2', 0, 0, $row['thing2'], 'etc-'.$row['id'].'-static-tags'),
					gTxt('etc_search_use_content')
				),
				gTxt('etc_search_static_search')
			),
			'div',
			array('class' => 'txp-layout-2col')
		) . n;

	$out[] = tag_end('div');

	$out[] = eInput('etc_search#etc-form-'.$row['id']) .
	sInput('save') .
	hInput('id', $row['id']) .
	graf(
		fInput('submit', 'save', gTxt('save'), 'publish') .
		($row['id'] ? sp . fInput('submit', 'delete', gTxt('delete'), 'txp-button caution') : '')
	);

	$out = form(
		join('', $out),
		'', '', 'post', 'etc-search-form', '', 'etc-form-'.$row['id']
	);

	echo wrapRegion('etc-form-group'.$row['id'], $out, 'etc-ops-group-content'.$row['id'], ($row['id'] ? gTxt('etc_search_form_number').$row['id'] : gTxt('etc_search_new_form')), 'etc_search_global-ops_'.$row['id']);
}

function etc_search_parse($string, $pattern, &$matches, $open = '', $close = '', $replace = array())
{
	if(!$string || !$pattern) return $string;
	$matches = array();
	$string = preg_split($pattern, $string, null, PREG_SPLIT_DELIM_CAPTURE);	if(($count = count($string)) > 1) for($i = 1; $i < $count; $i += 2) {
		$matches[$open.$i.$close] = $replace ? strtr($string[$i], $replace) : $string[$i];
		$string[$i] = $open.$i.$close;
	}
	return implode('', $string);
}

function etc_search_query_($string, $fields, $ops=null){
	if(!$fields || $string === '') return '1';
	global $etc_search_ops, $etc_search_neg, $etc_search_match_query;
	$where = array();
	if(!$ops || !is_array($ops)) {
		$patterns = explode(';', $fields);
		$fields = array();
		$not = false;		while($string !== '' && $string[0] === $etc_search_neg) {$not = !$not; $string=substr($string, 1);}
		if(preg_match('/^\[\"\d*\"\]$/', $string)) return $not ? "( NOT $string )" : $string;
		$string = str_replace('{*}', '{{*}}', $string);
		if($string > '') foreach($patterns as $pattern) {
			unset($flds, $pat, $cond);
			$items = explode('::', $pattern); //+ array(null, null, null)
			if(count($items) == 3) list($flds, $pat, $cond) = $items;
			else foreach($items as $item) {				if($item && $item[0] === '/') $pat = $item;
				elseif(preg_match('/^\s*[\w\.]+\s*(?:,\s*[\w\.]+\s*)*$/', $item)) $flds = $item;
				else $cond = $item;
			}
			if(empty($pat)) $pat = '/^.+$/s';
//			if(isset($flds) && $flds === '') {$etc_search_match_query = true; return(isset($cond) ? preg_replace($pat, $cond, $string) : $string);}
			if(preg_match($pat, $string, $match)) {
				$etc_search_match_query = true;
				if(empty($flds) && !isset($cond)) return $string;
				if(!isset($cond)) $fields[] = array($flds, "{*} LIKE '%$string%'");
				else $fields[] = empty($flds) ? array('NULL', preg_replace($pat, $cond, $string)) : array($flds, preg_replace($pat, $cond, $match[0]));
			}
		}
		if(!$fields) return '0';
		else $etc_search_match_query = true;
		foreach($fields as $flist) {
			$whr = array();
			$flds = strpos($flist[0], '(') !== false ? preg_split('/([^\(\)\,]*(?:\((?:[^\(\)]|(?1))*\))?)(?:\,|$)/', $flist[0], null, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) : do_list($flist[0]);
			$nflds = count($flds);
			foreach($flds as $field) {
				$chunk = strtr($flist[1], array('{{*}}' => '{*}', '{*}' => $field) );
				$whr[] = $nflds > 1 ? '('.$chunk.')': $chunk;
			}
			$where[] = implode(" OR ", $whr);
		}
		$where = '( '.implode(' OR ', $where).' )';
		return ($not ? "( NOT $where )" : $where);
	}

	list($sep, $op) = array(end($ops), key($ops));
	unset($ops[$op]);

	$quotes = $braces = array();
	if(strpos($string, '\"') !== false) $string = etc_search_parse($string, '/\\\"(.*)\\\"/Us', $quotes, '|"', '"|');
	if(strpos($string, '(') !== false) $string = etc_search_parse($string, '/\(((?:[^()]|(?0))*)\)/U', $braces, '["', '"]');
	set_error_handler(function() {}, E_WARNING);
	$isReg = preg_match($sep, '') !== false;
	restore_error_handler();
	foreach(!$isReg ? do_list($string, $sep) : preg_split($sep, $string, 0, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $value) if($value !== '')
		$where[] = etc_search_query_($value, $fields, $ops);
	foreach($braces as &$value) $value = etc_search_query_($value, $fields, $etc_search_ops);
	unset($value);

	switch (count($where)) {
		case 0: return '0';
		case 1: return strtr(strtr($where[0], $braces), $quotes);
		default: return '( '.strtr(strtr(implode(" $op ", $where), $braces), $quotes).' )';
	}
}


function etc_search_query($atts)
{
	global $etc_search_match_query, $etc_search_neg;
	extract(lAtts(array(
		'query'   => '',
		'id'   => null,
		'split'   => get_pref('etc_search_ops'),
		'match'   => ''
	), $atts));

	if(isset($etc_search_match_query)) $safe_match =  $etc_search_match_query;
	if($id) foreach(do_list($id) as $item) {
			if($item) $op = get_pref('etc_search_ops_'.$item);
			$ops[] = json_decode(str_replace('\\', '\\\\', $op === '' ? get_pref('etc_search_ops') : $op), true);
		
	}
	else $ops = array(json_decode(str_replace('\\', '\\\\', $split === '' ? get_pref('etc_search_ops') : $split), true));

	$result = array();
	foreach($ops as $op) {
		if(isset($op['NOT'])) {$etc_search_neg = $op['NOT']; unset($op['NOT']);}
		else $etc_search_neg = '';

		$result[] = etc_search_query_($query, $match, $op);
	}
	if(isset($safe_match)) $etc_search_match_query = $safe_match;
	return count($result) == 1 ? $result[0] : '('.implode(') OR (', $result).')';
}

function etc_search_get_results($params, $live=true)
{
	global $prefs, $pretext, $thisarticle, $thispage, $thisimage, $thislink, $thisfile, $thiscategory, $thissection, $etc_page_counter, $etc_search_neg, $etc_search_ops, $etc_search_forms, $etc_search_match, $etc_search_match_query;

	if(!($search_fields = $prefs['searchable_article_fields'])) $search_fields = 'Title,Body';
	$safe_search_fields = '`'.implode('`,`', do_list($search_fields)).'`';

	$pretext_q = $pretext['q'];
	$q = $pretext['q'] = $params['q'];
	$max = $newmax = max(intval($params['etc_limit']), 0);
	$pg = max(intval($pretext['pg']), 1);
	$offset = ($pg - 1)*$max;
	$o = array();
	$matched = false;
	$rc = $lim_it = 0;
	$etc_search_ops = gps('m') === 'exact' ? null : get_pref('etc_search_ops');

	foreach($params['etc_search'] as $id) {
		if(isset($etc_search_ops)) {
			if($id) $etc_search_ops = get_pref('etc_search_ops_'.$id);
			$etc_search_ops = json_decode(str_replace('\\', '\\\\', $etc_search_ops === '' ? get_pref('etc_search_ops') : $etc_search_ops), true);
		}
		if(isset($etc_search_ops['NOT'])) {$etc_search_neg = $etc_search_ops['NOT']; unset($etc_search_ops['NOT']);}
		else $etc_search_neg = '';

		unset($this_article, $this_image, $this_file, $this_link);
		if($id) {
				if(isset($etc_search_forms[$id])) $row = $etc_search_forms[$id];
				else $row = $etc_search_forms[$id] = safe_row('query, form1, form2, thing1, thing2, type', 'etc_search', "id=$id");
				if(empty($row)) continue;
		}
		else $row = array('query'=>'', 'form1'=>'', 'form2'=>'', 'thing1'=>'', 'thing2'=>'', 'type'=>'article');
		extract($row);

		if(!empty($params['etc_f'])) $form = $params['etc_f'];
		else $form = $live ? ($form1 ? $form1 : 'etc_search_results') : ($form2 ? $form2 : 'search_results');
		if(!empty($params['etc_t'])) $thing = $params['etc_t'];
		else $thing = $live ? ($thing1 ? $thing1 : fetch_form($form)) : ($thing2 ? $thing2 : fetch_form($form));

		if(!$query) {$query = '{'.$search_fields.'}'; $type = 'article';}
		else $query = trim(parse($query));

		if(preg_match('/^(.+)\b(LIMIT\s+(\d+)\b\s*,?\s*(\d*)\b.*)$/i', $query, $matches)) {
			$query = $matches[1]; $limit = $matches[2];
			if($matches[4]) {$off = $matches[3]; $lim = $matches[4];}
			else {$off = 0; $lim = $matches[3];}
		}
		else $limit = '';

		if($newmax > 0) $lim_it = $limit ? " LIMIT ".($offset + $off).", ".min($lim - $offset, $newmax) : " LIMIT $offset, $newmax";
		else $lim_it = '';

		if(preg_match('/^(.+)\b(ORDER\s+BY\b.+)$/Ui', $query, $matches)) {
			$query = $matches[1]; $order = $matches[2];
		} else $order = '';

		// If $query includes an own 'Status' query, use that in place of the default
		if(preg_match('/^(.+)\b(Status[\s!=<>].+)$/Ui', $query, $matches)) {
			$status = '';
		} else $status = 'Status >= 4 AND';

		// Match all instances of Section = 'value'
		$query_sections = [];
		if (preg_match_all("/Section\s*=\s*'([^']+)'/", $query, $matches)) {
			$query_sections = array_merge($query_sections, $matches[1]);
		}

		// Match all instances of Section IN ('value1', 'value2', 'value3')
		if (preg_match_all("/Section\s*IN\s*\(\s*'([^']+)'\s*(?:,\s*'([^']+)'\s*)*\)/", $query, $matches)) {
			foreach ($matches[0] as $match) {
				preg_match_all("/'([^']+)'/", $match, $values);
				$query_sections = array_merge($query_sections, $values[1]);
			}
		}

		$query_sections = array_unique($query_sections);

		$etc_search_match = true;
		$etc_search_match_query = false;
		$query = preg_replace_callback('/\{((?:[^{}]|(?0))+)\}/U', 'etc_search_gps', $oldquery = $query);
		if(!$etc_search_match || empty($query) || !$etc_search_match_query && ($oldquery !== $query)) continue;
		else $matched = true;

//		$order = preg_replace_callback('/\{((?:[^{}]|(?0))+)\}/U', 'etc_search_gps', $order);

		if(!($custom = preg_match('/^SELECT\b/i', $query))) switch($type) {//default search
			case 'image' : case 'file' : case 'link' : case 'category' : case 'section' :
			$table = safe_pfx('txp_'.$type);
			$count = 'SELECT COUNT(*) FROM '.$table.' WHERE '.($type == 'file' ? $status.' ' : '').$query;
			$query = 'SELECT * FROM '.$table.' WHERE '.($type == 'file' ? $status.' ' : '').$query;
			break;

			default :
			$s_filter = '';
			$rs = safe_column("name", "txp_section", "searchable != '1'");
			if ($rs) {
				foreach($rs as $name) {
					if (!in_array($name, $query_sections)) {
						$s_filter .= " AND Section != '".doSlash($name)."'";
					}
				}
			}
			$table = safe_pfx('textpattern');
			$now_posted = is_callable('now') ? now('posted') : 'NOW()';
			$now_expires = is_callable('now') ? now('expires') : 'NOW()';
			$count = "SELECT COUNT(*) FROM $table WHERE $status Posted <= $now_posted AND (Expires IS NULL OR Expires>=$now_expires) $s_filter AND $query";
			$query = "SELECT *".($order ? '' : ", MATCH ($safe_search_fields) AGAINST ('$q') AS score")." FROM $table WHERE $status Posted <= $now_posted AND (Expires IS NULL OR Expires>=$now_expires) $s_filter AND $query";
			if(!$order) $order = ' ORDER BY score DESC';
		}

		$count = !$custom ? $count : 'SELECT count(*) FROM ('.$query.$limit.') AS tmpcount'; /*(
			$limit ? "SELECT count(*) FROM (".preg_replace("/^SELECT\b(?:[^\']|\'.*\')+\bFROM\b/Ui", 'SELECT 1 FROM', $query.$limit).") count"
				: preg_replace("/^SELECT\b(?:[^\']|\'.*\')+\bFROM\b/Ui", 'SELECT count(*) FROM', $query)
		);
*/
		$count = intval(getThing($count));
		$rc += $count;
		if($count <= $offset) {$offset -= $count; continue;} else $offset = 0;
		$rs = ($max <= 0 || $newmax > 0 ? getRows($query.$order.($lim_it ? $lim_it : $limit)) : array());
		$replacements = array();
		preg_match_all("/\{(.+)\}/U", $thing, $matches, PREG_SET_ORDER);

		if($type && isset(${'this'.$type})) ${'this_'.$type} = ${'this'.$type};
		$count = count($rs) - 1;
		if(!empty($rs)) foreach($rs as $i => $a) {
			foreach($matches as $match) {
				if($match[1][0] === '*') {$match[1] = substr($match[1], 1); $escape = false;}
				else $escape = true;
				if(isset($a[$match[1]])) $replacements[$match[0]] = $escape ? htmlspecialchars($a[$match[1]], ENT_QUOTES) : $a[$match[1]];
			}
			if($type) {
				$val = $type.($type === 'file' ? '_download' : '').'_format_info';
				if($type == 'article') article_format_info($a);
				elseif($type == 'file' || $type == 'image' || $type == 'link') ${'this'.$type} = $val($a);
				else ${'this'.$type} = $a;
				${'this'.$type}['is_first'] = $i == 0;
				${'this'.$type}['is_last'] = $i == $count;
			}
			$o[] = parse(strtr($thing, $replacements));
		}
		if($type && isset(${'this_'.$type})) ${'this'.$type} = ${'this_'.$type};
		if($max > 0) $newmax = $max - count($o);
	}

	if(empty($thispage)) {
		$etc_page_counter['from'] = ($pg - 1)*$max + 1;
		$etc_page_counter['to'] = $max > 0 ? min($etc_page_counter['from'] + $max - 1, $rc) : $rc;
		$thispage['pg']          = $pg;
		$thispage['numPages']    = $max > 0 ? ceil($rc/$max) : 1;
		$thispage['s']           = $pretext['s'];
		$thispage['c']           = $pretext['c'];
		$thispage['context']     = 'article';
		$thispage['grand_total'] = $rc;
		$thispage['total']       = $rc - $etc_page_counter['from'] + 1;
	}
	if($live && $lim_it && $thispage['numPages'] > $pg) $o[] = gTxt('more').'&hellip;';

	$pretext['q'] = $pretext_q;
/*  echo('<?xml version=\'1.0\' encoding=\'utf-8\' ?>');*/
	return $matched ? $o : null;
}

function etc_search_result_count($atts)
{
	global $thispage, $etc_page_counter;
	if(empty($thispage) || empty($etc_page_counter)) return;

	extract(lAtts(array(
		'text'   => gTxt('showing_search_results')
	), $atts));

	return(strtr($text, array('{from}' => $etc_page_counter['from'], '{to}' => $etc_page_counter['to'], '{total}' => $thispage['grand_total'], '{page}' => $thispage['pg'], '{pages}' => $thispage['numPages'])));
}

function etc_search_result_excerpt($atts)
{
	extract(lAtts(array(
		'break'   => ' &#8230;',
		'hilight' => 'strong',
		'limit'   => 5,
		'size'   => 50,
		'showalways'   => "0",
		'type'   => 'article',
		'field'   => 'body'
	), $atts));

	global ${'this'.$type}, $pretext;
	if(empty(${'this'.$type}) || empty(${'this'.$type}[$field])) return '';
	$m = $pretext['m'];
	if(($q = trim(gps('q'))) === '') return;

	$result = preg_replace('/\s+/', ' ', strip_tags(str_replace('><', '> <', ${'this'.$type}[$field])));

	$ops = json_decode(str_replace('\\', '\\\\', get_pref('etc_search_ops')), true) or $ops = array();
	foreach($ops as &$val) $val = preg_quote($val, '/');
	$ops = implode('|', $ops);

	$q = preg_quote(str_replace(array('(', ')'), '', $q), '/');
	$quotes = array();
	if($m !== 'exact' && strpos($q, '"') !== false) $q = etc_search_parse($q, '/(".*")/Us', $quotes, '(', ')', array('"' => ''));
	$q = htmlspecialchars($q, ENT_QUOTES);

	if ($m === 'exact')
	{
		$regex_search = '/(?:\G|\s).{0,'.$size.'}'.$q.'.{0,'.$size.'}(?:\s|$)/iu';
		$regex_hilite = $q;
	}
	else
	{
		$regex_hilite = strtr(preg_replace("/(?:$ops)+/", '|', $q), doSpecial($quotes));
		$regex_search = '/(?:\G|\s).{0,'.$size.'}('.$regex_hilite.').{0,'.$size.'}(?:\s|$)/iu';
	}

	preg_match_all($regex_search, $result, $concat);
	$concat = $concat[0];

	$min = min($limit, count($concat));
	for ($i = 0, $r = array(); $i < $min; $i++)
	{
		$r[] = trim($concat[$i]);
	}

	$concat = join($break.n, $r);
	$concat = preg_replace('/^[^>]+>/U', '', $concat);

	$concat = preg_replace('/('.$regex_hilite.')/i', "<$hilight>$1</$hilight>", $concat);

	if($concat) return (strlen($result) > $size ? $break.$concat.$break : trim($concat));
	elseif(!$showalways) return '';

	$result = explode('<cut />', wordwrap($result, 2*$size, '<cut />', true), 2);
	return $result[0].(count($result) > 1 ? $break : '');
}

function etc_search_callback($event, $step)
{
	global $nolog;
	$nolog = true;

//	header('Content-Type: text/html');
	exit(etc_search_results(array(), null, true));
}

function etc_search_gps($matches) {
	global $pretext, $etc_search_ops, $etc_search_match, $etc_search_neg;
	$slash = isset($etc_search_neg);//query db
	$custom = $matches[1][0] === '?';
	if(!$custom && $slash) {$q = $pretext['q']; $fields = $matches[1];}
	else {
		if($custom) $matches[1] = substr($matches[1], 1);
		if($slash) list($q, $fields) = explode('::', $matches[1], 2) + array(null, null);
		else $q = $matches[1];
		list($q, $sep) = explode('&', $q, 2) + array(null, ',');
		list($q, $def) = explode('|', $q, 2) + array(null, null);
//		$q = str_replace(' ', '_', $q);
/*		if(!isset($_REQUEST[$q])) if(isset($def)) $q = $def; else {$q = ''; $etc_search_match = false;}
		else*/ $q = (is_array($q = gps($q)) ? implode($sep, $q) : $q);

		if($q === '') {if(isset($def)) $q = $def; else $etc_search_match = false;}
		elseif($slash) $q = addcslashes(doSlash($q), '%_');
	}
	return !empty($fields) ? etc_search_query_($q, $fields, $etc_search_ops) : $q;
}

function etc_search_term($event, $step)
{
	global $pretext, $etc_search_match;
	$etc_search_match = true;
	if($pretext['q'] === '') $pretext['q'] = gps('etc_search');
	$etc_format = htmlspecialchars_decode(gps('etc_q'));
	if($etc_format === '') return;
	$pretext['q'] = preg_replace_callback("/\{(.+)\}/Us", 'etc_search_gps', $etc_format);
	if(!$etc_search_match) $pretext['q'] = '';
}

function etc_search_results($atts, $thing=null, $live=false)
{
	global $has_article_tag, $prefs, $pretext;
	if (!isset($atts['query']) && $pretext['q'] === '' && gps('etc_search') === '') return article($atts, $thing);
	$has_article_tag = true;

	extract(lAtts(array(
		'id'         => '',
//		'format'         => '{q}',
		'query'         => null,
//		'html_id'         => '',
		'form'         => '',
		'no_matches'   => gTxt('no_search_matches'),
		'limit'     => 10,
		'wraptag'         => '',
		'class'         => '',
		'break'   => ''
	), $atts, false));

	if(isset($query)) $params = array('etc_search' => do_list($id), 'etc_limit' => $limit, 'q' => $query);
	else {
		extract($params = gpsa(array('etc_search', 'etc_limit', 'etc_q', 'etc_f', 'etc_w', 'etc_b')));
		$params['q'] = trim($pretext['q']);
		if($params['q'] === '') return '';

		if($etc_search) {
			list($hash, $search) = explode('.', $etc_search, 2) + array(null, null);
			if(md5(get_pref('etc_search_hash').($live ? $etc_f.$etc_w.$etc_b.intval($etc_limit): '').$etc_q.$search) !== $hash) return '';
			$params['etc_search'] = $search;
		}
		$params['etc_search'] = array_map('intval', do_list($params['etc_search'], '.'));
		if($id !== '') $params['etc_search'] = $id[0] === '-' ? array_diff($params['etc_search'], do_list(substr($id, 1))) : array_intersect($params['etc_search'], do_list($id));
	}
	if($params['q'] === '' || empty($params['etc_search'])) return '';
	if($live) {$wraptag = $params['etc_w']; $break = $params['etc_b']; $form = $params['etc_f']; $limit = $params['etc_limit'];}

	if(isset($thing)) $no_matches = EvalElse($thing, 0);
	if($form) $thing = fetch_form($form);
	$thing = isset($thing) ? EvalElse($thing, 1) : '';

	if($limit) $params['etc_limit'] = $limit;
	if($form) $params['etc_f'] = $form;
	if($thing) $params['etc_t'] = $thing;

	$params['q'] = doSlash($params['q']);
	if(!isset($query)) $params['q'] = addcslashes($params['q'], '%_');

	$o = etc_search_get_results($params, $live);
	return ($o ? doWrap($o, $wraptag, $break, $class) : ($o === null ? '' : parse($no_matches)));
}

function etc_search($atts, $thing = '')
{
	global $prefs;
	extract(lAtts(array(
		'id'         => '0',
		'target'         => '',
		'live'         => '600',
		'match'         => '',
		'action'         => null,
		'format'         => '',
		'minlength'            => 1,
		'html_id'         => str_replace('.', '', uniqid("live_search_", true)),
		'label'           => gTxt('search'),
		'size'            => 0,
		'placeholder'   => '',
		'limit'     => 0,
		'form'         => '',
		'class'         => '',
		'wraptag'         => '',
		'break'   => 'br'
	), $atts));

	$id = implode('.', do_list($id));
	$live = /*$thing ? 0 :*/ intval($live);
	$limit = intval($limit);
	$minlength = intval($minlength);
	$qs = array();
	if($action === null) $action = rhu;
	elseif ($q = parse_url($action, PHP_URL_QUERY)) parse_str($q, $qs);
	unset($qs['q'], $qs['m'], $qs['pg'], $qs['etc_search'], $qs['etc_limit'], $qs['etc_q']);
//	$hash = $id || $limit ? md5($prefs['etc_search_hash'].$limit.$id).'.'.$id : '';
	$hash = $id ? md5($prefs['etc_search_hash'].$format.$id).'.'.$id : '';
	$q = (!$id || gps('etc_search') == $hash ? htmlspecialchars(gps('q')) : '');

	$inputs = '';
	if($hash) $inputs .= '<input type="hidden" data-etc="search" name="etc_search" value="'.$hash.'" />'.n;
	if($format) $inputs .= '<input type="hidden" data-etc="search" name="etc_q" value="'.htmlspecialchars($format).'" />'.n;
//	if($limit) $inputs .= '<input type="hidden" data-etc="search" name="etc_limit" value="'.$limit.'" />'.n;
	if($match) $inputs .= '<input type="hidden" data-etc="search" name="m" value="'.$match.'" />'.n;
	foreach($qs as $key => $val) $inputs .= '<input type="hidden" data-etc="search" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($val).'" />'.n;
	$inputs .= $thing ? parse($thing) : '<input type="search" name="q"'.($size ? ' size="'.intval($size).'"' : '').' value="'.$q.'" placeholder="'.$placeholder.'" autocomplete="off" />'.n;

	$out = '<form id="'.$html_id.'" class="'.$class.'" method="get" action="'.$action.'">'.n
	.($label ? '<label>'.$label.'<br /></label>' : '').$inputs
	.'</form>';

	if($live) {
		$hash = md5($prefs['etc_search_hash'].$form.$wraptag.$break.$limit.$format.$id).'.'.$id;
		$results_opts = array();
		$results_opts[] ='etc_search:"'.$hash.'"';
		if($form) $results_opts[] = 'etc_f:"'.$form.'"';
		if($break) $results_opts[] ='etc_b:"'.$break.'"';
		if($wraptag) $results_opts[] = 'etc_w:"'.$wraptag.'"';
		if($limit) $results_opts[] = 'etc_limit:"'.$limit.'"';

		$out .= '<script>//<![CDATA['.n.'window.addEventListener("load", function () {'.n
		.'etc_live_search('.$live.','.$minlength.',"'.$html_id.'","'.$target.'",{'.implode(',', $results_opts).'});'.n
		.'});'.n.'//]]></script>';
	}
	return $out;
}

function etc_search_pophelp($event, $step, $ui, $atts)
{
	$atts['data-item'] = gTxt('pophelp_' . $atts['help_var']);

	return sp.href(span(gTxt('help'), array('class' => 'ui-icon ui-icon-help')), '#', $atts);
}