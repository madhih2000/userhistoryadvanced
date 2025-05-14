<?php
 
/**
 * All DokuWiki plugins to extend the admin function
 * need to inherit from this class
 */


 function globr($dir, $pattern) {
	$files = glob($dir . '/' . $pattern);
	$subdirs = glob($dir . '/*', GLOB_ONLYDIR);
	if (!empty($subdirs)) {
		foreach ($subdirs as $subdir) {
			$subfiles = globr($subdir, $pattern);
			if (!empty($subfiles) && !empty($files)) {
				$files = array_merge($files, $subfiles);
			}
		}
	}
	return $files;
}

// Original sorting logic preserved
function cmp($a, $b) {
	$time1 = $a['date'];
	$time2 = $b['date'];
	if ($time1 == $time2) {
		return 0;
	}
	return ($time1 < $time2 ? 1 : -1); // descending
}

class admin_plugin_userhistoryadvanced extends DokuWiki_Admin_Plugin {

	function admin_plugin_userhistoryadvanced ( ) {
        $this->setupLocale ( );
	}
 
    /**
     * return sort order for position in admin menu
     */
    function getMenuSort ( ) {
      return 999;
    }

    /**
     * handle user request
     */
    function handle ( ) {
    }
 
    /**
     * output appropriate html
     */
	function _userList ( ) {
		global $auth;
		global $ID;
		
        $user_list = $auth->retrieveUsers ( );
		
        echo ( '<h2 id="'.str_replace ( array ( " ", "'" ), "_", strtolower ( $this->getLang ( 'list' ) ) ).'">'.$this->getLang ( 'list' ).'</h2>' );
	
		echo ( '<div class = "editor_list"><p class = "editor_counter">'.$this->getLang ( 'total' ).': '.count ( $user_list ).'</p><ol>' );
		foreach ( $user_list as $key => $value ) {
			$nick = $key;
			$name = $value['name'];
			$href = wl ( $ID ). ( strpos ( wl ( $ID ), '?' )?'&amp;':'?' ).'do=admin&amp;page='.$this->getPluginName ( ).'&amp;user='.hsc ( $nick );
			echo ( '<li><a href = "'.$href.'">'.$nick.' - '.$name.'</li>' );
		}
		echo ( '</ol></div>' );
	}


	function _getChanges($user, $namespace = '') {
		global $conf;
	
		$changes = array();
		$alllist = globr($conf['metadir'], '*.changes');
		$skip = array('_comments.changes', '_dokuwiki.changes');
	
		foreach ($alllist as $fullname) {
			$filepart = basename($fullname);
			if (in_array($filepart, $skip)) continue;
	
			$lines = file($fullname);
			foreach ($lines as $line) {
				$change = dokuwiki\ChangeLog\ChangeLog::parseLogLine($line);
				if (!$change) continue;
	
				// Case-insensitive username match
				if (strtolower($change['user']) != strtolower($user)) continue;
	
				// Namespace filter
				if (!empty($namespace)) {
					$nsWithColon = rtrim($namespace, ':') . ':';
					if (strpos($change['id'], $nsWithColon) !== 0) continue;
				}
	
				$changes[] = $change;
			}
		}
	
		uasort($changes, 'cmp');
	
		return $changes;
	}
	
	
	function _userHistory($user, $namespace = '') {
		global $conf, $ID, $lang;
	
		$changes = array_values($this->_getChanges($user, $namespace));
		$total = count($changes);
	
		// Handle export to CSV
		if (isset($_REQUEST['export']) && $_REQUEST['export'] === 'csv') {
			// Prevent DokuWiki from sending any output
			ob_end_clean(); // Clean any output buffer if exists

			header('Content-Type: text/csv; charset=utf-8');
			header('Content-Disposition: attachment; filename="user_history_' . $user . '.csv"');
			header('Pragma: no-cache');
			header('Expires: 0');

			$out = fopen('php://output', 'w');
			fputcsv($out, ['Date', 'Page ID', 'Summary', 'Change Type']);

			foreach ($changes as $entry) {
				fputcsv($out, [
					strftime($conf['dformat'], $entry['date']),
					$entry['id'],
					$entry['sum'],
					$entry['type']
				]);
			}

			fclose($out);
			exit; 
		}
	
		// Pagination setup
		$perPage = 100;
		$start = isset($_REQUEST['start']) ? max(0, intval($_REQUEST['start'])) : 0;
		$end = min($start + $perPage, $total);
	
		// Back and Export Links
		$params = ['do' => 'admin', 'page' => $this->getPluginName()];
		if ($namespace) $params['namespace'] = $namespace;
	
		echo '<p><a href="' . wl($ID, $params) . '">[' . $this->getLang('back') . ']</a></p>';
		echo '<p><a href="' . wl($ID, array_merge($params, ['user' => $user, 'export' => 'csv'])) . '">[' . $this->getLang('export_csv') . ']</a></p>';
	
		echo '<h2>' . hsc($user) . '</h2>';
		echo '<div class="edit_list">';
		echo '<p class="edit_counter">' . $this->getLang('total') . ': ' . $total . '</p>';
		echo '<ol start="' . ($start + 1) . '">';
	
		for ($i = $start; $i < $end; $i++) {
			$change = $changes[$i];
			$date = strftime($conf['dformat'], $change['date']);
			echo ($change['type'] === 'e') ? '<li class="minor">' : '<li>';
			echo '<div class="li"><span class="date">' . $date . '</span> ';
	
			$diffLink = wl($change['id'], "do=diff&rev=" . $change['date']);
			$revLink  = wl($change['id'], "do=revisions");
	
			echo '<a class="diff_link" href="' . $diffLink . '"><img src="' . DOKU_BASE . 'lib/images/diff.png" alt="diff" title="diff" /></a> ';
			echo '<a class="revisions_link" href="' . $revLink . '"><img src="' . DOKU_BASE . 'lib/images/history.png" alt="history" title="history" /></a> ';
			echo $change['id'] . ' – ' . html_wikilink(':' . $change['id'], $conf['useheading'] ? NULL : $change['id']);
			if (!empty($change['sum'])) {
				echo ' – ' . hsc($change['sum']);
			}
			echo '</div></li>';
		}
	
		echo '</ol>';
	
		// Pagination navigation
		echo '<div class="pagination">';
		$baseParams = ['do' => 'admin', 'page' => $this->getPluginName(), 'user' => $user];
		if ($namespace) $baseParams['namespace'] = $namespace;
	
		if ($start > 0) {
			$prev = max(0, $start - $perPage);
			echo '<a href="' . wl($ID, array_merge($baseParams, ['start' => $prev])) . '">← Previous</a> ';
		}
		if ($end < $total) {
			$next = $start + $perPage;
			echo '<a href="' . wl($ID, array_merge($baseParams, ['start' => $next])) . '">Next →</a>';
		}
		echo '</div>';
	
		echo '</div>';
	}

	function _userSummary($namespace = '') {
		global $auth;
	
		$user_list = $auth->retrieveUsers();
		echo '<h2>' . $this->getLang('list') . '</h2>';
		echo '<div class="editor_list"><ol>';
	
		foreach ($user_list as $username => $info) {
			$changes = $this->_getChanges($username, $namespace);
			if (empty($changes)) continue;
	
			$name = hsc($info['name']);
			$count = count($changes);
			$href = wl('', array('do'=>'admin', 'page'=>$this->getPluginName(), 'user'=>$username, 'namespace'=>$namespace));
			echo "<li><a href=\"$href\">$username - $name</a> ($count changes)</li>";
		}
	
		echo '</ol></div>';
	}

	function html() {
		global $conf, $ID;
	
		echo '<h1>' . hsc($this->getLang('menu')) . '</h1>';
	
		// Get request values
		$selectedNamespace = isset($_REQUEST['namespace']) ? cleanID($_REQUEST['namespace']) : '';
		$selectedUser = isset($_REQUEST['user']) ? $_REQUEST['user'] : '';
	
		// Collect users and namespaces from changelog files
		$users = [];
		$namespaces = [];
	
		$changeFiles = globr($conf['metadir'], '*.changes');
		$skip = array('_comments.changes', '_dokuwiki.changes');
	
		foreach ($changeFiles as $file) {
			if (in_array(basename($file), $skip)) continue;
			$lines = file($file);
			foreach ($lines as $line) {
				$change = dokuwiki\ChangeLog\ChangeLog::parseLogLine($line);
				if (!$change) continue;
	
				$uname = strtolower($change['user']);
				$users[$uname] = $change['user']; // preserve case
	
				// Extract namespace from page ID
				$id = $change['id'];
				if (strpos($id, ':') !== false) {
					$ns = substr($id, 0, strrpos($id, ':'));
					$namespaces[$ns] = true;
				}
			}
		}
	
		ksort($users);
		ksort($namespaces);
	
		// Filter form
		echo '<form action="" method="GET">';
		echo '<input type="hidden" name="do" value="admin" />';
		echo '<input type="hidden" name="page" value="' . $this->getPluginName() . '" />';
	
		// User dropdown
		echo '<label>User: <select name="user">';
		echo '<option value="">-- Select User --</option>';
		foreach ($users as $key => $name) {
			$selected = ($selectedUser == $name) ? 'selected' : '';
			echo '<option value="' . hsc($name) . '" ' . $selected . '>' . hsc($name) . '</option>';
		}
		echo '</select></label> ';
	
		// Namespace dropdown
		echo '<label>Namespace: <select name="namespace">';
		echo '<option value="">-- All Namespaces --</option>';
		foreach (array_keys($namespaces) as $ns) {
			$selected = ($selectedNamespace == $ns) ? 'selected' : '';
			echo '<option value="' . hsc($ns) . '" ' . $selected . '>' . hsc($ns) . '</option>';
		}
		echo '</select></label> ';
	
		echo '<input type="submit" value="Filter" />';
		echo '</form>';
	
		// Show results
		if ($selectedUser) {
			$this->_userHistory($selectedUser, $selectedNamespace);
		} else {
			$this->_userSummary($selectedNamespace);
		}
	}
	
}
