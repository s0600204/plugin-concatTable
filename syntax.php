<?php
/**
 *	Concat Tables Plugin
 *		Gets tables from other pages, combines them and outputs the result
 *
 *	Usage:
 *		{{concat>page1#section|page2#section|...|pageX#section}}
 *		{{concat>page1#section|page2#section|...|pageX#section&flags}}
 *
 *	@license	CC-BY-SA 3.0 (http://creativecommons.org/licenses/by-sa/3.0/)
 *	@author		s0600204
 *	@thanks		the authors of plugin:include - bits of this are inspired by/copied from there. Thanks!
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/**
 *	All DokuWiki plugins to extend the parser/rendering mechanism 
 *	need to inherit from this class 
 */ 
class syntax_plugin_concatTable extends DokuWiki_Syntax_Plugin {
    
	function getType () { return 'substition'; }
	function getSort () { return 301; }
	function getPType () { return "block"; }
	
	function connectTo ($mode) {
		$this->Lexer->addSpecialPattern("{{concattable>.+?}}", $mode, 'plugin_concatTable');
	}
	
	/**
	 *	Handle Function
	 */
	function handle ($match, $state, $pos, &$handler) {

		$match = substr($match, 14, -2);  // strip markup
		$flags = explode('&', $match);  
		$match = array_shift($flags);  // seperate links from flags
		$match = explode('|', $match);  // seperate links from each other
		
		for ($m=0; $m<count($match); $m++) {
			$tmp = array();  // for each link, break into page and section
			list($tmp['page'], $tmp['sect']) = explode('#', $match[$m]);
			$match[$m] = $tmp;
			
			if(isset($match[$m]['sect'])) {
				$check = null;
				$match[$m]['sect'] = sectionID($match[$m]['sect'], $check);
			}
		}
		
		return array($match, $flags);
	}
	
	/**
	 *	Render Function
	 */
	function render ($format, &$renderer, $data) {
		global $ID;
		list ($pages, $flags) = $data;
		
		$flags = $this->_setflags($flags);
		
		$newTable = array(array(
				'table_open',
				array(0, 0, 0),
				0
			));
		
		foreach ($pages as $page) {
			resolve_pageid(getNS($ID), $page['page'], $exists);  // resolve shortcuts and clean ID
			$oldTable = $this->_processPage($page['page'], $page['sect']);
			if ($rows !== false) {
				if ($newTable[0][1][0] < $oldTable[1][0]) {  // set col count
					$newTable[0][1][0] = $oldTable[1][0];
				}
				$newTable = array_merge($newTable, $oldTable[0]);
				$newTable[0][1][1] += $oldTable[1][1];  // update row count
			}
		}
		$newTable[] = array('table_close', array(0), 0);
		
		if (count($newTable) < 2) {
			return false;
		} else {
			if (count($flags) > 0) {
				$newTable = $this->_processTable($newTable, $flags);
			}
			$renderer->nest($newTable);
			return true;
		}
	}
	
	
	/**
	 *	Aquires data from table and returns it in an array
	 *	
	 *	@author	s0600204
	 *	@params	string	$page	page to aquire table from
	 *	@params	string	$sect	section on that page
	 *	@return	array	[0] = table data, [1] = table dimensions (cols, rows)
	 *	@return	boolean	returns false if page does not exist
	 */
	function _processPage ($page, $sect) {
		$keep = 0;
		$dimens = array();
		$ins_new = array();
		
		if (page_exists($page)) {
			global $ID;
		//	$backupID = $ID;
		//	$ID = $page; // Change the global $ID as otherwise plugins like the discussion plugin will save data for the wrong page
			$ins = p_cached_instructions(wikiFN($page));
		//	$ID = $backupID;
		} else {
			return false;
		}
		
		$cnt = count($ins);
		for ($i=0; $i<$cnt; $i++) {
			switch ($ins[$i][0]) {
				case "header":
					if ($sect === $ins[$i][1][0]) {
						$keep = 1;
					}
					break;
					
				case 'table_open':
					if ($keep == 1) {
						$dimens = $ins[$i][1];
					}
					break;
					
				case 'table_close':
					$keep = 0;
					break;
					
				case 'section_open':  // required to stop it being written
					break;
					
				default:
					if ($keep == 1) {
						$ins_new[] = $ins[$i];
					}
					break;
			}
		}
		return array($ins_new, $dimens);
	}
	
	/**
	 *	Alters formatting of the final table, before it gets integrated into the page
	 *	
	 *	@author	s0600204
	 *	@params	string	$table	table to be modified
	 *	@params	array	$flags	flags indicating what to do
	 *	@return	array	formatted table
	 */
	function _processTable ($table, $flags) {
		$row = 0;
		$coll = 0;
		
		// process table
		$cnt = count($table);
		for ($i=0; $i<$cnt; $i++) {
			switch ($table[$i][0]) {
				case 'tablerow_open':
					$col = 0;
					$row++;
					break;
					
				case 'tablecell_open':
					$col++;
					if ($flags['code'] == 1) {
						switch ($col) {
							case 1:
								$table[$i][1][1] = 'right';
								break;
							case 3:
								$table[$i][1][1] = 'left';
								break;
							case 2:
								$table[$i][0] = 'tableheader_open';
							default:
								$table[$i][1][1] = 'center';
								break;
						}
					}
					break;
					
				case 'tablecell_close':
					if ($col == 2 && $flags['code'] == 1) {
						$table[$i][0] = 'tableheader_close';
					}
					break;
					
				case 'tableheader_open':
					$col++;
					if ($flags['th-rem'] == 1) {
						$table[$i][0] = 'tablecell_open';
					}
					if ($flags['code'] == 1) {
						if ($col != 2) {
							$table[$i][0] = 'tablecell_open';
						}
						switch ($col) {
							case 1:
								$table[$i][1][1] = 'right';
								break;
							case 3:
								$table[$i][1][1] = 'left';
								break;
							default:
								$table[$i][1][1] = 'center';
								break;
						}
					}
					break;
					
				case 'tableheader_close':
					if ($flags['th-rem'] == 1) {
						$table[$i][0] = 'tablecell_close';
					}
					if ($flags['code'] == 1 && $col != 2) {
						$table[$i][0] = 'tablecell_close';
					}
					break;
					
				default:
					break;
			}
		}
		
		return $table;
	}
	
	/**
	 *	Sets flags
	 */
	function _setflags($setflags) {
		
		$flags = array(
				'code' => 0,
				'th-rem' => 0
			);
		
		foreach ($setflags as $flag) {
			switch ($flag) {
				case 'code':
					$flags['code'] = 1;
					break;
				case 'noheadings':
					$flags['th-rem'] = 1;
					break;
			}
		}
		
		return $flags;
	}
}