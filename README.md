## dh-api

A Dreamhost API PHP script used for bulk editing of email filters.

There is no real convenient way of adding email filters by the web panel interface, as it is pretty much limited to one addition at a time via a web form. This was written to solve this problem. This is essential to manage spam filters which may have lots of entries.

### How to use:

* Create a file `.api_key` with your API key inside.
* In Dreamhost panel, you must have email filters added to your API key access rules

## Examples of usage

Run to see a current list of email filters:

	php dh-api.php --list

Save the email filters to `list.txt`:
	
	php dh-api.php --list > list.txt

Edit `list.txt` and add or delete filters as required.

Sync the file to the server, first, do a dry run to see what changes would be made

	php dh-api.php --sync --dry

Actually perform the sync:

	php dh-api.php --sync

See help, and all options:

	php dh-api.php --help

## Quick addition of entries to `list.txt`

Quick add of email filters (must have `add.txt`):

	php dh-api.php --list > list.txt
	php dh-api.php --add-to-list >> list.txt
	php dh-api.php --sync --dry
	php dh-api.php --sync

Format of `add.txt`:

	# Additions
	# These add to filter.txt, as email filter in the FROM field

	==andy@andygock.com.au==
	organichosting
	groomingtechnologies.com

