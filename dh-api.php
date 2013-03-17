<?php

// CLI PHP script to batch add email filters via Dreamhost API
// Run with --help switch to see command line options

define("WGET_BIN","c:/cygwin/bin/wget.exe");
define("SPAM_FOLDER","spam-filter");
define("DEFAULT_API_KEY",".api_key");

$opt = getopt("hla",array("add","email:","help","list","list-file:","account:","action:","value:","bare","filter:","clear","key:"));
//var_dump($opt);

// Obtain api key
// If param is 16 char string, then assume it *is* the key.
// Otherwise treat it as a key file.
// If this switch is not supplied, read default key file
if (isset($opt['key'])) {
	if (strlen($opt['key']) == 16) {
		$api = $opt['key'];
	} else {
	
		$api_file = file_get_contents($opt['key']);
		if ($api_file === false) {
			echo "Count not read: '".$opt['key']."'\n";
			exit;
		}
		$api = trim($api_file);
	}
} else {
	$api_file = file_get_contents(DEFAULT_API_KEY);
	if ($api_file === false) {
		echo "Count not read: '".$opt['key']."'\n";
		exit;
	}	
	$api = trim($api_file);
}

if (isset($opt['list']) || isset($opt['l'])) {
	echo "Retreiving current filters...\n";
	list_mail_filters();
	exit;
	
} else if (isset($opt['add']) || isset($opt['a'])) {

	if (!isset($opt['account'])) {
		echo "Please select account using the --account option.\n";
		exit;
	}	

	if (isset($opt['list-file'])) {
		echo "Adding filters from list file.\n";
		$curr = get_filters();
		
		$myemail = trim($opt['account']);
		$input_file = trim($opt['list-file']);
		
		$addresses = @file($input_file);
		if (!$addresses) {
			echo "Could not open list file '".$input_file."'\n";
			exit;
		}
		
		//echo "Read " . count($addresses) . " lines from list file.\n";
		
		foreach ($addresses as $addr) {
			$addr = trim($addr);
			
			if (strpos($addr,"#") === 0) {
				// comment line - ignore
				continue;
			}
			
			// we should check if filter already exists, before adding it
			// do later!
			
			if (! check_current($curr, $myemail, $addr)) {
				
				if (! add_to_filter($myemail, $addr) ) {
					echo "Script terminated.\n";
					exit;
				}
				
			} else {
				echo $addr.": skipping... (already exists)\n";
			}
		}
		
		exit;	
	}
	
	if (isset($opt['filter'])) {
		//echo "Adding: " . trim($opt['filter']) . "\n";
		add_to_filter($opt['account'], trim($opt['filter']));
		exit;	
	}

	echo "Incorrect syntax";
	exit;

} else if (isset($opt['clear'])) {
	// clear all entries for a email account
	echo "--clear is not implemented\n";
	exit;
	
} else  {
	print_help();
	exit;
}

exit;

function print_help() {
	echo "CLI Syntax: \n";
	echo "  php " . basename(__FILE__) . " [OPTIONS]\n";
	
	echo "Example:\n";
	echo "  php " . basename(__FILE__) . " --help\n";
	echo "  php " . basename(__FILE__) . " --list\n";
	echo "  php " . basename(__FILE__) . " --add --account=bad@email.com --filter=bademail.com\n";
	echo "  php " . basename(__FILE__) . " --add --account=bad@email.com --list-file=listfile.txt\n";
	
	echo "\nOptions:\n";
	echo "  --api=APIKEY  Specify API key (16 chars)\n";
	echo "  --api=KEYFILE File with API key inside. Filename not to be 16 chars)\n";
	exit;
}

function add_to_filter($myaddr, $address) {
	global $opt, $api;
	$result = array();
	if (isset($opt['action']) && isset($opt['value'])) {
		$url = "format=json&key=".$api."&cmd=mail-add_filter&rank=0&address=".$myaddr."&filter_on=from&action=".urlencode($opt['action'])."&action_value=".urlencode($opt['value'])."&filter=".$address;	
	} else {
		$url = "format=json&key=".$api."&cmd=mail-add_filter&rank=0&address=".$myaddr."&filter_on=from&action=move&action_value=".SPAM_FOLDER."&filter=".$address;
	}
	exec(WGET_BIN . " -qO- --no-check-certificate \"https://api.dreamhost.com/?".$url."\"", $result);
	$result = json_decode($result[0]);

	if ($result->result == "error") {
		echo "Error: " . $result->data . "\n";
		exit;
	}
	
	if ($result->result == "success") {
		echo "Added: $address\n";
		return true;
	} else {
		echo "Error: " . $result->data . "\n";
		return false;
	}
	
}

function list_mail_filters() {
	global $opt, $api;
	
	$result = array();
	$url = "format=json&key=".$api."&cmd=mail-list_filters";
	exec(WGET_BIN . " -qO- --no-check-certificate \"https://api.dreamhost.com/?".$url."\"", $result);
	$result = json_decode($result[0]);
	
	//var_dump($result);
	
	if ($result->result == "error") {
		echo "Error: " . $result->data . "\n";
		exit;
	}
	
	foreach ($result->data as $data) {
		if (isset($opt['account'])) {
			if ($opt['account'] != $data->address) {
				continue;
			}
		}
		if (isset($opt['bare'])) {
			echo $data->filter . "\n";	
		} else {
			echo "to:" . $data->address . ' ' . $data->filter_on . ":" . $data->filter . "\n";	
		}
	}
}

function get_filters() {
	global $api;
	$result = array();
	$url = "format=json&key=".$api."&cmd=mail-list_filters";
	exec(WGET_BIN . " -qO- --no-check-certificate \"https://api.dreamhost.com/?".$url."\"", $result);
	
	$result = json_decode($result[0]);
	
	if ($result->result == "error") {
		echo "Error: " . $result->data . "\n";
		exit;
	}
	
	return $result;
}

function check_current($data, $account, $address, $action="", $value="") {

	foreach ($data->data as $filter) {
		if ( ($filter->address === $account) && ($filter->filter === $address) && ($filter->filter_on == "from") && ($filter->action == "move") ) {
			return true;
		}
	}
	return false;
}

?>