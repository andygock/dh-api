A Dreamhost API PHP script used for bulk editing of email filters.

How to use:

Create a file `.api_key` with your API key inside.

Run to see a current list of email filters:

	php dh-api.php --list

Save the email filters to "list.txt"
	
	php dh-api.php --list > list.txt

Edit list.txt and add or delete filters as required

Sync the file to the server, first, do a dry run to see what changes would be made

	php dh-api.php --sync --dry

Actually perform the sync

	php dh-api.php --sync

See help, and all options:

	php dh-api.php --help

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

