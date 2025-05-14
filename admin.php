<?php

use dokuwiki\ChangeLog\ChangeLog;
use dokuwiki\Utf8\PhpString;

require_once(DOKU_INC . 'inc/DifferenceEngine.php');

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
            if (!empty($subfiles)) {
                $files = array_merge($files, $subfiles);
            }
        }
    }
    return $files;
}

function cmp($a, $b) {
    $time1 = $a['date'];
    $time2 = $b['date'];
    if ($time1 == $time2) return 0;
    return ($time1 < $time2 ? 1 : -1); // descending
}

class admin_plugin_userhistoryadvanced extends DokuWiki_Admin_Plugin {

	private function _getPreviousRevisionTimestamp($pageId, $currentTs) {
		$revs = getRevisions($pageId, 0, 0);
		foreach ($revs as $ts) {
			if ($ts < $currentTs) {
				return $ts;
			}
		}
		return null;
	}
	
	private function _showDiffForChange($pageId, $rev1, $rev2 = null) {
		$file1 = wikiFN($pageId, $rev1);
		if (!file_exists($file1)) return '';
	
		$text1 = io_readFile($file1, false);
		$text2 = $rev2 ? io_readFile(wikiFN($pageId, $rev2), false) : rawWiki($pageId);
	
		$diff = new Diff(explode("\n", $text1), explode("\n", $text2));
		$formatter = new UnifiedDiffFormatter();
		return '<div class="diff_content"><pre>' . hsc($formatter->format($diff)) . '</pre></div>';
	}



    public function __construct() {
        $this->setupLocale();
    }

    public function getMenuSort() {
        return 999;
    }

    public function handle() {
        // Handle logic here if needed
    }

    private function _userList() {
        global $auth, $ID;

        $user_list = $auth->retrieveUsers();
        echo '<h2 id="' . str_replace([" ", "'"], "_", strtolower($this->getLang('list'))) . '">' . $this->getLang('list') . '</h2>';
        echo '<div class="editor_list"><p class="editor_counter">' . $this->getLang('total') . ': ' . count($user_list) . '</p><ol>';

        foreach ($user_list as $key => $value) {
            $nick = $key;
            $name = $value['name'];
            $href = wl($ID) . (strpos(wl($ID), '?') ? '&amp;' : '?') . 'do=admin&amp;page=' . $this->getPluginName() . '&amp;user=' . hsc($nick);
            echo '<li><a href="' . $href . '">' . $nick . ' - ' . $name . '</a></li>';
        }

        echo '</ol></div>';
    }

    private function _getChanges($user, $namespace = '') {
        global $conf;

        $changes = [];
        $alllist = globr($conf['metadir'], '*.changes');
        $skip = ['_comments.changes', '_dokuwiki.changes'];

        foreach ($alllist as $fullname) {
            if (in_array(basename($fullname), $skip)) continue;

            $lines = file($fullname);
            foreach ($lines as $line) {
                $change = dokuwiki\ChangeLog\ChangeLog::parseLogLine($line);
                if (!$change) continue;

                if (strtolower($change['user']) !== strtolower($user)) continue;

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

    private function _userHistory($user, $namespace = '') {
        global $conf, $ID;

        $changes = array_values($this->_getChanges($user, $namespace));
        $total = count($changes);

        if (isset($_REQUEST['export']) && $_REQUEST['export'] === 'csv') {
            while (ob_get_level()) ob_end_clean();
            if (function_exists('header_remove')) header_remove();

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="user_history_' . $user . '.csv"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
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

        $perPage = 100;
        $start = isset($_REQUEST['start']) ? max(0, intval($_REQUEST['start'])) : 0;
        $end = min($start + $perPage, $total);

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
			echo '<li class="' . ($change['type'] === 'e' ? 'minor' : '') . '">';
			echo '<div class="li"><span class="date">' . strftime($conf['dformat'], $change['date']) . '</span> ';
			$diffLink = wl($change['id'], "do=diff&rev=" . $change['date']);
			$revLink  = wl($change['id'], "do=revisions");
			echo '<a class="diff_link" href="' . $diffLink . '"><img src="' . DOKU_BASE . 'lib/images/diff.png" alt="diff" title="diff" /></a> ';
			echo '<a class="revisions_link" href="' . $revLink . '"><img src="' . DOKU_BASE . 'lib/images/history.png" alt="history" title="history" /></a> ';
			echo $change['id'] . ' – ' . html_wikilink(':' . $change['id'], $conf['useheading'] ? NULL : $change['id']);
			if (!empty($change['sum'])) {
				echo ' – ' . hsc($change['sum']);
			}
		
			// Show inline diff
			$prevRev = $this->_getPreviousRevisionTimestamp($change['id'], $change['date']);
			if ($prevRev) {
				echo $this->_showDiffForChange($change['id'], $prevRev, $change['date']);
			} else {
				echo '<div class="diff_content"><em>No previous revision available for diff.</em></div>';
			}
		
			echo '</div></li>';
		}

        echo '</ol>';
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

    private function _userSummary($namespace = '') {
        global $auth;

        $user_list = $auth->retrieveUsers();
        echo '<h2>' . $this->getLang('list') . '</h2>';
        echo '<div class="editor_list"><ol>';

        foreach ($user_list as $username => $info) {
            $changes = $this->_getChanges($username, $namespace);
            if (empty($changes)) continue;

            $name = hsc($info['name']);
            $count = count($changes);
            $href = wl('', ['do' => 'admin', 'page' => $this->getPluginName(), 'user' => $username, 'namespace' => $namespace]);
            echo "<li><a href=\"$href\">$username - $name</a> ($count changes)</li>";
        }

        echo '</ol></div>';
    }

    public function html() {
        global $conf, $ID, $auth;

        echo '<h1>' . hsc($this->getLang('menu')) . '</h1>';

        $selectedNamespace = isset($_REQUEST['namespace']) ? cleanID($_REQUEST['namespace']) : '';
        $selectedUser = isset($_REQUEST['user']) ? $_REQUEST['user'] : '';

        $allUsers = $auth->retrieveUsers();
        $users = [];
        $usersWithHistory = [];
        $namespaces = [];

        $changeFiles = globr($conf['metadir'], '*.changes');
        $skip = ['_comments.changes', '_dokuwiki.changes'];

        foreach ($changeFiles as $file) {
            if (in_array(basename($file), $skip)) continue;
            $lines = file($file);
            foreach ($lines as $line) {
                $change = dokuwiki\ChangeLog\ChangeLog::parseLogLine($line);
                if (!$change) continue;

                $uname = strtolower($change['user']);
                $usersWithHistory[$uname] = true;

                if (strpos($change['id'], ':') !== false) {
                    $ns = substr($change['id'], 0, strrpos($change['id'], ':'));
                    $namespaces[$ns] = true;
                }
            }
        }

        foreach ($allUsers as $username => $info) {
            $lower = strtolower($username);
            $users[$lower] = [
                'username' => $username,
                'name' => $info['name'],
                'hasHistory' => isset($usersWithHistory[$lower])
            ];
        }

        ksort($users);
        ksort($namespaces);

        echo '<form action="" method="GET">';
        echo '<input type="hidden" name="do" value="admin" />';
        echo '<input type="hidden" name="page" value="' . $this->getPluginName() . '" />';

        echo '<label>User: <select name="user">';
        echo '<option value="">-- Select User --</option>';
        foreach ($users as $data) {
            $selected = ($selectedUser == $data['username']) ? 'selected' : '';
            $label = hsc($data['username']) . ' - ' . hsc($data['name']);
            if ($data['hasHistory']) {
                $label .= ' *';
            }
            echo '<option value="' . hsc($data['username']) . '" ' . $selected . '>' . $label . '</option>';
        }
        echo '</select></label> ';

        echo '<label>Namespace: <select name="namespace">';
        echo '<option value="">-- All Namespaces --</option>';
        foreach (array_keys($namespaces) as $ns) {
            $selected = ($selectedNamespace == $ns) ? 'selected' : '';
            echo '<option value="' . hsc($ns) . '" ' . $selected . '>' . hsc($ns) . '</option>';
        }
        echo '</select></label> ';

        echo '<input type="submit" value="Filter" />';
        echo '</form>';

        if ($selectedUser) {
            $this->_userHistory($selectedUser, $selectedNamespace);
        } else {
            $this->_userSummary($selectedNamespace);
        }
    }
}
