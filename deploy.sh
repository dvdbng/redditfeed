ssh root@ovh '(cd /var/www/hoyga/redditfeed; git pull)'
scp config.php root@ovh:/var/www/hoyga/redditfeed
