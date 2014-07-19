<?php

// Dreamhost API interface
//
// Typical workflow:
//
// 1. Get list file
//   php dh-api.php --list > list.txt
// 
// 2. Edit `add.txt` and appropriate entries as required
//   vim add.txt
//
// 3. Update `list.txt` with new entries
//   php dh-api.php --add-to-list >> list.txt
//
// 4. Sync
//   php dh-api.php --sync
//

class DreamApi {
	// don't use this, save your api key in the file ".api_key"
	private $api_key = "";

	// dreamhost API URL
	private $api_url = "https://api.dreamhost.com/";

	// set defaujlt exec commands for wget and curl
	private $wget = "wget";
	private $curl = "/usr/bin/curl";

	private $uuid;
	private $commands = array();
	private $debug_level;
	private $dry_run = false;

	public $show_exec;
	
	private $keys = array('action','action_value','address','contains','filter','filter_on','stop','rank');
	
	function set_api_key($key) {
		$this->api_key = trim($key);
	}
	
	function set_dry_run($value=true) {
		$this->dry_run = $value;
	}
	
	function is_dry_run() {
		return $this->dry_run;
	}
	
	private function query($data) {
		$output = array(); // store output from dreamhost server here

		// data to use in query string to API server
		$data = array_merge(array("key"=>$this->api_key,"format"=>"php"), $data);

		// full url to GET
		$url = "https://api.dreamhost.com/?".http_build_query($data);
		
		// Note:
		// MAC curl 7.30.0 works
		// Debian Linux 7: curl 7.26.0 does not work, hangs, not sure why
		//                 use wget instaed
		// Windows: Never thoroughly tested, but last time it didn't
		//          work consistently
		//if (strstr(php_uname(),"Darwin")) {
		if (strstr(php_uname(),"Darwin")) {
			// MAC OS X - use curl
			$exec_str = $this->curl . " -s '".$url."'";
		} else if (strstr(php_uname(),"Linux")) {
			// Linux - use wget
			$exec_str = $this->wget . " -qO- --no-check-certificate '".$url."'";
		} else {
			// Other OS - use wget
			$exec_str = $this->wget . " -qO- --no-check-certificate '".$url."'";
		}

		if ($this->show_exec) {
			// show use the exec string, used for debugging the script
			fwrite(STDERR, "EXEC: ".$exec_str."\n");
		}

		// execute the command string
		exec($exec_str, $output);
		
		// count number of lines of response from system command e.g curl, wget etc
		if (count($output)==0) {
			fwrite(STDERR,"API server did not respond.\n");
			exit();
		}

		// grab the php formatted server response
		$result = unserialize($output[0]);
		if (!$result) {
			// response is not php format
			fwrite(STDERR,"Error: API server didn't return a PHP serialized response.\n");
			exit();
		}

		if (!isset($result['result'])) {
			// invalid result from server
			fwrite(STDERR,"Error: API server didn't return a result field.\n");
			exit(); // fatal error, should quit right away
		}
		if ($result['result'] != "success") {
			if ($result['result'] == "error") {
				fwrite(STDERR,"Error occured.\n");
			}
			if (isset($result['data'])) {
				fwrite(STDERR,"Error data: ".$result['data']."\n");
			}
			if (isset($result['reason'])) {
				fwrite(STDERR,"Error reason: ".$result['reason']."\n");
			}
			exit();
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
			fwrite(STDERR,"Error: mail-list_filters command failed (result != 'success')\n");
			exit();
		}
		
		sort($result['data']);
		$this->email_filters = $result['data'];
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
					fwrite(STDERR,"Error: Invalid function parameter to mail_add_filter(). Key not set.\n");
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
					fwrite(STDERR,"Error: mail-add_filter command did not return a result\n");
					exit();
				}
				if ($result['result'] != 'success') {
					fwrite(STDERR,"Error: mail-add_filter result was not 'success'\n");
					exit();
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
				fwrite(STDERR,"Warning: Missing rank key\n");
				// ok to continue, it should still delete file, without a rank key, i think...
				//exit();
			}
			$new_data = array_merge(array('cmd'=>'mail-remove_filter'), $new_data);
			$result = $this->query($new_data);
			if (!array_key_exists('result', $result)) {
				fwrite(STDERR,"Error: mail-remove_filter command did not return a result\n");
				exit();
			}	
			if ($result['result'] != 'success') {
				fwrite(STDERR,"Error: mail-remove_filter result was not 'success'\n");
				exit();
			}
			return true;
		}		
	}
	
	function mail_sync_filters_add() {
		if (!isset($this->email_filters) || !isset($this->new_filters)) {
			return false;
		}
		foreach ($this->new_filters as $f) {
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
			if ( !$this->mail_matching_filter($filter_existing, $this->new_filters, $this->keys) ) {
				// no matches, we can delete
				$this->mail_delete_filter($filter_existing);
			}
		}		
	}
	
	function mail_matching_filter($needle, $haystack_array, $keys) {
		$keys = array_diff($keys, array("rank","account_id")); // don't compare "rank" or "account_id", ignore this key
		
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
			fwrite(STDERR, "Could not open \"".$filename."\"\n");
			return false;
		}
		while( $line = fgets($file) ) {
			if (trim($line) == "") {
				continue;
			}
			$data = array();
			parse_str(str_replace("|","&",rtrim($line)), $data);
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

	function add_to_list() {

		// look for 'add.txt', which contains email filters to add into list.txt
		if (!is_file('add.txt')) {
			fwrite(STDERR, "Error: Could not find 'add.txt'\n");
			exit();
		}

		$file = @fopen('add.txt','r');
		if (!$file) {
			fwrite(STDERR, "Error: Could not open 'add.txt'\n");
			exit();
		}

		$email = ""; // store "last email address found"

		while( $line = fgets($file) ) {
			$line = trim($line);

			if ( preg_match("/^#/",$line) ) {
				// comment line
				continue;
			}

			if ( preg_match("/^$/", $line) ) {
				// empty line
				continue;
			}

			// look for email address header
			$matches = array();
			if ( preg_match("/^==(.*)==$/", $line, $matches) ) {
				// grab email address from header line
				$email = trim($matches[1]);
				continue;
			}

			// desired format:
			// account_id=467273|action=move|action_value=spam-filter|address=andy@andygock.com.au|contains=yes|filter=@advertisewithseo.com|filter_on=from|rank=1|stop=yes

			if ($email != "") {
				// not a blank line in add.txt

				if ( preg_match("/^([a-z]+):(.*)/", $line, $matches)) {
					// filter_on identifier found

					$filter_on = $matches[1];
					if (!in_array($filter_on, array("from","subject","to","cc","body","reply-to","headers"))) {
						// not a valid 'filter on' key
						fwrite(STDERR, "Warning: Invalid key '".$filter_on.":' in 'add.txt', ignoring line.\n");
						continue;
					}

					$filter = trim($matches[2]);

				} else {
					// assume "from:"
					$filter_on = "from";

					// line must contain a email address (or part of one) to filter, use rank=1
					// not sure if we need account_id value, i've removed it for now

					$filter = $line;
					
				}

				echo "action=move|action_value=spam-filter|address=".$email."|contains=yes|filter=".$filter."|filter_on=".$filter_on."|rank=1|stop=yes\n";

			}

		}

	}
	
}

////////////////////////////////
// SCRIPT STARTS HERE

// command line options
$opts = getopt("li:sh",array("list","input:","sync","dry-run","dry","exec","help","add-to-list"));

//var_dump($opts);

if (count($opts) == 0) {
	// if no options given, we want to display help message
	$opts['help'] = 1;
}

if (array_key_exists("help", $opts) || array_key_exists("h", $opts)) {
	// display help message
	print "Email filter tool using Dreamhost API (by Andy Gock)\n\n";
	print "Usage:\n";
	print "\tdh-api.php [OPTIONS] COMMAND\n";

	print "\nCommands:\n";
	print "\t-s, --sync    Synchronise 'list.txt' with server filters\n";
	print "\t-l, --list    List all server filters\n";
	print "\t--add-to-list Process 'add.txt' and output\n";
	print "\t-h, --help    This help message\n";

	print "\nOptions:\n";
	print "\t-i FILE, --input=FILE\n";
	print "\t                 User specified lsit file\n";
	print "\t--dry, --dry-run Perform a dummy run\n";
	print "\t--exec           Display executed command string to stderr\n";
	print "\t                 e.g wget, curl etc\n";
	
	exit();
}

$dream = new DreamApi();

// Look for API key
if ($file = fopen(".api_key","r")) {
	// read api key from this file
	$key = fgets($file,128);
	if (!$key) {
		fwrite(STDERR, "Could not read .api_key\n");
		exit();		
	}		
	$dream->set_api_key($key);
} else {
	// could not find api key
	fwrite(STDERR, "Could not open .api_key\n");
	exit();
}

if (array_key_exists("exec", $opts)) {
	// display executed command to STDERR, for debugging
	$dream->show_exec = true;
}

if (array_key_exists("add-to-list", $opts)) {
	// look for add.txt, and make a formatted list of commands, which can be appended to list.txt
	// and then synchromised to the server
	// this add.txt file is easier to write and is useful for adding new entries
	$dream->add_to_list();
	exit();
}
		
if (array_key_exists("list", $opts) || array_key_exists("l", $opts)) {
	// list all server email filters
	// example usage: dh-api.php --list > list.txt
	fwrite(STDERR, "Listing existing mail filters from remote server...\n");
	$dream->mail_list_filters();
	$dream->mail_print_filters();
	fwrite(STDERR, "Listed ".count($dream->email_filters)." lines.\n");
	exit();
}

if (array_key_exists("dry-run", $opts) || array_key_exists("dry", $opts)) {
	// perform dummy run of the sync command, but doesn't actually perform any network operations
	$dream->set_dry_run(true);
}

if (array_key_exists("s", $opts) || array_key_exists("sync", $opts)) {
	// perform actual sync, any differences between server filterts and those in list.txt is synced
	// this includes any additions or deletions required to bring these two into sync
	// if -i or --input supplied, then user can specify a filename instead of list.txt
	if (array_key_exists("i", $opts)) {
		$filename = $opts['i'];
	} else if (array_key_exists("input", $opts)) {
		$filename = $opts['input'];
	} else {
		// look for 'list.txt' as defaulty
		if ($fp = fopen("list.txt","r")) {
			$filename = "list.txt";
		} else {
			fwrite(STDERR, "No input filter list was supplied and could not find 'list.txt'. Try using --input=FILE option.\n");
			exit();
		}
	}
	
	if ( !$dream->mail_read_filter_file($filename) ) {
		// could not read file, error message displayed inside the function
		fwrite(STDERR, "Error: Could not read filter file '".$filename."'\n");
		exit();
	}
	//$dream->mail_print_filter_file();
	
	// sync with both ADD and DELETE
	if ($dream->is_dry_run()) {
		fwrite(STDERR, "Dry run...\n");
	}
	fwrite(STDERR, "Reading existing mail filters from remote server...\n");
	$dream->mail_list_filters();
	fwrite(STDERR, "Read ".count($dream->email_filters)." entries.\n");
	fwrite(STDERR, "Performing sync...\n");
	$dream->mail_sync_filters_delete();
	$dream->mail_sync_filters_add();
	fwrite(STDERR, "Completed.\n");

	// all done
} // sync operation

?>
