<?php
// Github webhook

$output = shell_exec("cd /var/www/hoyga/redditfeed/; git pull origin master;");
echo "<pre>$output</pre>";
die("done ".mktime());

?>
