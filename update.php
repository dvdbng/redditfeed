<?php
// Github webhook

$output = shell_exec("cd /var/www/hoyga/redditfeed/; git pull origin master 2>&1;");
echo "<pre>$output</pre>";
die("done ".mktime());

?>
