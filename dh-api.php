<?php

class DreamApi {
	private $api_key = "";
	private $api_url = "https://api.dreamhost.com/";
	private $uuid;
	private $wget = "c:/cygwin/bin/wget.exe";
	
	private $commands = array();
	
	private $debug_level;
	
	private $dry_run = false;
	var $show_exec;
	
	private $keys = array('action','action_value','address','contains','filter','filter_on','stop','rank');
	
	function set_dry_run($value=true) {
		$this->dry_run = $value;
	}
	
	function is_dry_run() {
		return $this->dry_run;
	}
	
	private function query($data) {
		$output = array();
		$data = array_merge(array("key"=>$this->api_key,"format"=>"php"), $data);
		$exec_str = $this->wget . " -qO- --no-check-certificate \"https://api.dreamhost.com/?" . http_build_query($data);
		if ($this->show_exec) {
			fwrite(STDERR, "EXEC: ".$exec_str."\n");
		}
		exec($exec_str, $output);
		$result = unserialize($output[0]);
		if ($result['result'] != "success") {
			if (isset($result['data'])) {
				fwrite(STDERR,"Error data: ".$result['data']."\n");
			}
			if (isset($result['reason'])) {
				fwrite(STDERR,"Error reason ".$result['reason']."\n");
			}
		}
		return $result;
	}
	
	function get_accessible_commands() {
		$result = $this->query(array("cmd"=>"api-list_accessible_cmds"));
		
		if ($result['result'] == "success") {
			foreach ($result['data'] as $cmd) {
				foreach ($cmd as $key=>$val) {
					//print $key.'='.$val." ";
					if ($key == "cmd") {
						$this->commands[] = $val;
					}
				}
			}
		}
	}
	
	function mail_list_filters() {
		$result = $this->query(array('cmd'=>'mail-list_filters'));
		
		if ($result['result'] != "success") {
			fwrite(STDERR, $result['data']."\n");
			//var_dump($result);
			exit();
		}
		
		//var_dump($result['data']);
		sort($result['data']);
		$this->email_filters = $result['data'];
		//$this->mail_print_filters();
		return true;
	}
	
	function mail_print_filters() {
		if (!isset($this->email_filters)) {
			return false;
		}
		foreach ($this->email_filters as $d) {
			print urldecode(http_build_query($d,"","|"))."\n";
		}		
		return true;
	}
	
	// add filter if filter does not already exist
	function mail_add_filter($data) {
		// e.g
		// check whether filter already exists in $this->email_filters
		
		if (!isset($this->email_filters)) {
			return false;
		}
		
		$filter_was_matched = false;

		// check whether all keys match
		foreach ($this->email_filters as $d) {
			// go through each of existing filters, and see if any
			// matches $data
			$matches = 0;
			foreach ($this->keys as $check_key) {
				if ($check_key == "rank") { // ignore "rank" when comparing
					continue;
				}
				if (!array_key_exists($check_key,$data) || !array_key_exists($check_key,$d)) {
					//var_dump($data);
					//var_dump($d);
					throw new Exception("Invalid function parameter to mail_add_filter(). Key not set.");
					exit();
				}
				if ($data[$check_key] == $d[$check_key]) {
					$matches++;
				}
			}
			if ($matches == (count($this->keys)-1)) { // one less than count, as we ignore "rank" key
				// found a match
				$filter_was_matched = true;
				break;
			}
		}
		
		if ($filter_was_matched) {
			// filter already exists
		} else {
			// filter does not exist on remote server, add it (ignore account_id and rank key)
			print "ADD ".$this->mail_formatted_filter($data)."\n";
			if (!$this->dry_run) {
				foreach ($this->keys as $k) {
					$new_data[$k] = $data[$k];
				}
				$new_data = array_merge(array('cmd'=>'mail-add_filter'), $new_data);	
				$result = $this->query($new_data);
				if (!array_key_exists('result', $result)) {
					var_dump($result);
					throw new Exception("mail-add_filter command returned invalid result");
					exit();
				}
				if ($result['result'] != 'success') {
					if (isset($result['data'])) {
						print "ERROR OCCURED: ".$result['data']."\n";
					} else {
						var_dump($result);
						throw new Exception("Invalid response from server");
						exit();
					}
					
					return false;
				} else {
					return true;
				}
			}
		}
		
		return true;
		
	}
	
	function mail_delete_filter($data) {
		print "DELETE ".$this->mail_formatted_filter($data)."\n";		
		if (!$this->dry_run) {
			foreach ($this->keys as $k) {
				$new_data[$k] = $data[$k];
			}
			if (!array_key_exists("rank", $new_data) || !array_key_exists("rank", $data)) {
				throw new Exception("Missing rank key.");
			}
			//$new_data["rank"] = $data["rank"]; // need rank key for delete command
			$new_data = array_merge(array('cmd'=>'mail-remove_filter'), $new_data);
			$result = $this->query($new_data);
			if (!array_key_exists('result', $result)) {
				throw new Exception("mail-add_filter command returned invalid result");
			}			
			if ($result['result'] != 'success') {
				if (isset($result['data'])) {
					print "ERROR OCCURED: ".$result['data']."\n";
				} else {
					var_dump($result);
					throw new Exception("Invalid response from server");
					exit();
				}
				return false;
			} else {
				return true;
			}
		}		
	}
	
	function mail_sync_filters_add() {
		if (!isset($this->email_filters) || !isset($this->new_filters)) {
			return false;
		}
		foreach ($this->new_filters as $f) {
			//print "About to add new filter:\n";
			//var_dump($f);
			$this->mail_add_filter($f);
		}
		return true;
	}
	
	// delete filters that are in $this->email_filters, but not in $this->new_filters
	// (a bit like array_merge)
	function mail_sync_filters_delete() {
		if (!isset($this->email_filters) || !isset($this->new_filters)) {
			return false;
		}
		foreach ($this->email_filters as $filter_existing) {
			//print "About to add new filter:\n";
			//var_dump($f);
			if ( !$this->mail_matching_filter($filter_existing, $this->new_filters, $this->keys) ) {
				// no matches, we can delete
				$this->mail_delete_filter($filter_existing);
					
			}
		}		
	}
	
	function mail_matching_filter($needle, $haystack_array, $keys) {
		$keys = array_diff($keys, array("rank")); // don't compare "rank", ignore this key
		
		foreach ($haystack_array as $a2) {
			$matches = 0;
			foreach ($keys as $k) {
				if ($needle[$k] == $a2[$k]) {
					$matches++;
				}
			}
			if ($matches == count($keys)) {
				// we have a match
				return true;
			}
		}
		// no match found
		return false;
	}

	function mail_formatted_filter($filter) {
		return urldecode(http_build_query($filter,"","|"));
	}
	
	// read filter file into $this->new_filters
	function mail_read_filter_file($filename) {
		$this->new_filters = array();
		$file = @fopen($filename,'r');
		if (!$file) {
			print "Could not open \"".$filename."\"\n";
			return false;
		}
		while( $line = fgets($file) ) {
			if (trim($line) == "") {
				continue;
			}
			
			$data = array();
			//print $line;
			parse_str(str_replace("|","&",rtrim($line)), $data);
			//var_dump($data);
			$this->new_filters[] = $data;
		}
		return true;
	}
	
	function mail_print_filter_file() {
		if (!isset($this->new_filters)) {
			return false;
		}
		if (!is_array($this->new_filters)) {
			return false;
		}
		
		foreach ($this->new_filters as $d) {
			print $this->mail_formatted_filter($d)."\n";
		}
		return true;
	}
	
	/**
	 * Print help screen for command line parameters
	 * @return type
	 */
	function print_help() {
		
		return;
	}
	
}

$opts = getopt("li:sh",array("list","input:","sync","dry-run","dry","exec","help"));
//var_dump($opts);

$dream = new DreamApi();

if (array_key_exists("help", $opts) || array_key_exists("h", $opts)) {
	print "dh-api.php [OPTIONS] COMMAND\n";
	print "\nCommands:\n";
	print "\t-s, --sync\n";
	print "\t-l, --list\n";
	print "\t-h, --help\n";
	print "\nOptions:\n";
	print "\t-i, --input\n";
	print "\t--dry, --dry-run\n";
	print "\t--exec\n";
	exit();
}

if (array_key_exists("exec", $opts)) {
	$dream->show_exec = true;
}
		
if (array_key_exists("list", $opts) || array_key_exists("l", $opts)) {
	fwrite(STDERR, "Listing existing mail filters from remote server...\n");
	$dream->mail_list_filters();
	$dream->mail_print_filters();
	fwrite(STDERR, "Listed ".count($dream->email_filters)." lines.\n");
	exit;
}

if (array_key_exists("dry-run", $opts) || array_key_exists("dry", $opts)) {
	$dream->set_dry_run(true);
}

if (array_key_exists("s", $opts) || array_key_exists("sync", $opts)) {
	if (array_key_exists("i", $opts)) {
		$filename = $opts['i'];
	}
	if (array_key_exists("input", $opts)) {
		$filename = $opts['input'];
	}
	if (!isset($filename)) {
		print "No input filter list was supplied. Use the --input=FILE option.\n";
		exit;
	}
	
	if ( !$dream->mail_read_filter_file($filename) ) {
		// could not read file, error message displayed inside the function
		exit;
	}
	//$dream->mail_print_filter_file();
	
	// sync with both ADD and DELETE
	if ($dream->is_dry_run()) {
		print "Dry run...\n";
	}
	fwrite(STDERR, "Reading existing mail filters from remote server...\n");
	$dream->mail_list_filters();
	fwrite(STDERR, "Read ".count($dream->email_filters)." entries.\n");
	fwrite(STDERR, "Performing sync...\n");
	$dream->mail_sync_filters_add();
	$dream->mail_sync_filters_delete();
	fwrite(STDERR, "Completed.\n");
}

?>